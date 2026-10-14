import { Head, Link } from '@inertiajs/react';
import { Pencil, Users } from 'lucide-react';
import type { ReactNode } from 'react';
import { BookingBox } from '@/components/booking-box';
import { CancelEventDialog } from '@/components/cancel-event-dialog';
import { DeleteEventDialog } from '@/components/delete-event-dialog';
import { EventBanner } from '@/components/event-banner';
import { EventHeader } from '@/components/event-header';
import { LocalDateTime } from '@/components/local-date-time';
import { PublishEventDialog } from '@/components/publish-event-dialog';
import { Button } from '@/components/ui/button';
import { edit } from '@/routes/events';
import { index as attendees } from '@/routes/events/attendees';
import type { EventBookingBox, EventDetails, EventPermissions } from '@/types';

const shortDateTime: Intl.DateTimeFormatOptions = {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
};

function Detail({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="min-w-0 bg-background px-6 py-5">
            <dt className="text-[13px] font-semibold tracking-[0.14em] text-muted-foreground uppercase">
                {label}
            </dt>
            <dd className="mt-1.5 text-lg font-semibold break-words">
                {children}
            </dd>
        </div>
    );
}

export default function ShowEvent({
    event,
    booking,
    can,
    is_organizer,
}: {
    event: EventDetails;
    booking: EventBookingBox | null;
    can: EventPermissions;
    is_organizer: boolean;
}) {
    const manages =
        can.publish ||
        can.update ||
        can.cancel ||
        can.delete ||
        can.viewAttendees;

    return (
        <>
            <Head title={event.title} />
            <EventHeader event={event} />
            <EventBanner event={event} isOrganizer={is_organizer} />
            <div className="flex flex-col gap-14 px-8 pt-14 pb-24 lg:flex-row lg:items-start">
                <article className="min-w-0 flex-1">
                    <dl className="mb-10 grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-px border bg-border">
                        <Detail label="When">
                            <LocalDateTime
                                value={event.starts_at}
                                options={shortDateTime}
                            />
                        </Detail>
                        <Detail label="Where">{event.venue}</Detail>
                        <Detail label="Organizer">
                            {event.organizer_name}
                        </Detail>
                    </dl>
                    <h2 className="mb-4 font-display text-[32px] font-normal uppercase">
                        About
                    </h2>
                    <p className="max-w-[640px] text-xl leading-[1.6] break-words whitespace-pre-line dark:text-[#d6d4cc]">
                        {event.description}
                    </p>
                </article>
                <div className="flex flex-col gap-4 lg:sticky lg:top-6 lg:w-[400px] lg:shrink-0">
                    {manages && (
                        <div className="flex flex-wrap gap-2">
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
                    {booking && (
                        <BookingBox
                            eventId={event.id}
                            eventTitle={event.title}
                            booking={booking}
                        />
                    )}
                </div>
            </div>
        </>
    );
}
