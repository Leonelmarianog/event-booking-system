import { Link, router } from '@inertiajs/react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useInitials } from '@/hooks/use-initials';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { logout } from '@/routes';
import { index as bookingsIndex } from '@/routes/bookings';
import { index as organizerEvents } from '@/routes/organizer/events';
import { edit as profileEdit } from '@/routes/profile';
import type { User } from '@/types';

const links = [
    { title: 'My bookings', href: bookingsIndex() },
    { title: 'My events', href: organizerEvents() },
    { title: 'Settings', href: profileEdit() },
];

const itemClass = 'min-h-11 cursor-pointer px-4.5 text-[15px] font-medium';

export function AccountMenu({ user }: { user: User }) {
    const getInitials = useInitials();
    const { isCurrentUrl } = useCurrentUrl();
    const cleanup = useMobileNavigation();

    const handleLogout = () => {
        cleanup();
        router.flushAll();
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    aria-label={`Account menu for ${user.name}`}
                    className="size-11 cursor-pointer rounded-full border-2 border-input bg-secondary text-[15px] font-bold text-foreground outline-none focus-visible:ring-[3px] focus-visible:ring-ring data-[state=open]:border-primary"
                >
                    {getInitials(user.name)}
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="end"
                sideOffset={12}
                className="w-65 p-0"
            >
                <DropdownMenuLabel className="px-4.5 py-4 font-normal">
                    <span className="block truncate text-[15px] font-bold">
                        {user.name}
                    </span>
                    <span className="block truncate text-sm text-muted-foreground">
                        {user.email}
                    </span>
                </DropdownMenuLabel>
                <DropdownMenuSeparator className="m-0" />
                <DropdownMenuGroup className="py-2">
                    {links.map((link) => (
                        <DropdownMenuItem
                            key={link.title}
                            asChild
                            className={itemClass}
                        >
                            <Link
                                href={link.href}
                                prefetch
                                onClick={cleanup}
                                aria-current={
                                    isCurrentUrl(link.href) ? 'page' : undefined
                                }
                            >
                                {link.title}
                            </Link>
                        </DropdownMenuItem>
                    ))}
                </DropdownMenuGroup>
                <DropdownMenuSeparator className="m-0" />
                <div className="py-2">
                    <DropdownMenuItem asChild className={itemClass}>
                        <Link
                            href={logout()}
                            as="button"
                            onClick={handleLogout}
                            data-test="logout-button"
                            className="w-full"
                        >
                            Log out
                        </Link>
                    </DropdownMenuItem>
                </div>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
