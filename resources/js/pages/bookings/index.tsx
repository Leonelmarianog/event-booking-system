import { Head, Link, setLayoutProps } from '@inertiajs/react';
import { BookingStatusBadge } from '@/components/booking-status-badge';
import { CancelBookingDialog } from '@/components/cancel-booking-dialog';
import { LocalDateTime } from '@/components/local-date-time';
import { index } from '@/routes/bookings';
import { index as eventsIndex, show } from '@/routes/events';
import type { BookingRow, BreadcrumbItem } from '@/types';

function BookingTable({
    title,
    bookings,
}: {
    title: string;
    bookings: BookingRow[];
}) {
    return (
        <section className="flex flex-col gap-3">
            <h2 className="text-lg font-semibold">{title}</h2>
            {bookings.length === 0 ? (
                <p className="text-sm text-muted-foreground">No bookings.</p>
            ) : (
                <div className="overflow-x-auto rounded-md border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-3 py-2 font-medium">Event</th>
                                <th className="px-3 py-2 font-medium">
                                    Starts
                                </th>
                                <th className="px-3 py-2 font-medium">Venue</th>
                                <th className="px-3 py-2 text-right font-medium">
                                    Seats
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Reference
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Status
                                </th>
                                <th className="px-3 py-2">
                                    <span className="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {bookings.map((booking) => (
                                <tr
                                    key={booking.reference}
                                    className="border-t"
                                >
                                    <td className="px-3 py-2">
                                        <Link
                                            href={show(booking.event.id)}
                                            className="font-medium underline-offset-4 hover:underline"
                                        >
                                            {booking.event.title}
                                        </Link>
                                    </td>
                                    <td className="px-3 py-2 whitespace-nowrap">
                                        <LocalDateTime
                                            value={booking.event.starts_at}
                                        />
                                    </td>
                                    <td className="px-3 py-2">
                                        {booking.event.venue}
                                    </td>
                                    <td className="px-3 py-2 text-right">
                                        {booking.quantity}
                                    </td>
                                    <td className="px-3 py-2 font-mono whitespace-nowrap">
                                        {booking.reference}
                                    </td>
                                    <td className="px-3 py-2">
                                        <BookingStatusBadge
                                            status={booking.status}
                                        />
                                    </td>
                                    <td className="px-3 py-2 text-right">
                                        {booking.can_cancel && (
                                            <CancelBookingDialog
                                                reference={booking.reference}
                                                eventTitle={booking.event.title}
                                                triggerLabel="Cancel"
                                            />
                                        )}
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

export default function Bookings({
    upcoming,
    past,
}: {
    upcoming: BookingRow[];
    past: BookingRow[];
}) {
    setLayoutProps<{ breadcrumbs: BreadcrumbItem[] }>({
        breadcrumbs: [{ title: 'My bookings', href: index() }],
    });

    const hasBookings = upcoming.length > 0 || past.length > 0;

    return (
        <>
            <Head title="My bookings" />
            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4">
                <h1 className="text-2xl font-semibold">My bookings</h1>

                {hasBookings ? (
                    <>
                        <BookingTable title="Upcoming" bookings={upcoming} />
                        <BookingTable title="Past" bookings={past} />
                    </>
                ) : (
                    <p className="text-sm">
                        You have no bookings yet.{' '}
                        <Link
                            href={eventsIndex()}
                            className="font-medium underline underline-offset-4"
                        >
                            Browse events
                        </Link>
                    </p>
                )}
            </div>
        </>
    );
}
