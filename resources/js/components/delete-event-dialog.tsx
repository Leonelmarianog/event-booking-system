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
import { destroy } from '@/routes/events';

/**
 * Asks for a confirmation and then deletes the draft event. The children are the
 * button that opens the dialog. The dialog closes when the request ends, also when
 * the server refuses with an error toast.
 */
export function DeleteEventDialog({
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
                <DialogTitle>Delete this draft?</DialogTitle>
                <DialogDescription>
                    The draft is removed for good. You cannot undo this.
                </DialogDescription>
                <Form
                    {...destroy.form(eventId)}
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
                                    Keep draft
                                </Button>
                            </DialogClose>
                            <Button
                                type="submit"
                                variant="destructive"
                                size="md"
                                disabled={processing}
                            >
                                Delete draft
                            </Button>
                        </DialogFooter>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
