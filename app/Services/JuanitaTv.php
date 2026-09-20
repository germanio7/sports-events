<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

// ponytail: grilla 24/7 de /tv como JSON estático — el HTML vive tras Cloudflare y no se puede scrapear desde el server.
// Si un día /tv expone API, esto se cambia por fetch como PelisJuanitaScraper. Sin Adultos.
class JuanitaTv
{
    private const BASE = 'https://pelisjuanita.com/tv/';

    /** @return Collection<int, array{name: string, slug: string, category: string, country: string, options: array<int, array{source: string, url: string}>}> */
    public function getChannels(): Collection
    {
        $cached = Cache::get('juanita:tv');

        if (is_array($cached)) {
            return collect($cached);
        }

        $raw = config('juanita_tv.channels', []);
        $channels = collect(is_array($raw) ? $raw : [])
            ->map(fn ($c) => $this->parseChannel(is_array($c) ? $c : []))
            ->filter()
            ->values();

        Cache::put('juanita:tv', $channels->all(), 3600);

        return $channels;
    }

    private function parseChannel(array $c): ?array
    {
        $name = trim((string) ($c['name'] ?? ''));
        if ($name === '' || ($c['categoria'] ?? '') === 'Adultos') {
            return null;
        }

        $options = collect($c['options'] ?? [])
            ->map(function ($o, $i) {
                $url = trim((string) (is_array($o) ? ($o['url'] ?? '') : ''));
                if ($url === '') {
                    return null;
                }

                return ['source' => 'S'.($i + 1), 'url' => $this->absolute($url)];
            })->filter()
            ->values()
            ->all();

        if ($options === []) {
            return null;
        }

        return [
            'name' => $name,
            'slug' => (string) ($c['slug'] ?? ''),
            'category' => (string) ($c['categoria'] ?? 'Otros'),
            'country' => (string) ($c['pais'] ?? ''),
            'options' => $options,
        ];
    }

    private function absolute(string $url): string
    {
        return str_starts_with($url, 'http') || str_starts_with($url, '//')
            ? (str_starts_with($url, '//') ? 'https:'.$url : $url)
            : self::BASE.ltrim($url, '/');
    }
}
