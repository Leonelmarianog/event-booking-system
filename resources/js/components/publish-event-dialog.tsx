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
import { store } from '@/routes/events/publication';

/**
 * Asks for a confirmation and then publishes the event. The children are the button
 * that opens the dialog. The dialog closes when the request ends, also when the
 * server refuses with an error toast.
 */
export function PublishEventDialog({
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
                <DialogTitle>Publish this event?</DialogTitle>
                <DialogDescription>
                    Everyone can see a published event and book seats. You
                    cannot make it a draft again.
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
                                    Keep as draft
                                </Button>
                            </DialogClose>
                            <Button
                                type="submit"
                                size="md"
                                disabled={processing}
                            >
                                Publish
                            </Button>
                        </DialogFooter>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
