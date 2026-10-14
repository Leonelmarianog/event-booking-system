import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';

const items = [
    { title: 'Profile', href: edit() },
    { title: 'Security', href: editSecurity() },
    { title: 'Appearance', href: editAppearance() },
];

export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();

    return (
        <div className="px-8 pt-14 pb-22">
            <h1 className="mb-10 font-display text-[clamp(56px,8vw,112px)] leading-[0.9] uppercase">
                Settings
            </h1>

            <div className="flex flex-wrap items-start gap-14">
                <nav
                    aria-label="Settings"
                    className="flex max-w-60 flex-[1_1_200px] flex-col gap-1 text-sm font-semibold tracking-[0.08em] uppercase"
                >
                    {items.map((item) => {
                        const current = isCurrentOrParentUrl(item.href);

                        return (
                            <Link
                                key={item.title}
                                href={item.href}
                                aria-current={current ? 'page' : undefined}
                                className={cn(
                                    'flex min-h-12 items-center px-4 focus-visible:outline-3 focus-visible:outline-offset-3 focus-visible:outline-ring',
                                    current
                                        ? 'bg-primary text-primary-foreground'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {item.title}
                            </Link>
                        );
                    })}
                </nav>

                <div className="flex min-w-0 flex-[999_1_560px] flex-col gap-14">
                    {children}
                </div>
            </div>
        </div>
    );
}
