import { Head, setLayoutProps } from '@inertiajs/react';
import { EventForm } from '@/components/event-form';
import { create, store } from '@/routes/events';
import type { BreadcrumbItem } from '@/types';

export default function CreateEvent() {
    setLayoutProps<{ breadcrumbs: BreadcrumbItem[] }>({
        breadcrumbs: [{ title: 'Create event', href: create() }],
    });

    return (
        <>
            <Head title="Create event" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:max-w-3xl">
                <h1 className="text-2xl font-semibold">Create event</h1>
                <EventForm form={store.form()} submitLabel="Create event" />
            </div>
        </>
    );
}
