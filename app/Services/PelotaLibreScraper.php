<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fragile HTML scraper for https://pelotaalibre.st/agenda.php.
 *
 * The upstream is an unauthenticated static page (no API) that lists the day's
 * matches inside <li> blocks. Each match is a group of consecutive <li>s: the
 * first is the match itself (with league, teams, time, channel), and the
 * remaining <li>s in the same group are alternative stream options (OP 2, OP 3…)
 * that link to /eventos.html?r=<base64(encoded stream URL)>.
 *
 * The page changes layout without notice — when parsing fails, the scraper
 * returns an empty list and logs a warning. Cache TTL is short (60s) so
 * transient failures self-heal quickly.
 */
class PelotaLibreScraper
{
    private const CACHE_TTL = 60;

    private const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    private function agendaUrl(): string
    {
        return (string) config('services.pelotalibre.agenda_url');
    }

    /**
     * @return Collection<int, array{league: string|null, home: string, away: string, time: string|null, channel: string|null, quality: string|null, options: array<int, array{source: string, quality: string|null, url: string, embed: string}>}>
     */
    public function getAgenda(): Collection
    {
        $cached = Cache::get('pelota:agenda');

        if (is_array($cached)) {
            return collect($cached);
        }

        try {
            $html = Http::withHeaders(['User-Agent' => self::USER_AGENT])
                ->retry(2, 800)
                ->timeout(15)
                ->get($this->agendaUrl())
                ->body();
        } catch (ConnectionException|Throwable $e) {
            Log::warning('pelota agenda fetch failed', ['error' => $e->getMessage()]);

            return collect();
        }

        $events = $this->parseAgenda($html);
        Cache::put('pelota:agenda', $events->all(), self::CACHE_TTL);

        return $events;
    }

    /**
     * Parse the agenda HTML.
     *
     * @return Collection<int, array{league: string|null, home: string, away: string, time: string|null, channel: string|null, quality: string|null, options: array<int, array{source: string, quality: string|null, url: string, embed: string}>}>
     */
    private function parseAgenda(string $html): Collection
    {
        if (! preg_match_all('/<li[^>]*>(.*?)<\/li>/si', $html, $matches)) {
            return collect();
        }

        $events = collect();
        $current = null;

        foreach ($matches[1] as $li) {
            $href = $this->extractAttr($li, 'href');
            $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($li), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

            // Option row: has a base64-encoded event URL, no league pattern
            if ($href && str_starts_with($href, '/eventos.html?r=')) {
                if ($current !== null) {
                    $current['options'][] = [
                        'source' => $this->optionSource($text),
                        'quality' => $this->optionQuality($text),
                        'url' => $href,
                        'embed' => $this->decodeEmbed($href),
                    ];
                }

                continue;
            }

            // Match row: starts with "<league>: <home> vs <away> <time> <channel> ..."
            if ($current !== null) {
                $events->push($current);
                $current = null;
            }

            $parsed = $this->parseMatchText($text);
            if ($parsed !== null) {
                $current = $parsed;
                $current['options'] = [];
            }
        }

        if ($current !== null) {
            $events->push($current);
        }

        return $events->values();
    }

