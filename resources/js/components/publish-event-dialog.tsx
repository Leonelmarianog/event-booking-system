import { Form } from '@inertiajs/react';
import { Send } from 'lucide-react';
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
 * A button that asks for a confirmation and then publishes the event. The dialog
 * closes when the request ends, also when the server refuses with an error toast.
 */
export function PublishEventDialog({ eventId }: { eventId: number }) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm">
                    <Send />
                    Publish
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Publish this event?</DialogTitle>
                <DialogDescription>
                    Everyone can see a published event. You cannot make it a
                    draft again.
                </DialogDescription>
                <Form {...store.form(eventId)} onFinish={() => setOpen(false)}>
                    {({ processing }) => (
                        <DialogFooter className="gap-2">
                            <DialogClose asChild>
                                <Button variant="secondary">Cancel</Button>
                            </DialogClose>
                            <Button type="submit" disabled={processing}>
                                Publish
                            </Button>
                        </DialogFooter>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
