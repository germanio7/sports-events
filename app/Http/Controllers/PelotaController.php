<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\PelotaLibreScraper;
use App\Services\PelisJuanitaScraper;
use App\Services\StreamResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class PelotaController extends Controller
{
    public function __construct(private readonly PelotaLibreScraper $scraper, private readonly PelisJuanitaScraper $juanita, private readonly StreamResolver $resolver) {}

    public function agenda(): JsonResponse
    {
        return response()->json([
            'source' => 'futbollibrehd.me/api/agenda',
            'events' => $this->scraper->getAgenda()->values(),
        ]);
    }

    public function agendaJuanita(): JsonResponse
    {
        return response()->json([
            'source' => 'pelisjuanita.com/tv/api-agenda.php',
            'events' => $this->juanita->getAgenda()->values(),
        ]);
    }

    // ponytail: modo default para Jellyfin — un canal por partido con URL proxy
    // estable; la resolución al .m3u8 ocurre al sintonizar (los tokens expiran).
    // ?resolve=1 mantiene el modo directo (una entrada por opción, .m3u8 inline).
    public function playlist(Request $request): Response
    {
        $events = $this->juanita->getAgenda();

        if ($request->boolean('resolve')) {
            return $this->directPlaylist($events);
        }

        $lines = ['#EXTM3U'];
        foreach ($events as $event) {
            if (($event['options'] ?? []) === []) {
                continue;
            }
            $league = $this->m3uField($event['league'] ?? 'Otros');
            $id = Str::slug("{$event['home']} vs {$event['away']}");
            $title = $this->m3uField("{$event['home']} vs {$event['away']} ({$event['time']})");
            $lines[] = "#EXTINF:-1 tvg-id=\"{$id}\" group-title=\"{$league}\",{$title}";
            // ponytail: url() con array agrega path, no query — por eso http_build_query.
            // Se mandan todas las opciones: al sintonizar se usa la primera que resuelva.
            $lines[] = url('/api/juanita/stream').'?'.http_build_query(['u' => array_column($event['options'], 'url')]);
        }

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'audio/x-mpegurl',
            'Content-Disposition' => 'inline; filename="juanita.m3u"',
        ]);
    }

    // ponytail: XMLTV mínimo desde la agenda (sin descripciones ni logos).
    // Duración fija 2h por partido; ids iguales a los tvg-id de la playlist.
    public function epg(): Response
    {
        $out = ['<?xml version="1.0" encoding="UTF-8"?>', '<tv>'];
        foreach ($this->juanita->getAgenda() as $event) {
            if (($event['options'] ?? []) === [] || ! is_string($event['time'] ?? null)) {
                continue;
            }
            $id = Str::slug("{$event['home']} vs {$event['away']}");
            $name = $this->xml("{$event['home']} vs {$event['away']}");
            $out[] = "  <channel id=\"{$id}\"><display-name>{$name}</display-name></channel>";
            // ponytail: la hora ya viene en AR; se ancla a date_diary (o hoy) solo para el start.
            $date = $event['date'] ?? date('Y-m-d');
            $start = new \DateTimeImmutable("{$date} {$event['time']}", new \DateTimeZone('America/Argentina/Buenos_Aires'));
            $out[] = "  <programme start=\"{$start->format('YmdHis O')}\" stop=\"{$start->modify('+2 hours')->format('YmdHis O')}\" channel=\"{$id}\"><title>{$name}</title></programme>";
        }
        $out[] = '</tv>';

        return response(implode("\n", $out)."\n", 200, ['Content-Type' => 'application/xml']);
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    // ponytail: redirect al primer .m3u8 vivo de las opciones (batch concurrente).
    // Jellyfin/ffmpeg sigue el 302 y baja los segmentos directo del upstream.
    public function stream(Request $request): RedirectResponse|Response
    {
        $embeds = array_values(array_filter(
            (array) $request->query('u', []),
            fn ($u) => is_string($u) && (str_starts_with($u, 'http') || str_starts_with($u, '/')),
        ));
        if ($embeds === []) {
            abort(400);
        }

        // ponytail: se itera $embeds (orden upstream, primera = HD) y no el mapa,
        // porque el mapa mezcla cacheados con recién resueltos y pierde el orden.
        $map = $this->resolver->resolveMany($embeds);
        foreach ($embeds as $u) {
            $target = $map[$u] ?? $u;
            if (str_contains($target, '.m3u8')) {
                return redirect()->away($target);
            }
        }

        abort(502);
    }

    /** @param \Illuminate\Support\Collection<int, array<string, mixed>> $events */
    private function directPlaylist($events): Response
    {
        $urls = [];
        foreach ($events as $event) {
            foreach ($event['options'] as $opt) {
                $urls[] = $opt['url'];
            }
        }
        $resolved = $this->resolver->resolveMany($urls);

        $lines = ['#EXTM3U'];
        foreach ($events as $event) {
            $league = $this->m3uField($event['league'] ?? 'Otros');
            foreach ($event['options'] as $opt) {
                $url = $resolved[$opt['url']] ?? $opt['url'];
                if (str_starts_with($url, '/')) {
                    $url = 'https://pelisjuanita.com'.$url;
                }
                $title = $this->m3uField("{$event['home']} vs {$event['away']} ({$event['time']}) — {$opt['source']}");
                $lines[] = "#EXTINF:-1 group-title=\"{$league}\",{$title}";
                $lines[] = $url;
            }
        }

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'audio/x-mpegurl',
            'Content-Disposition' => 'inline; filename="juanita.m3u"',
        ]);
    }

    private function m3uField(?string $value): string
    {
        return trim(preg_replace('/[\r\n]+/', ' ', (string) $value));
    }
}
