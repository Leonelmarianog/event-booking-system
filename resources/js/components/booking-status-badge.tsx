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

export function BookingStatusBadge({ status }: { status: BookingStatus }) {
    return <Badge variant={variants[status]}>{labels[status]}</Badge>;
}
