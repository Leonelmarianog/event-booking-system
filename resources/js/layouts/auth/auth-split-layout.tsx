import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { home } from '@/routes';
import type { AuthLayoutProps, AuthPanelTint } from '@/types';

const tints: Record<AuthPanelTint, string> = {
    lime: 'bg-lime',
    violet: 'bg-violet',
    orange: 'bg-orange',
    green: 'bg-[#1f6f5c]',
};

export default function AuthSplitLayout({
    children,
    title,
    description,
    panel,
}: AuthLayoutProps) {
    return (
        <div className="grid min-h-dvh bg-background text-foreground lg:grid-cols-2">
            <aside
                className={cn(
                    'relative isolate hidden flex-col justify-between gap-12 overflow-hidden px-12 py-10 text-[#f2f0eb] lg:flex',
                    tints[panel?.tint ?? 'lime'],
                )}
            >
                <img
                    src="/images/auth-crowd.jpg"
                    alt=""
                    className="absolute inset-0 -z-20 size-full object-cover mix-blend-multiply brightness-125 contrast-115 grayscale"
                />
                <div
                    aria-hidden="true"
                    className="absolute inset-0 -z-10 bg-[linear-gradient(180deg,rgba(13,13,14,0.55)_0%,rgba(13,13,14,0)_30%,rgba(13,13,14,0)_45%,rgba(13,13,14,0.85)_100%)]"
                />
                <Link
                    href={home()}
                    aria-label="Gather, home"
                    className="self-start font-display text-[30px] uppercase"
                >
                    Gather<span className="text-lime">.</span>
                </Link>
                {panel?.headline && (
                    <div>
                        <p className="font-display text-[clamp(56px,7vw,112px)] leading-[0.9] whitespace-pre-line uppercase">
                            {panel.headline}
                        </p>
                        {panel.tagline && (
                            <p className="mt-5 max-w-[380px] text-lg font-medium">
                                {panel.tagline}
                            </p>
                        )}
                    </div>
                )}
            </aside>

            <main className="flex items-center justify-center px-8 py-14">
                <div className="w-full max-w-[420px]">
                    <Link
                        href={home()}
                        aria-label="Gather, home"
                        className="mb-10 inline-block font-display text-[30px] uppercase lg:hidden"
                    >
                        Gather<span className="dark:text-lime">.</span>
                    </Link>
                    <h1 className="font-display text-[56px] leading-none font-normal uppercase">
                        {title}
                    </h1>
                    {description && (
                        <p className="mt-2 mb-8 text-muted-foreground">
                            {description}
                        </p>
                    )}
                    {children}
                </div>
            </main>
        </div>
    );
}
