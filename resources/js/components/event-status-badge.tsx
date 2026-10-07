import { Badge } from '@/components/ui/badge';
import type { EventStatus } from '@/types';

const labels: Record<EventStatus, string> = {
    draft: 'Draft',
    published: 'Published',
    cancelled: 'Cancelled',
};

const variants = {
    draft: 'outline',
    published: 'secondary',
    cancelled: 'destructive',
} as const;

export function EventStatusBadge({ status }: { status: EventStatus }) {
    return <Badge variant={variants[status]}>{labels[status]}</Badge>;
}
