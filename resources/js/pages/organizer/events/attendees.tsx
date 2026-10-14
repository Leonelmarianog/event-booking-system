import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { BackLink } from '@/components/back-link';
import { LocalDateTime } from '@/components/local-date-time';
import { PaginationNav } from '@/components/pagination-nav';
import { SkipLink } from '@/components/skip-link';
import { show } from '@/routes/events';
import type { AttendeeRow, Paginated } from '@/types';

type AttendeeEvent = {
    id: number;
    title: string;
    venue: string;
    starts_at: string;
    capacity: number;
    seats_booked: number;
};

const shortDateTime: Intl.DateTimeFormatOptions = {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
};

function Stat({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="bg-background px-6 py-5">
            <dt className="text-[13px] font-semibold tracking-[0.14em] text-muted-foreground uppercase">
                {label}
            </dt>
            <dd className="mt-1 font-display text-5xl leading-none">
                {children}
            </dd>
        </div>
    );
}

const headCell = 'px-5 py-3.5 font-semibold';
const bodyCell = 'px-5 py-3.5';

export default function EventAttendees({
    event,
    attendees,
}: {
    event: AttendeeEvent;
    attendees: Paginated<AttendeeRow>;
}) {
    const seatsLeft = Math.max(0, event.capacity - event.seats_booked);

    return (
        <>
            <Head title={`Attendees of ${event.title}`} />
            <SkipLink href="#attendees-table">Skip to attendees</SkipLink>
            <div className="flex min-w-0 flex-col px-8 pt-14 pb-22">
                <BackLink href={show(event.id)}>Back to event</BackLink>
                <h1 className="mt-2 mb-3 font-display text-[clamp(56px,8vw,112px)] leading-[0.9] uppercase">
                    Attendees
                </h1>
                <p className="mb-8 text-lg break-words text-muted-foreground dark:text-[#b9b7ae]">
                    <strong className="text-foreground">{event.title}</strong>
                    {' · '}
                    <LocalDateTime
                        value={event.starts_at}
                        options={shortDateTime}
                    />
                    {' · '}
                    {event.venue}
                </p>

                <dl className="mb-10 grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-px border bg-border">
                    <Stat label="Bookings">{attendees.total}</Stat>
                    <Stat label="Seats booked">
                        {event.seats_booked}{' '}
                        <span className="text-2xl text-muted-foreground">
                            / {event.capacity}
                        </span>
                    </Stat>
                    <Stat label="Seats left">{seatsLeft}</Stat>
                </dl>

                <div
                    id="attendees-table"
                    tabIndex={-1}
                    className="overflow-x-auto border outline-none"
                >
                    {attendees.data.length === 0 ? (
                        <p className="px-5 py-6 text-muted-foreground">
                            {attendees.total === 0
                                ? 'No attendees yet.'
                                : 'No attendees on this page.'}
                        </p>
                    ) : (
                        <table className="w-full min-w-[900px] border-collapse text-[15px]">
                            <caption className="sr-only">
                                Confirmed bookings, page{' '}
                                {attendees.current_page} of{' '}
                                {attendees.last_page}
                            </caption>
                            <thead>
                                <tr className="bg-field text-left text-xs tracking-[0.14em] text-muted-foreground uppercase">
                                    <th scope="col" className={headCell}>
                                        Name
                                    </th>
                                    <th scope="col" className={headCell}>
                                        Email
                                    </th>
                                    <th
                                        scope="col"
                                        className={`${headCell} text-right`}
                                    >
                                        Seats
                                    </th>
                                    <th scope="col" className={headCell}>
                                        Reference
                                    </th>
                                    <th scope="col" className={headCell}>
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
                                        <th
                                            scope="row"
                                            className={`${bodyCell} text-left font-semibold`}
                                        >
                                            {attendee.name}
                                        </th>
                                        <td
                                            className={`${bodyCell} text-muted-foreground dark:text-[#b9b7ae]`}
                                        >
                                            {attendee.email}
                                        </td>
                                        <td
                                            className={`${bodyCell} text-right`}
                                        >
                                            {attendee.quantity}
                                        </td>
                                        <td
                                            className={`${bodyCell} font-mono text-[13px] whitespace-nowrap text-muted-foreground dark:text-[#b9b7ae]`}
                                        >
                                            {attendee.reference}
                                        </td>
                                        <td
                                            className={`${bodyCell} whitespace-nowrap text-muted-foreground dark:text-[#b9b7ae]`}
                                        >
                                            <LocalDateTime
                                                value={attendee.booked_at}
                                                options={shortDateTime}
                                            />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>

                <PaginationNav paginator={attendees} className="mt-6" />
            </div>
        </>
    );
}
