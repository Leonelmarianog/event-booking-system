export function OrDivider() {
    return (
        <div
            aria-hidden="true"
            className="my-7 flex items-center gap-4 text-[13px] font-semibold tracking-[0.14em] text-muted-foreground uppercase"
        >
            <span className="h-px flex-1 bg-border" />
            or
            <span className="h-px flex-1 bg-border" />
        </div>
    );
}
