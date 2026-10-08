import { Badge } from '@/components/ui/badge';
import type { BookingStatus } from '@/types';

const labels: Record<BookingStatus, string> = {
    confirmed: 'Confirmed',
    cancelled: 'Cancelled',
};

const variants = {
    confirmed: 'secondary',
    cancelled: 'destructive',
} as const;

export function BookingStatusBadge({
    status,
    eventCancelled = false,
}: {
    status: BookingStatus;
    eventCancelled?: boolean;
}) {
    if (eventCancelled) {
        return <Badge variant="destructive">Event cancelled</Badge>;
    }

    return <Badge variant={variants[status]}>{labels[status]}</Badge>;
}
