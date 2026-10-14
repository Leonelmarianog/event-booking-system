import { Form } from '@inertiajs/react';
import type { ReactNode } from 'react';
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
 * Asks for a confirmation and then cancels the booking. The children are the button
 * that opens the dialog. The dialog closes when the request ends, also when the
 * server refuses with an error toast.
 */
export function CancelBookingDialog({
    reference,
    eventTitle,
    children,
}: {
    reference: string;
    eventTitle: string;
    children: ReactNode;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{children}</DialogTrigger>
            <DialogContent>
                <DialogTitle>Cancel this booking?</DialogTitle>
                <DialogDescription>
                    Your booking for{' '}
                    <strong className="text-foreground">{eventTitle}</strong>{' '}
                    (reference{' '}
                    <span className="font-mono text-sm break-all">
                        {reference}
                    </span>
                    ) ends, and your seats go back to the event. You can book
                    again while seats are available.
                </DialogDescription>
                <Form
                    {...store.form(reference)}
                    options={{ preserveScroll: true }}
                    onFinish={() => setOpen(false)}
                >
                    {({ processing }) => (
                        <DialogFooter className="mt-5">
                            <DialogClose asChild>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="md"
                                >
                                    Keep booking
                                </Button>
                            </DialogClose>
                            <Button
                                type="submit"
                                variant="destructive"
                                size="md"
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
