import { Link, usePage } from '@inertiajs/react';
import { AccountMenu } from '@/components/account-menu';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { home, login, register } from '@/routes';
import { create, index as eventsIndex } from '@/routes/events';

const textLink =
    'inline-flex min-h-11 items-center text-sm font-semibold tracking-[0.08em] uppercase';
const limeLink =
    'inline-flex min-h-11 items-center bg-lime px-5 text-sm font-bold tracking-[0.06em] text-ink uppercase';

export function SiteNav() {
    const { auth } = usePage().props;
    const { isCurrentUrl } = useCurrentUrl();

    const current = (href: Parameters<typeof isCurrentUrl>[0]) =>
        isCurrentUrl(href) ? 'page' : undefined;

    return (
        <header className="border-b border-border bg-background">
            <nav
                aria-label="Main"
                className="mx-auto flex max-w-7xl flex-wrap items-center gap-6 px-8 py-4.5"
            >
                <Link
                    href={home()}
                    aria-label="Gather, home"
                    className="font-display text-[30px] tracking-[0.02em] uppercase"
                >
                    Gather<span className="dark:text-lime">.</span>
                </Link>

                <div className="ml-auto flex flex-wrap items-center gap-5">
                    {auth.user ? (
                        <>
                            <Link
                                href={eventsIndex()}
                                aria-current={current(eventsIndex())}
                                className={textLink}
                            >
                                Find events
                            </Link>
                            <Link
                                href={create()}
                                aria-current={current(create())}
                                className={limeLink}
                            >
                                Create event
                            </Link>
                            <AccountMenu user={auth.user} />
                        </>
                    ) : (
                        <>
                            <Link
                                href={login()}
                                aria-current={current(login())}
                                className={textLink}
                            >
                                Log in
                            </Link>
                            <Link
                                href={register()}
                                aria-current={current(register())}
                                className={limeLink}
                            >
                                Register
                            </Link>
                        </>
                    )}
                </div>
            </nav>
        </header>
    );
}