    /**
     * Parse the human text of a match <li>.
     *
     * Sample: "Liga BetPlay: Llaneros vs Fortaleza CEIF 22:10 Win Sports+ Calidad 720p"
     *
     * @return array{league: string|null, home: string, away: string, time: string|null, channel: string|null, quality: string|null, options: array<int, mixed>}|null
     */
    private function parseMatchText(string $text): ?array
    {
        if ($text === '' || ! preg_match('/\bvs\.?\b/i', $text)) {
            return null;
        }

        $league = null;
        if (preg_match('/^([^:]+):\s*(.+)$/', $text, $m)) {
            $league = trim($m[1]);
            $text = trim($m[2]);
        }

        $parts = preg_split('/\s+vs\.?\s+/i', $text, 2);
        if (count($parts) !== 2) {
            return null;
        }

        // Sample after split: "Fortaleza CEIF 22:10 Win Sports+ Calidad 720p"
        // We pull out time, then quality, then split what's left into [away, channel].
        $after = trim($parts[1]);

        $time = null;
        if (preg_match('/\b(\d{1,2}:\d{2})\b/', $after, $m)) {
            $time = date('H:i', strtotime("{$m[1]} -4 hours"));
            $after = trim(preg_replace('/\b\d{1,2}:\d{2}\b/', '', $after, 1));
        }

        $quality = null;
        if (preg_match('/\b(\d{3,4}p)\b/i', $after, $m)) {
            $quality = $m[1];
            $after = trim(preg_replace('/\s*\b\d{3,4}p\b\s*/i', '', $after));
        }

        // $after now looks like: "Fortaleza CEIF Win Sports+ Calidad"
        // The away team is one or more words; the channel is the rest. We can't tell
        // the split programmatically, so we take "first 1-3 words" as away and the
        // rest as channel — this matches the upstream convention (team names are
        // typically 1-3 words, channel names are 1-4 words, "Calidad" tail is junk).
        $tokens = preg_split('/\s+/', $after);
        $awayTokens = [];
        $channel = null;

        if ($tokens !== false) {
            // Drop trailing "Calidad" or similar generic tail words
            while (! empty($tokens) && in_array(strtolower(end($tokens)), ['calidad', 'hd', 'sd'], true)) {
                array_pop($tokens);
            }

            // Heuristic: take up to 4 leading tokens as the away team, rest is channel
            $awayMax = min(4, count($tokens));
            for ($i = 0; $i < $awayMax; $i++) {
                $candidate = array_slice($tokens, 0, $i + 1);
                $remaining = array_slice($tokens, $i + 1);

                if (empty($remaining)) {
                    $awayTokens = $candidate;
                    break;
                }

                // If the next token looks like a channel marker, stop here
                $next = strtolower($remaining[0]);
                if (in_array($next, ['win', 'espn', 'fox', 'tnt', 'mls', 'amazon', 'disney', 'hbo', 'paramount', 'star+', 'tyc', 'directv', 'tudn', 'univision', 'premiere', 'lance', 'globoplay', 'vix'], true)) {
                    $awayTokens = $candidate;
                    break;
                }
            }

            if (empty($awayTokens)) {
                $awayTokens = [array_shift($tokens)];
            }

            $channelTokens = array_slice($tokens, count($awayTokens));
            $channel = ! empty($channelTokens) ? implode(' ', $channelTokens) : null;
        }

        return [
            'league' => $league,
            'home' => trim($parts[0]),
            'away' => implode(' ', $awayTokens),
            'time' => $time,
            'channel' => $channel,
            'quality' => $quality,
            'options' => [],
        ];
    }

    private function optionSource(string $text): string
    {
        if (preg_match('/\|\s*OP\s*(\d+)/i', $text, $m)) {
            return 'OP '.$m[1];
        }

        return 'OP';
    }

    private function optionQuality(string $text): ?string
    {
        if (preg_match('/\b(\d{3,4}p)\b/i', $text, $m)) {
            return $m[1];
        }

        return null;
    }

    private function decodeEmbed(string $href): string
    {
        $query = parse_url($href, PHP_URL_QUERY) ?? '';
        parse_str($query, $params);
        $encoded = $params['r'] ?? '';

        if ($encoded === '') {
            return $href;
        }

        $decoded = base64_decode($encoded, true);

        return $decoded !== false ? $decoded : $href;
    }

    private function extractAttr(string $tag, string $name): ?string
    {
        if (preg_match('/\b'.preg_quote($name, '/').'="([^"]*)"/i', $tag, $m)) {
            return $m[1];
        }

        if (preg_match("/\b".preg_quote($name, '/')."='([^']*)'/i", $tag, $m)) {
            return $m[1];
        }

        return null;
    }
}
