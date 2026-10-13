import { SiteFooter } from '@/components/site-footer';
import { SiteNav } from '@/components/site-nav';
import type { AppLayoutProps } from '@/types';

export default function AppLayout({ children }: AppLayoutProps) {
    return (
        <div className="flex min-h-screen flex-col">
            <a
                href="#content"
                className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-4 focus:z-50 focus:bg-primary focus:px-4 focus:py-3 focus:font-bold focus:text-primary-foreground"
            >
                Skip to content
            </a>
            <SiteNav />
            <main
                id="content"
                tabIndex={-1}
                className="mx-auto flex w-full max-w-7xl flex-1 flex-col outline-none"
            >
                {children}
            </main>
            <SiteFooter />
        </div>
    );
}
