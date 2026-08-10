interface StripProps {
    index: string;
    name: string;
    highlight?: string;
    className?: string;
}

export function Strip({ index, name, highlight, className = '' }: StripProps) {
    return (
        <div className={`strip ${className}`.trim()}>
            <span className="strip-idx">{index}</span>
            <span className="strip-name">
                {name}
                {highlight ? <em>.{highlight}</em> : null}
            </span>
            <span className="strip-rule" />
            <span className="strip-meter" aria-hidden>
                <i />
                <i />
                <i />
                <i />
                <i />
            </span>
        </div>
    );
}
