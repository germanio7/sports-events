import { Head } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import Player from '@/components/player';
import { Strip } from '@/components/strip';
import PublicLayout from '@/layouts/public-layout';

type PelotaOption = {
    source: string;
    quality: string | null;
    url: string;
    embed: string;
};

type PelotaEvent = {
    league: string | null;
    home: string;
    away: string;
    time: string | null;
    channel: string | null;
    quality: string | null;
    options: PelotaOption[];
};

type CurrentStream = { url: string; title: string; type: 'hls' | 'video' | 'embed' } | null;

const getStreamType = (url: string): 'hls' | 'video' | 'embed' => {
    const lower = url.toLowerCase();
    if (lower.includes('.m3u8') || lower.includes('m3u8')) return 'hls';
    if (['.mp4', '.webm', '.ogg', '.mov', '.avi', '.mkv'].some((ext) => lower.includes(ext))) return 'video';
    return 'embed';
};

// ponytail: heuristic to sort an agenda that crosses midnight.
// Upstream emits times like 22:10, 23:30, 00:15, 00:30 without a date.
// We treat 00:00–05:59 as "next day" (continuation of the evening), so they
// sort AFTER the evening block. Events without a time go to the end.
const EARLY_HOUR_CUTOFF = 6;

const timeToSortKey = (time: string | null): number => {
    if (!time) return Number.MAX_SAFE_INTEGER;
    const match = /^(\d{1,2}):(\d{2})$/.exec(time);
    if (!match) return Number.MAX_SAFE_INTEGER;
    const hours = Number(match[1]);
    const minutes = Number(match[2]);
    // Pre-midnight times count as "later" than post-midnight ones for sorting
    const dayOffset = hours < EARLY_HOUR_CUTOFF ? 1 : 0;
    return dayOffset * 24 * 60 + hours * 60 + minutes;
};

const groupByLeague = (events: PelotaEvent[]): { league: string; events: PelotaEvent[] }[] => {
    const sorted = [...events].sort((a, b) => timeToSortKey(a.time) - timeToSortKey(b.time));
    const map = new Map<string, PelotaEvent[]>();
    for (const ev of sorted) {
        const key = ev.league ?? 'Otros';
        const list = map.get(key) ?? [];
        list.push(ev);
        map.set(key, list);
    }
    return Array.from(map.entries())
        .map(([league, evs]) => ({ league, events: evs }))
        .sort((a, b) => timeToSortKey(a.events[0]?.time ?? null) - timeToSortKey(b.events[0]?.time ?? null));
};

