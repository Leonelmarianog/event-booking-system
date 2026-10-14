import { Head } from '@inertiajs/react';
import { BackLink } from '@/components/back-link';
import { EventForm } from '@/components/event-form';
import { SkipLink } from '@/components/skip-link';
import { cn } from '@/lib/utils';
import { show, update } from '@/routes/events';
import type { EventDetails } from '@/types';

export default function EditEvent({ event }: { event: EventDetails }) {
    const isDraft = event.status === 'draft';

    return (
        <>
            <Head title={`Edit ${event.title}`} />
            <SkipLink href="#event-form">Skip to form</SkipLink>
            <div className="flex flex-col px-8 pt-14 pb-22">
                <BackLink href={show(event.id)}>Back to event</BackLink>
                <div className="mt-2 mb-12 flex flex-wrap items-baseline gap-4">
                    <h1 className="font-display text-[clamp(56px,8vw,112px)] leading-[0.9] uppercase">
                        Edit event
                    </h1>
                    <span
                        className={cn(
                            'inline-flex px-2.5 py-1 text-xs font-bold tracking-[0.1em] uppercase',
                            isDraft
                                ? 'bg-violet text-ink'
                                : 'border border-foreground dark:border-lime dark:text-lime',
                        )}
                    >
                        {isDraft ? 'Draft' : 'Published'}
                    </span>
                </div>
                <EventForm
                    form={update.form(event.id)}
                    defaults={event}
                    submitLabel="Save changes"
                    cancelHref={show(event.id)}
                    previewNote="Changing the start time sends no email to people who booked."
                />
            </div>
        </>
    );
}
