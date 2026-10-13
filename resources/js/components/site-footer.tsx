export function SiteFooter() {
    return (
        <footer className="border-t border-border">
            <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-8 py-7 text-sm text-muted-foreground">
                <span className="font-display text-xl uppercase">Gather.</span>
                <span>© {new Date().getFullYear()} Gather</span>
            </div>
        </footer>
    );
}
