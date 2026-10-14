import { cn } from '@/lib/utils';
import type { EventDetails } from '@/types';

function bannerFor(
    event: EventDetails,
    isOrganizer: boolean,
): { text: string; className: string } | null {
    if (event.status === 'cancelled') {
        return { text: 'This event was cancelled.', className: 'bg-orange' };
    }

    if (event.status === 'draft') {
        return {
            text: isOrganizer
                ? 'Draft. Only you can see this event until you publish it.'
                : 'Draft. This event is not public yet.',
            className: 'bg-violet',
        };
    }

    if (event.has_started) {
        return { text: 'This event has ended.', className: 'bg-[#b9b7ae]' };
    }

    return null;
}

/**
 * The full-width line under the event header for a cancelled, draft or past event.
 * A published event that has not started has no banner.
 */
export function EventBanner({
    event,
    isOrganizer,
}: {
    event: EventDetails;
    isOrganizer: boolean;
}) {
    const banner = bannerFor(event, isOrganizer);

    if (banner === null) {
        return null;
    }

    return (
        <p className={cn('px-8 py-4 font-bold text-ink', banner.className)}>
            {banner.text}
        </p>
    );
}
