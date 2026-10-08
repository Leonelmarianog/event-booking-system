import { Form } from '@inertiajs/react';
import { Ban } from 'lucide-react';
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
 * A button that asks for a confirmation and then cancels the event and its bookings.
 * The dialog closes when the request ends, also when the server refuses with an error
 * toast.
 */
export function CancelEventDialog({ eventId }: { eventId: number }) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    size="sm"
                    variant="outline"
                    className="text-destructive hover:text-destructive"
                >
                    <Ban />
                    Cancel event
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Cancel this event?</DialogTitle>
                <DialogDescription>
                    All confirmed bookings are cancelled. You cannot undo this.
                </DialogDescription>
                <Form {...store.form(eventId)} onFinish={() => setOpen(false)}>
                    {({ processing }) => (
                        <DialogFooter className="gap-2">
                            <DialogClose asChild>
                                <Button type="button" variant="secondary">
                                    Keep event
                                </Button>
                            </DialogClose>
                            <Button
                                type="submit"
                                variant="destructive"
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
