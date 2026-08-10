import { type RefObject } from 'react';

type StreamType = 'hls' | 'video' | 'embed';

interface PlayerProps {
    stream: { url: string; title: string; type: StreamType };
    onClose: () => void;
    videoRef?: RefObject<HTMLVideoElement | null>;
}

export default function Player({ stream, onClose, videoRef }: PlayerProps) {
    return (
        <div
            role="dialog"
            aria-modal="true"
            aria-label={`Reproductor · ${stream.title}`}
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/85 p-4 backdrop-blur-sm"
            onClick={onClose}
        >
            <div className="w-full max-w-6xl" onClick={(e) => e.stopPropagation()}>
                <div className="tty-frame">
                    <div className="tty-head">
                        <span className="tty-prompt">$</span>
                        <span className="tty-cmd">stream</span>
                        <span className="tty-flag">--tipo {stream.type}</span>
                        <span className="tty-pid">tty1</span>
                    </div>
                    <div className="tty-body !p-0">
                        <div className="flex items-center justify-between border-b border-[var(--line)] bg-[var(--ink-2)] px-4 py-2">
                            <div className="flex items-center gap-2 text-[12px]">
                                <span className="text-[var(--green)]">●</span>
                                <span className="text-[var(--amber-hi)]">transmitiendo</span>
                                <span className="text-[var(--mute)]">—</span>
                                <span className="max-w-[60ch] truncate text-[var(--fg-hi)]">{stream.title}</span>
                            </div>
                            <button
                                type="button"
                                onClick={onClose}
                                className="rounded border border-[var(--line-hi)] bg-[var(--panel-2)] px-2 py-1 text-[11px] tracking-[2px] text-[var(--mute)] uppercase transition-colors hover:border-[var(--red)] hover:text-[var(--red)]"
                                aria-label="Cerrar reproductor"
                            >
                                ✕ detener
                            </button>
                        </div>
                        <div className="aspect-video bg-black">
                            {stream.type === 'embed' ? (
                                <iframe
                                    src={stream.url}
                                    title={stream.title}
                                    className="h-full w-full"
                                    allow="autoplay; fullscreen; picture-in-picture;"
                                    allowFullScreen
                                />
                            ) : (
                                <video ref={videoRef} className="h-full w-full" controls autoPlay playsInline>
                                    Tu navegador no soporta el elemento de video.
                                </video>
                            )}
                        </div>
                        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-[var(--line)] bg-[var(--ink-2)] px-4 py-2 text-[11px] tracking-[2px] text-[var(--mute)] uppercase">
                            <span>
                                <span className="text-[var(--mute-2)]">esc</span> cerrar
                            </span>
                            <span className="truncate">origen {stream.url}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
