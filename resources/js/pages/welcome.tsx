import { Head, Link } from '@inertiajs/react';

import PublicLayout from '@/layouts/public-layout';

export default function Welcome() {
    return (
        <PublicLayout title="Eventos Deportivos">
            <Head title="Eventos Deportivos" />

            <section className="flex flex-1 flex-col justify-center py-8">
                <div className="mb-4 flex items-center gap-2 text-[11px] tracking-[4px] text-[var(--amber)] uppercase">
                    <span className="led" /> agregador de streams deportivos
                </div>
                <h1 className="mb-4 text-6xl leading-[0.86] font-extrabold tracking-[-3px] text-[var(--fg-max)] md:text-8xl">
                    Eventos<span className="text-[var(--green)] [text-shadow:0_0_34px_var(--green-glow)]"> Deportivos</span>
                </h1>
                <p className="mb-2 text-[15px] font-semibold tracking-[0.5px] text-[var(--fg-hi)]">
                    El <em className="text-[var(--amber)] not-italic">Winamp</em> de los streams deportivos.
                </p>
                <p className="mb-8 max-w-[60ch] text-[13.5px] leading-[1.8] text-[var(--fg)]">
                    Explorá eventos en vivo y próximos de fútbol, básquet, motor, rugby y más — abrí una fuente, elegí un stream, mirá acá mismo.
                    Interfaz oscura, sabor terminal, <em className="text-[var(--amber-hi)] not-italic">solo agregador</em>.
                </p>
                <div className="flex flex-wrap gap-3">
                    <Link
                        href="/opcion-1"
                        className="btn-go inline-flex items-center gap-2 rounded px-6 py-3 text-[12px] font-bold tracking-[2px] uppercase"
                    >
                        ▸ opción 1
                    </Link>
                    <Link
                        href="/opcion-2"
                        className="btn-ghost inline-flex items-center gap-2 rounded px-6 py-3 text-[12px] font-bold tracking-[2px] uppercase"
                    >
                        ▸ opción 2
                    </Link>
                </div>
            </section>
        </PublicLayout>
    );
}

