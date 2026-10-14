import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import type { RouteDefinition } from '@/wayfinder';

/**
 * The small link at the top of a page that goes back one level, for example "← My events".
 */
export function BackLink({
    href,
    children,
}: {
    href: RouteDefinition<'get'> | string;
    children: ReactNode;
}) {
    return (
        <Link
            href={href}
            className="inline-flex min-h-11 items-center self-start text-sm font-semibold tracking-[0.08em] text-muted-foreground uppercase outline-none hover:text-foreground focus-visible:outline-3 focus-visible:outline-offset-3 focus-visible:outline-ring"
        >
            <span aria-hidden="true">←&nbsp;</span>
            {children}
        </Link>
    );
}
