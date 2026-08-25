import { Link, usePage } from '@inertiajs/react';
import { type ReactNode } from 'react';

import CrtOverlay from '@/components/crt-overlay';
import { type BreadcrumbItem, type SharedData } from '@/types';

interface PublicLayoutProps {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
    title?: string;
}

const navItems: { href: string; label: string }[] = [
    { href: '/', label: 'Inicio' },
    { href: '/opcion-1', label: 'Opción 1' },
    { href: '/opcion-2', label: 'Opción 2' },
];

export default function PublicLayout({ children, breadcrumbs, title }: PublicLayoutProps) {
    const { url, props } = usePage<SharedData>();
    void breadcrumbs; // currently unused; kept for parity with the starter layout

    return (
        <div className="text-foreground relative flex min-h-screen flex-col overflow-x-hidden">
            <CrtOverlay />

            <nav className="sticky top-0 z-50 border-b border-[var(--line)] bg-[rgba(9,11,8,0.92)] backdrop-blur-md">
                <div className="mx-auto flex h-[58px] max-w-[1080px] items-center justify-between gap-4 px-5 md:px-10">
                    <Link href="/" className="flex items-center gap-3 font-bold tracking-[3px] text-[var(--fg-hi)]">
                        <span className="led" />
                        <span>
                            Eventos <b className="text-[var(--green)] drop-shadow-[0_0_12px_var(--green-glow)]">Deportivos</b>
                        </span>
                    </Link>
                    <div className="flex items-center gap-1">
                        {navItems.map((item) => {
                            const active = url === item.href || (item.href !== '/' && url.startsWith(item.href));
                            return (
                                <Link
                                    key={item.href}
                                    href={item.href}
                                    className={
                                        'rounded px-3 py-2 text-[10px] font-bold tracking-[2px] uppercase transition-colors ' +
                                        (active
                                            ? 'text-[var(--green)] [text-shadow:0_0_10px_var(--green-glow)]'
                                            : 'text-[var(--mute)] hover:text-[var(--green)]')
                                    }
                                >
                                    {item.label}
                                </Link>
                            );
                        })}
                    </div>
                </div>
            </nav>

            <main className="relative z-10 mx-auto flex w-full max-w-[1080px] flex-1 flex-col px-5 py-12 md:px-10">
                {title && <h1 className="sr-only">{title}</h1>}
                {children}
            </main>

            <footer className="relative z-10 mt-auto border-t border-[var(--line)] bg-[var(--ink-2)] py-10">
                <div className="mx-auto flex max-w-[1080px] flex-col items-center gap-3 px-5 text-center text-[11px] tracking-[2px] text-[var(--mute-2)] md:px-10">
                    <div>
                        <span className="text-[var(--green)]">~</span> Eventos Deportivos // solo agregador
                    </div>
                    <div>
                        visitas de hoy <b className="text-[var(--green)]">{props.visits.today}</b> · histórico{' '}
                        <b className="text-[var(--green)]">{props.visits.total}</b>
                    </div>
                    <div className="text-[var(--mute-2)]">Esta plataforma no aloja ni transmite ningún contenido.</div>
                </div>
            </footer>
        </div>
    );
}
