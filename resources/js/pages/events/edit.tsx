import { Head, setLayoutProps } from '@inertiajs/react';
import { EventForm } from '@/components/event-form';
import { edit, show, update } from '@/routes/events';
import type { BreadcrumbItem, EventDetails } from '@/types';

export default function EditEvent({ event }: { event: EventDetails }) {
    setLayoutProps<{ breadcrumbs: BreadcrumbItem[] }>({
        breadcrumbs: [
            { title: event.title, href: show(event.id) },
            { title: 'Edit', href: edit(event.id) },
        ],
    });

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
