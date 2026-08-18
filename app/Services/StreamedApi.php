<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class StreamedApi
{
    private function baseUrl(): string
    {
        return rtrim((string) config('services.streamed.api_base'), '/');
    }

    private function imgBase(): string
    {
        return rtrim((string) config('services.streamed.img_base'), '/');
    }

    private const RETRIES = 5;

    private const RETRY_DELAY_MS = 1500;

    private const CACHE_EVENTS_TTL = 60;

    private const CACHE_SPORTS_TTL = 3600;

    /**
     * Fetch the list of available sport categories from streamed.pk.
     *
     * Cached for one hour; the upstream changes this list rarely.
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function getSports(): array
    {
        return Cache::remember('streamed:sports', self::CACHE_SPORTS_TTL, function (): array {
            $sports = $this->fetchJson($this->baseUrl().'/sports');

            return collect($sports ?? [])
                ->map(fn (array $sport) => [
                    'id' => (string) ($sport['id'] ?? ''),
                    'name' => (string) ($sport['name'] ?? $sport['id'] ?? ''),
                ])
                ->filter(fn (array $sport) => $sport['id'] !== '')
                ->values()
                ->all();
        });
    }

    /**
     * Fetch events (matches) from streamed.pk.
     *
     * Endpoint matrix:
     *  - live=false, popular=false → /matches/{sport}
     *  - live=false, popular=true  → /matches/{sport}/popular
     *  - live=true,  popular=false → /matches/live  (filtered by category)
     *  - live=true,  popular=true  → /matches/live/popular  (NOT filtered: upstream
     *    pool is tiny, so we keep every category instead of zeroing out)
     *  - results are sorted by date ascending and reshaped to the public Event shape
     *  - cached for 60s to avoid hammering the upstream on every chip click
     */
    public function getEvents(?string $sport = null, bool $live = false, bool $popular = false): Collection
    {
        $sport ??= 'football';
        $cacheKey = 'streamed:events:'.($live ? 'live' : $sport).':'.($popular ? 'popular' : 'all');

        $events = Cache::remember($cacheKey, self::CACHE_EVENTS_TTL, function () use ($sport, $live, $popular) {
            $popularSegment = $popular ? 'popular' : '';

            $url = $live
                ? $this->baseUrl().'/matches/live/'.$popularSegment
                : $this->baseUrl().'/matches/'.$sport.'/'.$popularSegment;

            $matches = $this->fetchJson($url);
            $filterByCategory = $live && ! $popular;

            return collect($matches ?? [])
                ->when($filterByCategory, fn (Collection $c) => $c->where('category', $sport))
                ->sortBy(fn (array $match) => $this->toTimestamp($match['date'] ?? null))
                ->values()
                ->map(fn (array $match) => [
                    'id' => $match['id'],
                    'name' => $match['title'],
                    'image' => isset($match['poster']) ? $this->imgBase().$match['poster'] : '/notfound.jpg',
                    'date' => $this->normalizeDate($match['date'] ?? null),
                    'category' => $match['category'] ?? null,
                    'sources' => $match['sources'] ?? [],
                ])
                ->all();
        });

        return collect($events);
    }

    /**
     * Fetch stream options for a given source/event from streamed.pk.
     *
     * Only non-empty results are cached; empty/error responses bypass the cache
     * so the next click retries the upstream immediately.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getStream(string $source, string $id): array
    {
        $cacheKey = "streamed:stream:{$source}:{$id}";

        $cached = Cache::get($cacheKey);

        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $streams = $this->fetchJson($this->baseUrl().'/stream/'.$source.'/'.$id) ?? [];

        if ($streams !== []) {
            Cache::put($cacheKey, $streams, self::CACHE_EVENTS_TTL);
        }

        return $streams;
    }

    private function fetchJson(string $url): ?array
    {
        try {
            $response = Http::retry(self::RETRIES, self::RETRY_DELAY_MS)->get($url);

            if (! $response->successful()) {
                return null;
            }

            return $response->json();
        } catch (ConnectionException|Throwable) {
            return null;
        }
    }

    private function toTimestamp(mixed $date): int
    {
        if (is_numeric($date)) {
            // upstream returns milliseconds for most events
            $value = (int) $date;
            if ($value > 10_000_000_000) {
                $value = intdiv($value, 1000);
            }

            return $value;
        }

        if (is_string($date) && $date !== '') {
            $ts = strtotime($date);

            return $ts !== false ? $ts : PHP_INT_MAX;
        }

        return PHP_INT_MAX;
    }

    private function normalizeDate(mixed $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        if (is_numeric($date)) {
            $value = (int) $date;
            if ($value > 10_000_000_000) {
                $value = intdiv($value, 1000);
            }

            return date('c', $value);
        }

        if (is_string($date)) {
            $ts = strtotime($date);

            return $ts !== false ? date('c', $ts) : null;
        }

        return null;
    }
}
