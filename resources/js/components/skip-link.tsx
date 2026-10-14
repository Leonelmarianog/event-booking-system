import type { ReactNode } from 'react';

/**
 * A link that shows only when it has keyboard focus. It moves past the navbar to the main part
 * of the page.
 */
export function SkipLink({
    href,
    children,
}: {
    href: string;
    children: ReactNode;
}) {
    return (
        <a
            href={href}
            className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-4 focus:z-50 focus:bg-primary focus:px-4 focus:py-3 focus:font-bold focus:text-primary-foreground"
        >
            {children}
        </a>
    );
}
