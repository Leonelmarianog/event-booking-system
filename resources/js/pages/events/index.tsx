import { Head, Link, setLayoutProps } from '@inertiajs/react';
import { CalendarDays, MapPin } from 'lucide-react';
import { LocalDateTime } from '@/components/local-date-time';
import { PaginationNav } from '@/components/pagination-nav';
import { Badge } from '@/components/ui/badge';
import { index, show } from '@/routes/events';
import type { BreadcrumbItem, Paginated, UpcomingEvent } from '@/types';

export default function UpcomingEvents({
    events,
}: {
    events: Paginated<UpcomingEvent>;
}) {
    setLayoutProps<{ breadcrumbs: BreadcrumbItem[] }>({
        breadcrumbs: [{ title: 'Events', href: index() }],
    });

    return (
        <>
            <Head title="Events" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <h1 className="text-2xl font-semibold">Upcoming events</h1>

                {events.data.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No upcoming events.
                    </p>
                ) : (
                    <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {events.data.map((event) => (
                            <li
                                key={event.id}
                                className="flex flex-col gap-3 rounded-lg border p-4"
                            >
                                <Link
                                    href={show(event.id)}
                                    className="text-lg font-semibold break-words underline-offset-4 hover:underline"
                                >
                                    {event.title}
                                </Link>
                                <div className="flex items-center gap-2 text-sm">
                                    <CalendarDays className="size-4 shrink-0 text-muted-foreground" />
                                    <LocalDateTime value={event.starts_at} />
                                </div>
                                <div className="flex items-center gap-2 text-sm">
                                    <MapPin className="size-4 shrink-0 text-muted-foreground" />
                                    <span className="break-words">
                                        {event.venue}
                                    </span>
                                </div>
                                <div className="mt-auto">
                                    {event.seats_available === 0 ? (
                                        <Badge variant="destructive">
                                            Sold out
                                        </Badge>
                                    ) : (
                                        <span className="text-sm text-muted-foreground">
                                            {event.seats_available}{' '}
                                            {event.seats_available === 1
                                                ? 'seat'
                                                : 'seats'}{' '}
                                            left
                                        </span>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}

                <PaginationNav paginator={events} />
            </div>
        </>
    );
}
