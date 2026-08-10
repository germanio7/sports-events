import { Head } from '@inertiajs/react';
import Hls from 'hls.js';
import { useEffect, useRef, useState } from 'react';

import Player from '@/components/player';
import { Strip } from '@/components/strip';
import PublicLayout from '@/layouts/public-layout';

type Source = {
    id: string;
    source: string;
    options?: StreamOption[];
    loaded?: boolean;
};

type StreamOption = {
    url: string;
    language: string;
    id: string;
    hd: 'HD' | 'SD';
};

type Event = {
    id: string;
    name: string;
    image: string;
    date: string | null;
    category?: string | null;
    sources: Source[];
};

type StreamType = 'hls' | 'video' | 'embed';

type CurrentStream = { url: string; title: string; type: StreamType } | null;

type Sport = { id: string; label: string };

const FALLBACK_SPORTS: Sport[] = [
    { id: 'football', label: 'Fútbol' },
    { id: 'basketball', label: 'Básquet' },
    { id: 'tennis', label: 'Tenis' },
    { id: 'motor-sports', label: 'Motor' },
    { id: 'rugby', label: 'Rugby' },
    { id: 'fight', label: 'UFC/Box' },
];

// Upstream returns sport names in English; we translate the common ones to es-AR.
// ponytail: small map, fallback to capitalized upstream name when unknown.
const SPORT_LABELS: Record<string, string> = {
    football: 'Fútbol',
    basketball: 'Básquet',
    tennis: 'Tenis',
    'motor-sports': 'Motor',
    rugby: 'Rugby',
    fight: 'UFC/Box',
    'american-football': 'Fútbol americano',
    hockey: 'Hockey',
    baseball: 'Béisbol',
    mma: 'MMA',
    boxing: 'Boxeo',
    cricket: 'Críquet',
    'table-tennis': 'Tenis de mesa',
};

const translateSport = (id: string, upstreamName: string): string => {
    const fromMap = SPORT_LABELS[id];
    if (fromMap) return fromMap;
    return upstreamName.charAt(0).toUpperCase() + upstreamName.slice(1);
};

const getStreamType = (url: string): StreamType => {
    const lower = url.toLowerCase();
    if (lower.includes('.m3u8') || lower.includes('m3u8')) return 'hls';
    if (['.mp4', '.webm', '.ogg', '.mov', '.avi', '.mkv'].some((ext) => lower.includes(ext))) return 'video';
    if (
        [
            'embed',
            'iframe',
            'player',
            'youtube.com/embed',
            'youtube-nocookie.com/embed',
            'player.vimeo.com',
            'dailymotion.com/embed',
            'twitch.tv/embed',
        ].some((p) => lower.includes(p))
    ) {
        return 'embed';
    }
    return 'hls';
};

