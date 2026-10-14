import { Form, Link, usePage } from '@inertiajs/react';
import { Children, useState } from 'react';
import type { ComponentProps, ReactNode } from 'react';
import { CancelBookingDialog } from '@/components/cancel-booking-dialog';
import { CancelEventDialog } from '@/components/cancel-event-dialog';
import { DeleteEventDialog } from '@/components/delete-event-dialog';
import { FieldLabel } from '@/components/field-label';
import InputError from '@/components/input-error';
import { PublishEventDialog } from '@/components/publish-event-dialog';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { login, register } from '@/routes';
import { index as bookings } from '@/routes/bookings';
import { edit, index as events } from '@/routes/events';
import { index as attendees } from '@/routes/events/attendees';
import { store } from '@/routes/events/bookings';
import type { EventBookingBox, EventDetails, EventPermissions } from '@/types';

type Viewer = 'manager' | 'attendee' | 'user' | 'guest';
type BoxState = 'draft' | 'cancelled' | 'past' | 'sold_out' | 'published';
type Booked = Extract<EventBookingBox, { state: 'booked' }>;

const badges: Record<
    Exclude<BoxState, 'published'>,
    { label: string; className: string }
> = {
    sold_out: { label: 'Sold out', className: 'bg-orange text-ink' },
    cancelled: { label: 'Cancelled', className: 'bg-orange text-ink' },
    past: { label: 'Ended', className: 'bg-[#2b2b30] text-[#f2f0eb]' },
    draft: { label: 'Draft', className: 'bg-violet text-ink' },
};

const boxButton = 'h-[52px] w-full text-[15px] tracking-[0.08em]';
const secondaryButton = cn(boxButton, 'border-input');
const dangerButton = cn(
    boxButton,
    'border-destructive text-destructive-foreground hover:text-destructive-foreground',
);

function seats(count: number): string {
    return `${count} ${count === 1 ? 'seat' : 'seats'}`;
}

function viewerOf(
    booking: EventBookingBox | null,
    can: EventPermissions,
    isOrganizer: boolean,
    isLoggedIn: boolean,
): Viewer {
    if (
        isOrganizer ||
        can.update ||
        can.publish ||
        can.cancel ||
        can.delete ||
        can.viewAttendees
    ) {
        return 'manager';
    }

    if (booking?.state === 'booked') {
        return 'attendee';
    }

    return isLoggedIn ? 'user' : 'guest';
}

function stateOf(
    event: EventDetails,
    booking: EventBookingBox | null,
): BoxState {
    if (event.status === 'cancelled') {
        return 'cancelled';
    }

    if (event.status === 'draft') {
        return 'draft';
    }

    if (event.has_started) {
        return 'past';
    }

    if (booking?.state === 'sold_out' || event.seats_available === 0) {
        return 'sold_out';
    }

    return 'published';
}

function heading(viewer: Viewer, state: BoxState): string {
    if (viewer === 'manager') {
        return 'Manage event';
    }

    if (viewer === 'attendee') {
        return state === 'past' ? 'Your booking' : 'You’re going';
    }

    return 'Book seats';
}

function Message({ children }: { children: ReactNode }) {
    return <p className="mt-5 dark:text-[#d6d4cc]">{children}</p>;
}

function Note({ children }: { children: ReactNode }) {
    return (
        <p className="mt-4 text-sm text-muted-foreground dark:text-[#b9b7ae]">
            {children}
        </p>
    );
}

/** The column of box buttons. It renders nothing when no button shows. */
function Actions({ children }: { children: ReactNode }) {
    if (Children.toArray(children).length === 0) {
        return null;
    }

    return <div className="mt-6 flex flex-col gap-3">{children}</div>;
}

function LinkButton({
    href,
    primary = false,
    children,
}: {
    href: ComponentProps<typeof Link>['href'];
    primary?: boolean;
    children: ReactNode;
}) {
    return (
        <Button
            asChild
            size="lg"
            variant={primary ? 'default' : 'outline'}
            className={primary ? boxButton : secondaryButton}
        >
            <Link href={href}>{children}</Link>
        </Button>
    );
}

/**
 * "42 seats left", "86% booked" and a bar. The percent never says 100 while seats
 * are left.
 */
