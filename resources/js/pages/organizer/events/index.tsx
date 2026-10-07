import { Head, Link, setLayoutProps } from '@inertiajs/react';
import { EventStatusBadge } from '@/components/event-status-badge';
import { LocalDateTime } from '@/components/local-date-time';
import { create, show } from '@/routes/events';
import { index } from '@/routes/organizer/events';
import type { BreadcrumbItem, OrganizerEventRow } from '@/types';

function EventTable({
    title,
    events,
}: {
    title: string;
    events: OrganizerEventRow[];
}) {
    return (
        <section className="flex flex-col gap-3">
            <h2 className="text-lg font-semibold">{title}</h2>
            {events.length === 0 ? (
                <p className="text-sm text-muted-foreground">No events.</p>
            ) : (
                <div className="overflow-x-auto rounded-md border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-3 py-2 font-medium">Title</th>
                                <th className="px-3 py-2 font-medium">
                                    Status
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Starts
                                </th>
                                <th className="px-3 py-2 text-right font-medium">
                                    Seats booked
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {events.map((event) => (
                                <tr key={event.id} className="border-t">
                                    <td className="px-3 py-2">
                                        <Link
                                            href={show(event.id)}
                                            className="font-medium underline-offset-4 hover:underline"
                                        >
                                            {event.title}
                                        </Link>
                                    </td>
                                    <td className="px-3 py-2">
                                        <EventStatusBadge
                                            status={event.status}
                                        />
                                    </td>
                                    <td className="px-3 py-2 whitespace-nowrap">
                                        <LocalDateTime
                                            value={event.starts_at}
                                        />
                                    </td>
                                    <td className="px-3 py-2 text-right whitespace-nowrap">
                                        {event.seats_booked} / {event.capacity}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}

export default function OrganizerEvents({
    upcoming,
    past,
}: {
    upcoming: OrganizerEventRow[];
    past: OrganizerEventRow[];
}) {
    setLayoutProps<{ breadcrumbs: BreadcrumbItem[] }>({
        breadcrumbs: [{ title: 'My events', href: index() }],
    });

    const hasEvents = upcoming.length > 0 || past.length > 0;

    return (
        <>
            <Head title="My events" />
            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4">
                <h1 className="text-2xl font-semibold">My events</h1>

                {hasEvents ? (
                    <>
                        <EventTable title="Upcoming" events={upcoming} />
                        <EventTable title="Past" events={past} />
                    </>
                ) : (
                    <p className="text-sm">
                        You have no events yet.{' '}
                        <Link
                            href={create()}
                            className="font-medium underline underline-offset-4"
                        >
                            Create an event
                        </Link>
                    </p>
                )}
            </div>
        </>
    );
}
