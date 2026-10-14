import { Head } from '@inertiajs/react';
import { BackLink } from '@/components/back-link';
import { EventForm } from '@/components/event-form';
import { SkipLink } from '@/components/skip-link';
import { store } from '@/routes/events';
import { index as organizerEvents } from '@/routes/organizer/events';

export default function CreateEvent() {
    return (
        <>
            <Head title="Create event" />
            <SkipLink href="#event-form">Skip to form</SkipLink>
            <div className="flex flex-col px-8 pt-14 pb-22">
                <BackLink href={organizerEvents()}>My events</BackLink>
                <h1 className="mt-2 mb-3 font-display text-[clamp(56px,8vw,112px)] leading-[0.9] uppercase">
                    Create event
                </h1>
                <p className="mb-12 text-lg text-muted-foreground dark:text-[#b9b7ae]">
                    It starts as a draft. Only you can see it until you publish
                    it.
                </p>
                <EventForm
                    form={store.form()}
                    submitLabel="Create event"
                    cancelHref={organizerEvents()}
                    previewNote="This is how the card looks in the events list."
                />
            </div>
        </>
    );
}
