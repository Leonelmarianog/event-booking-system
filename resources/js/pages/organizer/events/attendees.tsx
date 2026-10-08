import { Head, Link, setLayoutProps } from '@inertiajs/react';
import { LocalDateTime } from '@/components/local-date-time';
import { PaginationNav } from '@/components/pagination-nav';
import { index } from '@/routes/events/attendees';
import { show } from '@/routes/events';
import type { AttendeeRow, BreadcrumbItem, Paginated } from '@/types';

type AttendeeEvent = {
    id: number;
    title: string;
    starts_at: string;
    capacity: number;
    seats_booked: number;
};

export default function EventAttendees({
    event,
    attendees,
}: {
    event: AttendeeEvent;
    attendees: Paginated<AttendeeRow>;
}) {
    setLayoutProps<{ breadcrumbs: BreadcrumbItem[] }>({
        breadcrumbs: [
            { title: event.title, href: show(event.id) },
            { title: 'Attendees', href: index(event.id) },
        ],
    });

    return (
        <>
            <Head title={`Attendees of ${event.title}`} />
            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4">
                <div className="flex flex-col gap-1">
                    <h1 className="text-2xl font-semibold break-words">
                        Attendees
                    </h1>
                    <p className="text-sm">
                        <Link
                            href={show(event.id)}
                            className="font-medium underline-offset-4 hover:underline"
                        >
                            {event.title}
                        </Link>
                        {' · '}
                        <LocalDateTime value={event.starts_at} />
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {attendees.total}{' '}
                        {attendees.total === 1 ? 'attendee' : 'attendees'} ·{' '}
                        {event.seats_booked} of {event.capacity} seats booked
                    </p>
                </div>

                {attendees.data.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No attendees yet.
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-md border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="px-3 py-2 font-medium">
                                        Name
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Email
                                    </th>
                                    <th className="px-3 py-2 text-right font-medium">
                                        Seats
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Reference
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Booked at
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {attendees.data.map((attendee) => (
                                    <tr
                                        key={attendee.reference}
                                        className="border-t"
                                    >
                                        <td className="px-3 py-2">
                                            {attendee.name}
                                        </td>
                                        <td className="px-3 py-2">
                                            {attendee.email}
                                        </td>
                                        <td className="px-3 py-2 text-right">
                                            {attendee.quantity}
                                        </td>
                                        <td className="px-3 py-2 font-mono whitespace-nowrap">
                                            {attendee.reference}
                                        </td>
                                        <td className="px-3 py-2 whitespace-nowrap">
                                            <LocalDateTime
                                                value={attendee.booked_at}
                                            />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <PaginationNav paginator={attendees} />
            </div>
        </>
    );
}
