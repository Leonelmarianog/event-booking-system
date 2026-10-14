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
import { store } from '@/routes/events/cancellation';

/**
 * Asks for a confirmation and then cancels the event and its bookings. The children
 * are the button that opens the dialog. The dialog closes when the request ends, also
 * when the server refuses with an error toast.
 */
export function CancelEventDialog({
    eventId,
    children,
}: {
    eventId: number;
    children: ReactNode;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{children}</DialogTrigger>
            <DialogContent>
                <DialogTitle>Cancel this event?</DialogTitle>
                <DialogDescription>
                    All confirmed bookings are cancelled, and everyone who
                    booked gets an email. You cannot undo this.
                </DialogDescription>
                <Form {...store.form(eventId)} onFinish={() => setOpen(false)}>
                    {({ processing }) => (
                        <DialogFooter className="mt-5">
                            <DialogClose asChild>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="md"
                                >
                                    Keep event
                                </Button>
                            </DialogClose>
                            <Button
                                type="submit"
                                variant="destructive"
                                size="md"
                                disabled={processing}
                            >
                                Cancel event
                            </Button>
                        </DialogFooter>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
