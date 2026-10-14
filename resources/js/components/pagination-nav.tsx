import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { Paginated } from '@/types';

const pageButton = 'h-12 px-6 text-sm tracking-[0.06em]';

function PageLink({
    href,
    label,
    primary = false,
}: {
    href: string | null;
    label: ReactNode;
    primary?: boolean;
}) {
    if (href === null) {
        return (
            <Button
                variant="outline"
                size="md"
                disabled
                className={cn(
                    pageButton,
                    'border-border text-muted-foreground',
                )}
            >
                {label}
            </Button>
        );
    }

    return (
        <Button
            asChild
            variant={primary ? 'default' : 'outline'}
            size="md"
            className={pageButton}
        >
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
    className,
}: {
    paginator: Paginated<unknown>;
    className?: string;
}) {
    if (paginator.last_page <= 1) {
        return null;
    }

    return (
        <nav
            aria-label="Pages"
            className={cn('flex items-center justify-between gap-4', className)}
        >
            <PageLink
                href={paginator.prev_page_url}
                label={
                    <>
                        <span aria-hidden="true">←&nbsp;</span>Previous
                    </>
                }
            />
            <span className="text-sm font-semibold tracking-[0.1em] text-muted-foreground uppercase">
                Page {paginator.current_page} of {paginator.last_page}
            </span>
            <PageLink
                href={paginator.next_page_url}
                label={
                    <>
                        Next<span aria-hidden="true">&nbsp;→</span>
                    </>
                }
                primary
            />
        </nav>
    );
}
