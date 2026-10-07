import { Head, Link, setLayoutProps } from '@inertiajs/react';
import { CalendarDays, MapPin, Pencil, User, Users } from 'lucide-react';
import { LocalDateTime } from '@/components/local-date-time';
import { PublishEventDialog } from '@/components/publish-event-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { edit, show } from '@/routes/events';
import type { BreadcrumbItem, EventDetails } from '@/types';

export default function ShowEvent({
    event,
    can,
}: {
    event: EventDetails;
    can: { update: boolean; publish: boolean };
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
                    {(can.publish || can.update) && (
                        <div className="ml-auto flex gap-2">
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

                <p className="break-words whitespace-pre-line">
                    {event.description}
                </p>
            </div>
        </>
    );
}
