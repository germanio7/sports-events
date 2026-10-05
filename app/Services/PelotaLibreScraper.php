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
 * HTML scraper for https://pelotalibre.la/agenda.php.
 *
 * Each event is `<li><a>Liga: Home vs Away<span class="t">HH:MM</span></a><ul>…</ul></li>`;
 * options link to /eventos.html?r=BASE64(stream url). Hours are fixed UTC+1 — converted to AR (UTC-3).
 * Cache TTL is short (60s) so transient failures self-heal quickly.
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
     * @return Collection<int, array{league: string|null, home: string, away: string, time: string|null, channel: string|null, quality: string|null, options: array<int, array{source: string, quality: string|null, url: string, embed: string}>}>
     */
    public function parseAgenda(string $html): Collection
    {
        if (trim($html) === '') {
            return collect();
        }

        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($dom);

        return collect(iterator_to_array($xpath->query('//li[a/span[@class="t"]]')))
            ->map(fn (\DOMElement $li) => $this->parseItem($xpath, $li))
            ->filter()
            ->values();
    }

    private function parseItem(\DOMXPath $xpath, \DOMElement $li): ?array
    {
        $head = $xpath->query('./a', $li)->item(0);
        $timeNode = $xpath->query('.//span[@class="t"]', $head ?? $li)->item(0);
        $rawTime = trim((string) $timeNode?->textContent);

        $title = trim((string) $head?->textContent);
        $title = trim(preg_replace('/\s+/', ' ', str_replace($rawTime, '', $title)));
        if ($title === '' || ! preg_match('/\bvs\.?\b/i', $title)) {
            return null;
        }

        $league = null;
        if (preg_match('/^([^:]+):\s*(.+)$/', $title, $m)) {
            $league = trim($m[1]) ?: null;
            $title = trim($m[2]);
        }

        $parts = preg_split('/\s+vs\.?\s+/i', $title, 2);
        if (count($parts) !== 2 || trim($parts[0]) === '' || trim($parts[1]) === '') {
            return null;
        }

        // ponytail: upstream publica hora UTC+1 fija (ver /js/horario.js); se convierte a Argentina (UTC-3).
        $time = null;
        if (preg_match('/^(\d{1,2}:\d{2})$/', $rawTime, $m)) {
            $dt = new \DateTimeImmutable($m[1], new \DateTimeZone('+01:00'));
            $time = $dt->setTimezone(new \DateTimeZone('America/Argentina/Buenos_Aires'))->format('H:i');
        }

        $options = collect(iterator_to_array($xpath->query('./ul/li/a[@href]', $li)))->map(function (\DOMElement $a) {
            $url = $this->streamUrl($a->getAttribute('href'));
            if ($url === null) {
                return null;
            }

            $qualityText = trim((string) $a->getElementsByTagName('span')->item(0)?->textContent);
            $name = trim(preg_replace('/\s+/', ' ', str_replace($qualityText, '', $a->textContent)));

            return [
                'source' => $name !== '' ? $name : 'OP',
                'quality' => $this->optionQuality($qualityText.' '.$name),
                'url' => $url,
                'embed' => $url,
            ];
        })->filter()->values()->all();

        return [
            'league' => $league,
            'home' => trim($parts[0]),
            'away' => trim($parts[1]),
            'time' => $time,
            'channel' => null,
            'quality' => null,
            'options' => $options,
        ];
    }

    /** `/eventos.html?r=BASE64` → decoded stream URL; absolute http(s) hrefs pass through. */
    private function streamUrl(string $href): ?string
    {
        parse_str((string) parse_url($href, PHP_URL_QUERY), $q);
        $decoded = is_string($q['r'] ?? null) ? base64_decode($q['r'], true) : false;
        $url = is_string($decoded) ? trim($decoded) : $href;

        return preg_match('#^https?://#i', $url) ? $url : null;
    }

    private function optionQuality(string $text): ?string
    {
        if (preg_match('/\b(\d{3,4}p)\b/i', $text, $m)) {
            return $m[1];
        }

        return stripos($text, 'HD') !== false ? 'HD' : null;
    }
}
