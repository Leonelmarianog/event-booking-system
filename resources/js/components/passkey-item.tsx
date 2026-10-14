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
import type { Passkey } from '@/types/auth';

type Props = {
    passkey: Passkey;
    onDelete: (id: number, onError: () => void) => void;
};

export default function PasskeyItem({ passkey, onDelete }: Props) {
    const [isDeleting, setIsDeleting] = useState(false);

    const handleDelete = () => {
        setIsDeleting(true);
        onDelete(passkey.id, () => setIsDeleting(false));
    };

    const details = [
        passkey.authenticator,
        `Added ${passkey.created_at_diff}`,
        passkey.last_used_at_diff && `Last used ${passkey.last_used_at_diff}`,
    ]
        .filter(Boolean)
        .join(' · ');

    return (
        <li className="flex items-center justify-between gap-4 border-b border-border py-4">
            <span className="min-w-0">
                <span className="block font-semibold break-words">
                    {passkey.name}
                </span>
                <span className="block text-sm text-muted-foreground">
                    {details}
                </span>
            </span>

            <Dialog>
                <DialogTrigger asChild>
                    <Button
                        variant="outline"
                        size="md"
                        className="shrink-0"
                        aria-label={`Remove passkey ${passkey.name}`}
                    >
                        Remove
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <DialogTitle>Remove this passkey?</DialogTitle>
                    <DialogDescription>
                        You will no longer be able to log in with the &quot;
                        {passkey.name}&quot; passkey.
                    </DialogDescription>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button variant="outline" size="md">
                                Keep passkey
                            </Button>
                        </DialogClose>
                        <Button
                            variant="destructive"
                            size="md"
                            onClick={handleDelete}
                            disabled={isDeleting}
                        >
                            {isDeleting ? 'Removing...' : 'Remove passkey'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </li>
    );
}
