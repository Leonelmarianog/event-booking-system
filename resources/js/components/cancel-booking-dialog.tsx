import { Form } from '@inertiajs/react';
import { X } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { store } from '@/routes/bookings/cancellation';

/**
 * A button that asks for a confirmation and then cancels the booking. The dialog
 * closes when the request ends, also when the server refuses with an error toast.
 */
export function CancelBookingDialog({
    reference,
    eventTitle,
    triggerLabel,
}: {
    reference: string;
    eventTitle: string;
    triggerLabel: string;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    size="sm"
                    variant="outline"
                    aria-label={`Cancel booking for ${eventTitle}`}
                >
                    <X />
                    {triggerLabel}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Cancel this booking?</DialogTitle>
                <DialogDescription>
                    Your booking for {eventTitle} (reference {reference}) ends,
                    and your seats go back to the event. You can book again
                    while seats are available.
                </DialogDescription>
                <Form
                    {...store.form(reference)}
                    options={{ preserveScroll: true }}
                    onFinish={() => setOpen(false)}
                >
                    {({ processing }) => (
                        <DialogFooter className="gap-2">
                            <DialogClose asChild>
                                <Button type="button" variant="secondary">
                                    Keep booking
                                </Button>
                            </DialogClose>
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={processing}
                            >
                                Cancel booking
                            </Button>
                        </DialogFooter>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
