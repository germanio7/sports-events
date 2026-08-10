export default function CrtOverlay() {
    return (
        <>
            <div className="crt-grain" />
            <div className="crt-scan" />
            <div className="chassis" />
            <span
                aria-hidden
                className="pointer-events-none fixed top-[14px] left-[14px] z-[60] h-3 w-3 rounded-full shadow-[inset_0_1px_1px_rgba(255,255,255,0.12),0_1px_2px_rgba(0,0,0,0.6)]"
                style={{
                    background: 'radial-gradient(circle at 35% 30%,#3a4033,#14170f 70%)',
                }}
            />
            <span
                aria-hidden
                className="pointer-events-none fixed top-[14px] right-[14px] z-[60] h-3 w-3 rounded-full shadow-[inset_0_1px_1px_rgba(255,255,255,0.12),0_1px_2px_rgba(0,0,0,0.6)]"
                style={{
                    background: 'radial-gradient(circle at 35% 30%,#3a4033,#14170f 70%)',
                }}
            />
            <span
                aria-hidden
                className="pointer-events-none fixed bottom-[14px] left-[14px] z-[60] h-3 w-3 rounded-full shadow-[inset_0_1px_1px_rgba(255,255,255,0.12),0_1px_2px_rgba(0,0,0,0.6)]"
                style={{
                    background: 'radial-gradient(circle at 35% 30%,#3a4033,#14170f 70%)',
                }}
            />
            <span
                aria-hidden
                className="pointer-events-none fixed right-[14px] bottom-[14px] z-[60] h-3 w-3 rounded-full shadow-[inset_0_1px_1px_rgba(255,255,255,0.12),0_1px_2px_rgba(0,0,0,0.6)]"
                style={{
                    background: 'radial-gradient(circle at 35% 30%,#3a4033,#14170f 70%)',
                }}
            />
        </>
    );
}
