<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

// ponytail: JSON (Strapi) de pelisjuanita, misma forma que PelotaLibreScraper para reusar la UI tal cual.
class PelisJuanitaScraper
{
    private const CACHE_TTL = 60;

    private function agendaUrl(): string
    {
        return (string) config('services.pelisjuanita.agenda_url');
    }

    /**
     * @return Collection<int, array{league: string|null, home: string, away: string, time: string|null, channel: string|null, quality: string|null, options: array<int, array{source: string, quality: string|null, url: string, embed: string}>}>
     */
    public function getAgenda(): Collection
    {
        $cached = Cache::get('juanita:agenda');

        if (is_array($cached)) {
            return collect($cached);
        }

        try {
            $json = Http::retry(2, 800)->timeout(15)->get($this->agendaUrl())->json();
        } catch (ConnectionException|Throwable $e) {
            Log::warning('juanita agenda fetch failed', ['error' => $e->getMessage()]);

            return collect();
        }

        $events = $this->parseAgenda($json);
        Cache::put('juanita:agenda', $events->all(), self::CACHE_TTL);

        return $events;
    }

    private function parseAgenda(mixed $json): Collection
    {
        $items = is_array($json) ? ($json['data'] ?? []) : [];

        return collect($items)
            ->map(fn ($item) => $this->parseItem(is_array($item) ? ($item['attributes'] ?? []) : []))
            ->filter()
            ->values();
    }

    private function parseItem(array $a): ?array
    {
        $desc = trim(preg_replace('/\s+/', ' ', (string) ($a['diary_description'] ?? '')));
        if ($desc === '' || ! preg_match('/\bvs\.?\b/i', $desc)) {
            return null;
        }

        $country = is_array($a['country'] ?? null) ? ($a['country']['data']['attributes']['name'] ?? null) : null;
        $league = $country;
        if (preg_match('/^([^:]+):\s*(.+)$/', $desc, $m)) {
            $league = trim($m[1]) ?: $country;
            $desc = trim($m[2]);
        }

        $parts = preg_split('/\s+vs\.?\s+/i', $desc, 2);
        if (count($parts) !== 2 || trim($parts[0]) === '' || trim($parts[1]) === '') {
            return null;
        }

        // ponytail: upstream guarda hora UTC-5 fija; se convierte a UTC de Argentina (UTC-3, sin DST).
        // Verificado con Serie A (11:30 → 13:30 AR) y slots europeos (13:00 → 15:00 AR = 20:00 CEST).
        $time = null;
        if (preg_match('/^(\d{2}:\d{2})/', (string) ($a['diary_hour'] ?? ''), $m)) {
            $dt = new \DateTimeImmutable($m[1], new \DateTimeZone('America/Bogota'));
            $time = $dt->setTimezone(new \DateTimeZone('America/Argentina/Buenos_Aires'))->format('H:i');
        }

        $embeds = is_array($a['embeds'] ?? null) ? ($a['embeds']['data'] ?? []) : [];
        $options = collect($embeds)->map(function ($e) {
            $attr = is_array($e) ? ($e['attributes'] ?? []) : [];
            $name = trim((string) ($attr['embed_name'] ?? ''));
            $url = (string) ($attr['embed_iframe'] ?? '');
            if ($url === '') {
                return null;
            }

            return [
                'source' => $name !== '' ? $name : 'OP',
                'quality' => $this->optionQuality($name),
                'url' => $url,
                'embed' => $this->decodeEmbed($url),
            ];
        })->filter()->values()->all();

        return [
            'league' => $league,
            'home' => trim($parts[0]),
            'away' => trim($parts[1]),
            'time' => $time,
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}/', (string) ($a['date_diary'] ?? ''), $dm) ? $dm[0] : null,
            'channel' => null,
            'quality' => null,
            'options' => $options,
        ];
    }

    private function optionQuality(string $text): ?string
    {
        if (preg_match('/\b(\d{3,4}p)\b/i', $text, $m)) {
            return $m[1];
        }

        return stripos($text, 'HD') !== false ? 'HD' : null;
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
}