export default function Pelota() {
    const [events, setEvents] = useState<PelotaEvent[]>([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [currentStream, setCurrentStream] = useState<CurrentStream>(null);

    useEffect(() => {
        const controller = new AbortController();
        const load = async () => {
            setLoading(true);
            setError(null);
            try {
                const response = await fetch('/api/pelota/agenda', { signal: controller.signal });
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                const data: { source: string; events: PelotaEvent[] } = await response.json();
                setEvents(data.events);
            } catch (err) {
                if ((err as { name?: string }).name === 'AbortError') return;
                setError('no se pudo obtener la agenda');
                setEvents([]);
            } finally {
                setLoading(false);
            }
        };
        void load();
        return () => controller.abort();
    }, []);

    const play = (url: string, title: string) => {
        setCurrentStream({ url, title, type: getStreamType(url) });
    };

    const closePlayer = () => setCurrentStream(null);

    const grouped = groupByLeague(events);

    return (
        <PublicLayout title="Opción 2">
            <Head title="Opción 2" />

            <Strip index="02" name="opcion" highlight="2" />

            <div className="tty-frame mb-10">
                <div className="tty-head">
                    <span className="tty-prompt">$</span>
                    <span className="tty-cmd">agenda</span>
                    <span className="tty-flag">--fuente pelotaalibre.st · hora AR (UTC-3)</span>
                    <span className="tty-pid">{events.length.toString().padStart(3, '0')} partidos</span>
                </div>
                <div className="tty-body">
                    <div className="mb-4 flex flex-wrap items-center gap-3 text-[11.5px]">
                        <span className="text-[var(--mute)]">scraper</span>
                        <span className="rounded border border-[var(--line-hi)] bg-[var(--panel-2)] px-2 py-0.5 text-[var(--amber-hi)]">
                            HTML · frágil · 60s cache
                        </span>
                        <span className="text-[var(--mute)]">orden: noche → madrugada</span>
                    </div>

                    {loading ? (
                        <div className="space-y-3">
                            {Array.from({ length: 4 }).map((_, i) => (
                                <div key={i} className="panel p-4">
                                    <div className="mb-2 h-3 w-1/3 animate-pulse rounded bg-[var(--panel-3)]" />
                                    <div className="h-3 w-2/3 animate-pulse rounded bg-[var(--panel-3)]" />
                                </div>
                            ))}
                        </div>
                    ) : error ? (
                        <div className="rounded border border-[var(--red)]/40 bg-[var(--red)]/10 px-4 py-3 text-[12.5px] text-[var(--red)]">
                            ▒ {error}
                        </div>
                    ) : events.length === 0 ? (
                        <div className="flex flex-col items-center justify-center py-16 text-center text-[var(--mute)]">
                            <div className="mb-3 text-4xl">▒▒</div>
                            <div className="text-[12px] tracking-[2px] uppercase">sin partidos</div>
                            <div className="mt-1 text-[11px]">el sitio upstream no tiene eventos cargados ahora</div>
                        </div>
                    ) : (
                        <div className="space-y-6">
                            {grouped.map(({ league, events: leagueEvents }) => (
                                <section key={league}>
                                    <h3 className="mb-2 flex items-center gap-2 text-[11px] tracking-[3px] text-[var(--amber)] uppercase">
                                        <span className="led" /> {league}
                                        <span className="text-[var(--mute-2)]">· {leagueEvents.length} partidos</span>
                                    </h3>
                                    <div className="space-y-2">
                                        {leagueEvents.map((event, idx) => (
                                            <MatchRow key={`${event.home}-${event.away}-${idx}`} event={event} onPlay={play} />
                                        ))}
                                    </div>
                                </section>
                            ))}
                        </div>
                    )}

                    <div className="mt-6 border border-[var(--amber)]/30 bg-[var(--amber-dim)] px-4 py-3 text-[12.5px] leading-relaxed text-[var(--amber-hi)]">
                        <span className="mr-2 font-bold tracking-[2px] text-[var(--amber)] uppercase">▸ aviso</span>
                        Esta vista lista partidos scrapeados de un sitio de terceros. Los enlaces a streams son externos y pueden romperse o no estar
                        disponibles. Esta plataforma no aloja ni transmite ningún contenido directamente.
                    </div>
                </div>
            </div>

            {currentStream && <Player stream={currentStream} onClose={closePlayer} />}
        </PublicLayout>
    );
}

function MatchRow({ event, onPlay }: { event: PelotaEvent; onPlay: (url: string, title: string) => void }) {
    const title = `${event.home} vs ${event.away}`;
    return (
        <article className="panel p-3">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <div className="min-w-0 flex-1">
                    <div className="text-[12.5px] font-bold text-[var(--fg-hi)]">
                        <span>{event.home}</span>
                        <span className="mx-2 text-[var(--mute)]">vs</span>
                        <span>{event.away}</span>
                    </div>
                    <div className="mt-1 flex flex-wrap items-center gap-2 text-[10.5px] tracking-[1px] text-[var(--mute)] uppercase">
                        {event.time && (
                            <span className="rounded border border-[var(--line-hi)] bg-[var(--ink-2)] px-1.5 py-0.5 text-[var(--green)]">
                                {event.time}
                            </span>
                        )}
                        {event.channel && <span className="text-[var(--amber-hi)]">{event.channel}</span>}
                        {event.quality && <span>· {event.quality}</span>}
                    </div>
                </div>
            </div>

            {event.options.length > 0 && (
                <div className="mt-2 flex flex-wrap gap-1.5">
                    {event.options.map((opt, i) => (
                        <button
                            key={`${opt.url}-${i}`}
                            type="button"
                            onClick={() => onPlay(opt.embed, title)}
                            className="rounded border border-[var(--line-hi)] bg-[var(--panel-2)] px-2.5 py-1 text-[11px] font-bold text-[var(--fg-hi)] transition-colors hover:border-[var(--green)] hover:text-[var(--green)]"
                            title={opt.embed}
                        >
                            <span className="text-[var(--green)]">▶</span> {opt.source}
                            {opt.quality && <span className="ml-1 text-[var(--amber-hi)]">{opt.quality}</span>}
                        </button>
                    ))}
                </div>
            )}
        </article>
    );
}