function SeatMeter({ event }: { event: EventDetails }) {
    const left = event.seats_available;
    const soldOut = left === 0;
    const booked =
        event.capacity > 0
            ? Math.round(((event.capacity - left) / event.capacity) * 100)
            : 100;
    const percent = soldOut ? 100 : Math.min(booked, 99);

    return (
        <div className="mt-5">
            <p className="mb-2 flex justify-between gap-4 text-[15px]">
                <strong>
                    {soldOut ? 'No seats left' : `${seats(left)} left`}
                </strong>
                <span className="text-muted-foreground dark:text-[#b9b7ae]">
                    {percent}% booked
                </span>
            </p>
            <div
                aria-hidden="true"
                className="h-1.5 bg-border dark:bg-[#2b2b30]"
            >
                <div
                    className={cn('h-1.5', soldOut ? 'bg-orange' : 'bg-lime')}
                    style={{ width: `${percent}%` }}
                />
            </div>
        </div>
    );
}

/**
 * The seats select and the reserve button. The chosen number never goes above
 * maxQuantity, also when maxQuantity gets smaller after a server error.
 */
function ReserveForm({
    eventId,
    maxQuantity,
}: {
    eventId: number;
    maxQuantity: number;
}) {
    const [quantity, setQuantity] = useState(1);
    const selected = Math.min(quantity, maxQuantity);

    return (
        <Form
            {...store.form(eventId)}
            options={{ preserveScroll: true }}
            className="mt-6 flex flex-col gap-4"
        >
            {({ processing, errors }) => (
                <>
                    <FieldLabel htmlFor="quantity">
                        How many seats?{' '}
                        <span className="font-normal tracking-normal normal-case">
                            (up to {maxQuantity})
                        </span>
                    </FieldLabel>
                    <select
                        id="quantity"
                        name="quantity"
                        value={selected}
                        onChange={(e) => setQuantity(Number(e.target.value))}
                        className="h-[52px] w-full border border-input bg-background px-4 text-[17px] outline-none focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                    >
                        {Array.from(
                            { length: maxQuantity },
                            (_, i) => i + 1,
                        ).map((n) => (
                            <option key={n} value={n}>
                                {seats(n)}
                            </option>
                        ))}
                    </select>
                    <Button
                        type="submit"
                        size="lg"
                        disabled={processing}
                        className={boxButton}
                    >
                        Reserve {seats(selected)}
                    </Button>
                    <InputError message={errors.quantity} />
                </>
            )}
        </Form>
    );
}

function ManagerBox({
    event,
    can,
    isOrganizer,
    state,
}: {
    event: EventDetails;
    can: EventPermissions;
    isOrganizer: boolean;
    state: BoxState;
}) {
    if (state === 'cancelled') {
        return (
            <Message>
                This event was cancelled. All bookings were cancelled and
                everyone who booked got an email.
            </Message>
        );
    }

    if (state === 'past') {
        const booked = event.capacity - event.seats_available;

        return (
            <>
                <Message>
                    {seats(booked)} {booked === 1 ? 'was' : 'were'} booked for
                    this event.
                </Message>
                <Actions>
                    {can.viewAttendees && (
                        <LinkButton href={attendees(event.id)}>
                            Attendees
                        </LinkButton>
                    )}
                </Actions>
            </>
        );
    }

    if (state === 'draft') {
        return (
            <>
                <Message>
                    {isOrganizer
                        ? 'Only you can see this draft. Publish it when it is ready.'
                        : 'This draft is not public yet.'}
                </Message>
                <Actions>
                    {can.publish && (
                        <PublishEventDialog eventId={event.id}>
                            <Button size="lg" className={boxButton}>
                                Publish
                            </Button>
                        </PublishEventDialog>
                    )}
                    {can.update && (
                        <LinkButton href={edit(event.id)}>
                            Edit event
                        </LinkButton>
                    )}
                    {can.delete && (
                        <DeleteEventDialog eventId={event.id}>
                            <Button
                                size="lg"
                                variant="outline"
                                className={dangerButton}
                            >
                                Delete draft
                            </Button>
                        </DeleteEventDialog>
                    )}
                </Actions>
            </>
        );
    }

    return (
        <>
            <SeatMeter event={event} />
            <Actions>
                {can.update && (
                    <LinkButton href={edit(event.id)}>Edit event</LinkButton>
                )}
                {can.viewAttendees && (
                    <LinkButton href={attendees(event.id)}>
                        Attendees
                    </LinkButton>
                )}
                {can.cancel && (
                    <CancelEventDialog eventId={event.id}>
                        <Button
                            size="lg"
                            variant="outline"
                            className={dangerButton}
                        >
                            Cancel event
                        </Button>
                    </CancelEventDialog>
                )}
            </Actions>
            {isOrganizer && (
                <Note>
                    You organize this event, so you cannot book seats for it.
                </Note>
            )}
        </>
    );
}

