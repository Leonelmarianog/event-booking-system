import { Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import type { Paginated } from '@/types';

function PageLink({ href, label }: { href: string | null; label: string }) {
    if (href === null) {
        return (
            <Button variant="outline" size="sm" disabled>
                {label}
            </Button>
        );
    }

    return (
        <Button asChild variant="outline" size="sm">
            <Link href={href}>{label}</Link>
        </Button>
    );
}

/**
 * The Previous and Next links of a paginated list. It shows nothing when the list has
 * only one page.
 */
export function PaginationNav({
    paginator,
}: {
    paginator: Paginated<unknown>;
}) {
    if (paginator.last_page <= 1) {
        return null;
    }

    return (
        <nav
            aria-label="Pages"
            className="flex items-center justify-between gap-4"
        >
            <PageLink href={paginator.prev_page_url} label="Previous" />
            <span className="text-sm text-muted-foreground">
                Page {paginator.current_page} of {paginator.last_page}
            </span>
            <PageLink href={paginator.next_page_url} label="Next" />
        </nav>
    );
}
