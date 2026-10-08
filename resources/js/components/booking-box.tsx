import { Form, Link } from '@inertiajs/react';
import { Ticket } from 'lucide-react';
import { CancelBookingDialog } from '@/components/cancel-booking-dialog';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { login } from '@/routes';
import { store } from '@/routes/events/bookings';
import type { EventBookingBox } from '@/types';

/**
 * The booking part of the event page: the form, the booking of the user, a login link
 * or "Sold out".
 */
export function BookingBox({
    eventId,
    eventTitle,
    booking,
}: {
    eventId: number;
    eventTitle: string;
    booking: EventBookingBox;
}) {
    return (
        <section aria-label="Booking" className="rounded-lg border p-4 text-sm">
            {booking.state === 'booked' && (
                <div className="flex flex-col items-start gap-3">
                    <p>
                        You booked {booking.quantity}{' '}
                        {booking.quantity === 1 ? 'seat' : 'seats'}. Reference:{' '}
                        <span className="font-mono break-all">
                            {booking.reference}
                        </span>
                    </p>
                    {booking.can_cancel && (
                        <CancelBookingDialog
                            reference={booking.reference}
                            eventTitle={eventTitle}
                            triggerLabel="Cancel booking"
                        />
                    )}
                </div>
            )}

            {booking.state === 'available' && (
                <Form
                    {...store.form(eventId)}
                    options={{ preserveScroll: true }}
                    className="flex flex-wrap items-end gap-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="quantity">Seats</Label>
                                <Input
                                    id="quantity"
                                    name="quantity"
                                    type="number"
                                    required
                                    min={1}
                                    max={booking.max_quantity}
                                    defaultValue={1}
                                    className="w-24"
                                />
                            </div>
                            <Button type="submit" disabled={processing}>
                                <Ticket />
                                Book seats
                            </Button>
                            <InputError
                                message={errors.quantity}
                                className="basis-full"
                            />
                        </>
                    )}
                </Form>
            )}

            {booking.state === 'login' && (
                <p>
                    <Link href={login()} className="underline">
                        Log in
                    </Link>{' '}
                    to book seats.
                </p>
            )}

            {booking.state === 'sold_out' && (
                <p className="font-medium">Sold out</p>
            )}
        </section>
    );
}
