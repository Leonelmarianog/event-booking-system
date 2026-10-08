import { Head, Link, setLayoutProps } from '@inertiajs/react';
import { CalendarDays, MapPin, Pencil, User, Users } from 'lucide-react';
import { BookingBox } from '@/components/booking-box';
import { CancelEventDialog } from '@/components/cancel-event-dialog';
import { DeleteEventDialog } from '@/components/delete-event-dialog';
import { LocalDateTime } from '@/components/local-date-time';
import { PublishEventDialog } from '@/components/publish-event-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { edit, show } from '@/routes/events';
import { index as attendees } from '@/routes/events/attendees';
import type { BreadcrumbItem, EventBookingBox, EventDetails } from '@/types';

export default function ShowEvent({
    event,
    booking,
    can,
}: {
    event: EventDetails;
    booking: EventBookingBox | null;
    can: {
        update: boolean;
        publish: boolean;
        cancel: boolean;
        delete: boolean;
        viewAttendees: boolean;
    };
}) {
    setLayoutProps<{ breadcrumbs: BreadcrumbItem[] }>({
        breadcrumbs: [{ title: event.title, href: show(event.id) }],
    });

    return (
        <>
            <Head title={event.title} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:max-w-3xl">
                <div className="flex flex-wrap items-center gap-3">
                    <h1 className="text-2xl font-semibold break-words">
                        {event.title}
                    </h1>
                    {event.status === 'draft' && (
                        <Badge variant="outline">Draft</Badge>
                    )}
                    {event.status === 'cancelled' && (
                        <Badge variant="destructive">Cancelled</Badge>
                    )}
                    {(can.publish ||
                        can.update ||
                        can.cancel ||
                        can.delete ||
                        can.viewAttendees) && (
                        <div className="ml-auto flex flex-wrap gap-2">
                            {can.viewAttendees && (
                                <Button asChild variant="outline" size="sm">
                                    <Link href={attendees(event.id)}>
                                        <Users />
                                        Attendees
                                    </Link>
                                </Button>
                            )}
                            {can.update && (
                                <Button asChild variant="outline" size="sm">
                                    <Link href={edit(event.id)}>
                                        <Pencil />
                                        Edit
                                    </Link>
                                </Button>
                            )}
                            {can.publish && (
                                <PublishEventDialog eventId={event.id} />
                            )}
                            {can.cancel && (
                                <CancelEventDialog eventId={event.id} />
                            )}
                            {can.delete && (
                                <DeleteEventDialog eventId={event.id} />
                            )}
                        </div>
                    )}
                </div>

                <dl className="grid gap-3 text-sm">
                    <div className="flex items-center gap-2">
                        <CalendarDays className="size-4 text-muted-foreground" />
                        <dt className="sr-only">Starts</dt>
                        <dd>
                            <LocalDateTime value={event.starts_at} />
                        </dd>
                    </div>
                    <div className="flex items-center gap-2">
                        <MapPin className="size-4 text-muted-foreground" />
                        <dt className="sr-only">Venue</dt>
                        <dd>{event.venue}</dd>
                    </div>
                    <div className="flex items-center gap-2">
                        <User className="size-4 text-muted-foreground" />
                        <dt className="sr-only">Organizer</dt>
                        <dd>{event.organizer_name}</dd>
                    </div>
                    <div className="flex items-center gap-2">
                        <Users className="size-4 text-muted-foreground" />
                        <dt className="sr-only">Seats</dt>
                        <dd>
                            {event.seats_available} of {event.capacity} seats
                            available
                        </dd>
                    </div>
                </dl>

                {booking && (
                    <BookingBox
                        eventId={event.id}
                        eventTitle={event.title}
                        booking={booking}
                    />
                )}

                <p className="break-words whitespace-pre-line">
                    {event.description}
                </p>
            </div>
        </>
    );
}