const formatDate = (date: string | null) => {
    if (!date) return '—';
    return new Date(date).toLocaleString('es-AR', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

export default function Events() {
    const [events, setEvents] = useState<Event[]>([]);
    const [loading, setLoading] = useState(false);
    const [loadingSourceId, setLoadingSourceId] = useState<string | null>(null);
    const [sports, setSports] = useState<Sport[]>(FALLBACK_SPORTS);
    const [sportSelected, setSportSelected] = useState(FALLBACK_SPORTS[0]?.id ?? '');
    const [liveOnly, setLiveOnly] = useState(false);
    const [popularOnly, setPopularOnly] = useState(false);
    const [failedImages, setFailedImages] = useState<Set<string>>(new Set());
    const [currentStream, setCurrentStream] = useState<CurrentStream>(null);
    const videoRef = useRef<HTMLVideoElement>(null);
    const hlsRef = useRef<Hls | null>(null);

    useEffect(() => {
        let cancelled = false;
        fetch('/api/sports')
            .then((r) => (r.ok ? r.json() : Promise.reject()))
            .then((data: { id: string; name: string }[]) => {
                if (cancelled || !Array.isArray(data) || data.length === 0) return;
                const mapped: Sport[] = data.map((s) => ({ id: s.id, label: translateSport(s.id, s.name) }));
                setSports(mapped);
                setSportSelected((current) => (mapped.some((s) => s.id === current) ? current : (mapped[0]?.id ?? '')));
            })
            .catch(() => {
                /* keep FALLBACK_SPORTS */
            });
        return () => {
            cancelled = true;
        };
    }, []);

    useEffect(() => {
        const controller = new AbortController();
        void loadEvents(sportSelected, liveOnly, popularOnly, controller.signal);
        return () => controller.abort();
    }, [sportSelected, liveOnly, popularOnly]);

    useEffect(() => {
        if (!currentStream || currentStream.type === 'embed' || !videoRef.current) return;
        const video = videoRef.current;
        const url = currentStream.url;
        if (currentStream.type === 'hls' && Hls.isSupported()) {
            const hls = new Hls({ enableWorker: true, lowLatencyMode: true });
            hlsRef.current = hls;
            hls.loadSource(url);
            hls.attachMedia(video);
            hls.on(Hls.Events.MANIFEST_PARSED, () => {
                video.play().catch(() => undefined);
            });
            hls.on(Hls.Events.ERROR, (_, data) => {
                if (data.fatal) hls.destroy();
            });
        } else {
            video.src = url;
            video.play().catch(() => undefined);
        }
        return () => {
            hlsRef.current?.destroy();
            hlsRef.current = null;
        };
    }, [currentStream]);

    const loadEvents = async (sport: string, live: boolean, popular: boolean, signal: AbortSignal) => {
        setLoading(true);
        try {
            const params = new URLSearchParams({ sport, live: String(live), popular: String(popular) });
            const response = await fetch(`/api/events?${params.toString()}`, { signal });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const data: Event[] = await response.json();
            setEvents(data);
        } catch (err) {
            if ((err as { name?: string }).name === 'AbortError') return;
            setEvents([]);
        } finally {
            setLoading(false);
        }
    };

    const setSport = (id: string) => {
        setSportSelected(id);
        setEvents([]);
    };

    const toggleLive = () => {
        setLiveOnly((v) => !v);
        setEvents([]);
    };

    const togglePopular = () => {
        setPopularOnly((v) => !v);
        setEvents([]);
    };

    const loadSource = async (eventId: string, source: Source) => {
        setLoadingSourceId(source.id);
        try {
            const params = new URLSearchParams({ source: source.source, id: source.id });
            const response = await fetch(`/api/stream?${params.toString()}`);
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const data: { embedUrl: string; language: string; streamNo: string; hd: boolean }[] = await response.json();
            const options: StreamOption[] = data.map((s) => ({
                url: s.embedUrl,
                language: s.language,
                id: s.streamNo,
                hd: s.hd ? 'HD' : 'SD',
            }));
            setEvents((prev) =>
                prev.map((ev) =>
                    ev.id !== eventId ? ev : { ...ev, sources: ev.sources.map((s) => (s.id === source.id ? { ...s, options, loaded: true } : s)) },
                ),
            );
        } catch {
            setEvents((prev) =>
                prev.map((ev) =>
                    ev.id !== eventId ? ev : { ...ev, sources: ev.sources.map((s) => (s.id === source.id ? { ...s, loaded: true } : s)) },
                ),
            );
        } finally {
            setLoadingSourceId(null);
        }
    };

    const playStream = (url: string, title: string) => {
        setCurrentStream({ url, title, type: getStreamType(url) });
    };

    const closePlayer = () => {
        hlsRef.current?.destroy();
        hlsRef.current = null;
        if (videoRef.current) {
            videoRef.current.pause();
            videoRef.current.src = '';
        }
        setCurrentStream(null);
    };

    return (
        <PublicLayout title="Opción 1">
            <Head title="Opción 1" />

            <Strip index="01" name="opcion" highlight="1" />

            <div className="tty-frame mb-10">
                <div className="tty-head">
                    <span className="tty-prompt">$</span>
                    <span className="tty-cmd">eventos</span>
                    <span className="tty-flag">--deporte {sportSelected}</span>
                    {liveOnly && <span className="text-[var(--red)]">--envivo</span>}
                    {popularOnly && <span className="text-[var(--amber)]">--popular</span>}
                    <span className="tty-pid">{events.length.toString().padStart(3, '0')} rows</span>
                </div>
                <div className="tty-body">
                    <div className="mb-4 flex flex-wrap items-center gap-2">
                        <FilterChip active={liveOnly} onClick={toggleLive}>
                            <span
                                className={`mr-2 inline-block h-1.5 w-1.5 rounded-full ${liveOnly ? 'animate-pulse bg-white' : 'bg-[var(--red)]'}`}
                            />
                            en vivo
                        </FilterChip>
                        <FilterChip active={popularOnly} onClick={togglePopular}>
                            ★ popular
                        </FilterChip>
                        <span className="mx-2 h-5 w-px bg-[var(--line)]" />
                        {sports.map((sport) => (
                            <FilterChip key={sport.id} active={sportSelected === sport.id} onClick={() => setSport(sport.id)}>
                                {sport.label}
                            </FilterChip>
                        ))}
                    </div>

                    {loading ? (
                        <SkeletonGrid />
                    ) : events.length === 0 ? (
                        <EmptyState liveOnly={liveOnly} popularOnly={popularOnly} sportSelected={sportSelected} />
                    ) : (
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                            {events.map((event) => (
                                <EventCard
                                    key={event.id}
                                    event={event}
                                    imageFailed={failedImages.has(event.id)}
                                    onImageError={() =>
                                        setFailedImages((prev) => {
                                            const next = new Set(prev);
                                            next.add(event.id);
                                            return next;
                                        })
                                    }
                                    loadingSourceId={loadingSourceId}
                                    onSourceClick={loadSource}
                                    onPlay={playStream}
                                />
                            ))}
                        </div>
                    )}

                    <div className="mt-6 border border-[var(--amber)]/30 bg-[var(--amber-dim)] px-4 py-3 text-[12.5px] leading-relaxed text-[var(--amber-hi)]">
                        <span className="mr-2 font-bold tracking-[2px] text-[var(--amber)] uppercase">▸ aviso</span>
                        Esta plataforma actúa únicamente como agregador de enlaces a contenidos alojados y transmitidos por terceros. No alojamos ni
                        transmitimos ningún contenido directamente.
                    </div>
                </div>
            </div>

            {currentStream && <Player stream={currentStream} onClose={closePlayer} videoRef={videoRef} />}
        </PublicLayout>
    );
}

function FilterChip({ active, onClick, children }: { active: boolean; onClick: () => void; children: React.ReactNode }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={
                'rounded border px-3 py-1.5 text-[12px] font-bold tracking-[1px] uppercase transition-all ' +
                (active
                    ? 'border-[var(--green)] bg-[var(--green)]/15 text-[var(--green)] [text-shadow:0_0_8px_var(--green-glow)]'
                    : 'border-[var(--line-hi)] bg-[var(--panel)] text-[var(--mute)] hover:border-[var(--green)] hover:text-[var(--green)]')
            }
        >
            {children}
        </button>
    );
}

function EventCard({
    event,
    imageFailed,
    onImageError,
    loadingSourceId,
    onSourceClick,
    onPlay,
}: {
    event: Event;
    imageFailed: boolean;
    onImageError: () => void;
    loadingSourceId: string | null;
    onSourceClick: (eventId: string, source: Source) => void;
    onPlay: (url: string, title: string) => void;
}) {
    return (
        <article className="panel overflow-hidden">
            <div className="relative h-36 overflow-hidden border-b border-[var(--line)] bg-[var(--ink)]">
                {imageFailed ? (
                    <div className="flex h-full w-full items-center justify-center text-[var(--mute)]">▒▒ sin_imagen</div>
                ) : (
                    <img alt={event.name} className="h-full w-full object-cover opacity-80" src={event.image} onError={onImageError} />
                )}
                <div className="absolute inset-0 bg-gradient-to-t from-black/80 via-black/10 to-transparent" />
                <div className="absolute right-2 bottom-2 left-2">
                    <div className="flex items-center gap-2 text-[10px] tracking-[2px] text-[var(--amber)] uppercase">
                        {event.category && <span className="text-[var(--green)]">[{event.category}]</span>}
                        <span>▸ {event.sources.length} sources</span>
                    </div>
                    <h3 className="truncate text-[14px] font-bold text-[var(--fg-max)]">{event.name}</h3>
                </div>
            </div>
            <div className="space-y-1.5 p-4">
                <div className="kv">
                    <span className="k">date</span>
                    <span className="v">{formatDate(event.date)}</span>
                </div>
                <div className="kv">
                    <span className="k">id</span>
                    <span className="v truncate">{event.id}</span>
                </div>
                <div className="mt-3 space-y-1.5">
                    {event.sources.map((source) => (
                        <div key={source.id}>
                            <button
                                type="button"
                                onClick={() => onSourceClick(event.id, source)}
                                className="flex w-full items-center justify-between rounded border border-[var(--line-hi)] bg-[var(--panel-2)] px-3 py-2 text-left text-[12px] font-bold text-[var(--fg-hi)] transition-colors hover:border-[var(--green)] hover:text-[var(--green)] disabled:opacity-50"
                                disabled={loadingSourceId === source.id}
                            >
                                <span>
                                    <span className="text-[var(--mute)]">source</span> ▸ {source.source}
                                </span>
                                <span className="text-[10px] tracking-[2px] text-[var(--mute)] uppercase">
                                    {loadingSourceId === source.id ? '…cargando' : source.loaded ? 'recargar' : 'cargar'}
                                </span>
                            </button>
                            {source.loaded && source.options && source.options.length === 0 && (
                                <div className="mt-1 ml-2 rounded border border-dashed border-[var(--line)] bg-[var(--ink-2)] px-3 py-1.5 text-[11px] text-[var(--mute)]">
                                    ▒ no hay streams para esta fuente
                                </div>
                            )}
                            {source.options && source.options.length > 0 && (
                                <div className="mt-1 ml-2 space-y-1">
                                    {source.options.map((option) => (
                                        <button
                                            key={option.id}
                                            type="button"
                                            onClick={() => onPlay(option.url, event.name)}
                                            className="flex w-full items-center gap-2 rounded border border-[var(--line)] bg-[var(--ink-2)] px-3 py-1.5 text-left text-[11.5px] text-[var(--mute)] transition-colors hover:border-[var(--green)] hover:text-[var(--green)]"
                                        >
                                            <span className="text-[var(--green)]">▶</span>
                                            <span className="font-bold text-[var(--amber-hi)]">{option.hd}</span>
                                            <span className="text-[var(--fg-hi)]">stream {option.id}</span>
                                            <span className="ml-auto">{option.language}</span>
                                        </button>
                                    ))}
                                </div>
                            )}
                        </div>
                    ))}
                </div>
            </div>
        </article>
    );
}

function SkeletonGrid() {
    return (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
            {Array.from({ length: 6 }).map((_, i) => (
                <div key={i} className="panel overflow-hidden">
                    <div className="h-36 animate-pulse bg-[var(--panel-3)]" />
                    <div className="space-y-2 p-4">
                        <div className="h-3 w-3/4 animate-pulse rounded bg-[var(--panel-3)]" />
                        <div className="h-3 w-1/2 animate-pulse rounded bg-[var(--panel-3)]" />
                    </div>
                </div>
            ))}
        </div>
    );
}

function EmptyState({ liveOnly, popularOnly, sportSelected }: { liveOnly: boolean; popularOnly: boolean; sportSelected: string }) {
    const hint =
        liveOnly && popularOnly
            ? 'no hay populares en vivo ahora mismo'
            : liveOnly
              ? `no hay ${sportSelected} en vivo ahora mismo`
              : popularOnly
                ? 'no hay populares para este deporte'
                : 'no hay eventos para este deporte';
    return (
        <div className="flex flex-col items-center justify-center py-16 text-center text-[var(--mute)]">
            <div className="mb-3 text-4xl">▒▒</div>
            <div className="text-[12px] tracking-[2px] uppercase">sin eventos</div>
            <div className="mt-1 text-[11px]">{hint}</div>
        </div>
    );
}