function AttendeeBox({
    event,
    booking,
    state,
}: {
    event: EventDetails;
    booking: Booked;
    state: BoxState;
}) {
    return (
        <>
            <dl className="mt-5 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-[15px]">
                <dt className="text-muted-foreground">Seats</dt>
                <dd className="font-semibold">{booking.quantity}</dd>
                <dt className="text-muted-foreground">Reference</dt>
                <dd className="min-w-0 font-mono text-[13px] break-all">
                    {booking.reference}
                </dd>
            </dl>
            {state === 'past' ? (
                <Message>This event has ended.</Message>
            ) : (
                <>
                    <Actions>
                        {booking.can_cancel && (
                            <CancelBookingDialog
                                reference={booking.reference}
                                eventTitle={event.title}
                            >
                                <Button
                                    size="lg"
                                    variant="outline"
                                    className={dangerButton}
                                >
                                    Cancel booking
                                </Button>
                            </CancelBookingDialog>
                        )}
                        <LinkButton href={bookings()}>My bookings</LinkButton>
                    </Actions>
                    <Note>
                        You can cancel any time before the event starts.
                    </Note>
                </>
            )}
        </>
    );
}

function BookBox({
    event,
    booking,
    state,
}: {
    event: EventDetails;
    booking: EventBookingBox | null;
    state: BoxState;
}) {
    const findOther = (
        <Actions>
            <LinkButton href={events()}>Find other events</LinkButton>
        </Actions>
    );

    if (state === 'draft') {
        return null;
    }

    if (state === 'sold_out') {
        return (
            <>
                <SeatMeter event={event} />
                <Message>All seats are taken.</Message>
                {findOther}
            </>
        );
    }

    if (state === 'past') {
        return (
            <>
                <Message>This event has ended.</Message>
                {findOther}
            </>
        );
    }

    if (state === 'cancelled') {
        return (
            <>
                <Message>
                    The organizer cancelled this event. All bookings were
                    cancelled.
                </Message>
                {findOther}
            </>
        );
    }

    return (
        <>
            <SeatMeter event={event} />
            {booking?.state === 'available' && (
                <ReserveForm
                    eventId={event.id}
                    maxQuantity={booking.max_quantity}
                />
            )}
            {booking?.state === 'login' && (
                <Actions>
                    <LinkButton href={login()} primary>
                        Log in to book
                    </LinkButton>
                    <LinkButton href={register()}>Register</LinkButton>
                </Actions>
            )}
            <Note>
                Free entry. You can cancel any time before the event starts.
            </Note>
        </>
    );
}

/**
 * The box on the right of the event page. It shows what the viewer (manager,
 * attendee, user or guest) can do for the state of the event (draft, cancelled,
 * past, sold out or published). Each button shows only when its `can` flag is true.
 */
export function EventBox({
    event,
    booking,
    can,
    isOrganizer,
}: {
    event: EventDetails;
    booking: EventBookingBox | null;
    can: EventPermissions;
    isOrganizer: boolean;
}) {
    const { auth } = usePage().props;
    const viewer = viewerOf(booking, can, isOrganizer, Boolean(auth.user));
    const state = stateOf(event, booking);
    const badge = state === 'published' ? null : badges[state];

    return (
        <section
            id="book"
            tabIndex={-1}
            aria-labelledby="event-box-heading"
            className="border bg-field p-8 outline-none focus-visible:ring-[3px] focus-visible:ring-ring"
        >
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2
                    id="event-box-heading"
                    className="font-display text-[32px] leading-[1.05] font-normal uppercase"
                >
                    {heading(viewer, state)}
                </h2>
                {badge && (
                    <span
                        className={cn(
                            'inline-flex px-2.5 py-1 text-xs font-bold tracking-[0.1em] uppercase',
                            badge.className,
                        )}
                    >
                        {badge.label}
                    </span>
                )}
            </div>
            {viewer === 'manager' && (
                <ManagerBox
                    event={event}
                    can={can}
                    isOrganizer={isOrganizer}
                    state={state}
                />
            )}
            {viewer === 'manager' &&
                !isOrganizer &&
                (state === 'published' || state === 'sold_out') && (
                    <div className="mt-6 border-t">
                        {booking?.state === 'available' && (
                            <ReserveForm
                                eventId={event.id}
                                maxQuantity={booking.max_quantity}
                            />
                        )}
                        {booking?.state === 'booked' && (
                            <AttendeeBox
                                event={event}
                                booking={booking}
                                state={state}
                            />
                        )}
                    </div>
                )}
            {viewer === 'attendee' && booking?.state === 'booked' && (
                <AttendeeBox event={event} booking={booking} state={state} />
            )}
            {(viewer === 'user' || viewer === 'guest') && (
                <BookBox event={event} booking={booking} state={state} />
            )}
        </section>
    );
}
