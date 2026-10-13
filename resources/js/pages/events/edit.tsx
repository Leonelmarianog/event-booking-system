import { Head } from '@inertiajs/react';
import { EventForm } from '@/components/event-form';
import { update } from '@/routes/events';
import type { EventDetails } from '@/types';

export default function EditEvent({ event }: { event: EventDetails }) {
    return (
        <>
            <Head title={`Edit ${event.title}`} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:max-w-3xl">
                <h1 className="text-2xl font-semibold">Edit event</h1>
                <EventForm
                    form={update.form(event.id)}
                    defaults={event}
                    submitLabel="Save changes"
                />
            </div>
        </>
    );
}
