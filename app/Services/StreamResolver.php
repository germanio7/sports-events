<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

// ponytail: sigue iframes hasta el player final y extrae el .m3u8 con regex.
// Sin resolvers por host: cuando un host cambie ofuscación, la opción cae al embed original.
// Los tokens expiran → cache corto (120s).
class StreamResolver
{
    private const CACHE_TTL = 120;

    private const MAX_DEPTH = 3;

    private const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    public function resolve(string $url): string
    {
        return $this->resolveMany([$url])[$url];
    }

    /** @param string[] $urls @return array<string, string> url original → .m3u8 (o la url tal cual si falla) */
    public function resolveMany(array $urls): array
    {
        $targets = [];
        foreach (array_unique($urls) as $url) {
            $targets[$url] = $this->unwrap($url);
        }

        $results = [];
        $pending = [];
        foreach ($targets as $original => $target) {
            $cached = Cache::get('resolve:'.md5($target));
            if (is_string($cached) && $cached !== '') {
                $results[$original] = $cached;
            } else {
                $pending[$original] = $target;
            }
        }

        $depth = self::MAX_DEPTH;
        while ($pending !== [] && $depth-- > 0) {
            $bodies = $this->poolGet(array_values(array_unique($pending)));
            $next = [];
            foreach ($pending as $original => $target) {
                $html = $bodies[$target] ?? null;
                if ($html === null) {
                    $results[$original] = $target;
                    continue;
                }
                if (($m3u8 = $this->extractM3u8($html)) !== null) {
                    $results[$original] = $m3u8;
                    continue;
                }
                if ($depth > 0 && preg_match('/<iframe[^>]+src=["\']([^"\']+)["\']/i', $html, $m)) {
                    // ponytail: algunos hosts escapan el src como \/6.php — stripslashes o la URL queda inválida.
                    $next[$original] = $this->absolute(stripslashes($m[1]), $target);
                    continue;
                }
                $results[$original] = $target;
            }
            $pending = $next;
        }

        foreach ($pending as $original => $target) {
            $results[$original] = $target;
        }

        foreach ($results as $original => $final) {
            // ponytail: solo se cachean éxitos — cachear el fallback envenenaría el próximo intento.
            if (str_contains($final, '.m3u8')) {
                Cache::put('resolve:'.md5($targets[$original]), $final, self::CACHE_TTL);
            }
        }

        return $results;
    }

    /** @param string[] $urls @return array<string, string|null> */
    private function poolGet(array $urls): array
    {
        try {
            $responses = Http::pool(fn ($pool) => array_map(
                fn ($url) => $pool->withHeaders([
                    'User-Agent' => self::USER_AGENT,
                    'Referer' => 'https://pelisjuanita.com/',
                ])->retry(1, 500)->timeout(10)->get($url),
                $urls,
            ));
        } catch (Throwable) {
            return [];
        }

        $bodies = [];
        foreach ($urls as $i => $url) {
            try {
                $response = $responses[$i];
                $bodies[$url] = $response->successful() ? $response->body() : null;
            } catch (Throwable) {
                $bodies[$url] = null;
            }
        }

        return $bodies;
    }

    private function unwrap(string $url): string
    {
        $query = parse_url($url, PHP_URL_QUERY) ?? '';
        parse_str($query, $params);
        $encoded = $params['r'] ?? '';

        if (is_string($encoded) && $encoded !== '') {
            $decoded = base64_decode($encoded, true);
            if ($decoded !== false && str_starts_with($decoded, 'http')) {
                return $decoded;
            }
        }

        return $url;
    }

    private function extractM3u8(string $html): ?string
    {
        $patterns = [
            '/playbackURL\s*=\s*"([^"]+\.m3u8[^"]*)"/i',
            '/(?:source|file)\s*:\s*"([^"]+\.m3u8[^"]*)"/i',
            '/"(https?:[^"]+\.m3u8[^"]*)"/i',
            "/'(https?:[^']+\\.m3u8[^']*)'/i",
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $m) && str_starts_with($m[1], 'http')) {
                return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        return null;
    }

    private function absolute(string $src, string $base): string
    {
        if (str_starts_with($src, 'http') || str_starts_with($src, '//')) {
            return str_starts_with($src, '//') ? 'https:'.$src : $src;
        }

        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');

        return str_starts_with($src, '/') ? $origin.$src : $origin.'/'.ltrim($src, '/');
    }
}
