import { Form, Head, setLayoutProps } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { create, store } from '@/routes/events';
import type { BreadcrumbItem } from '@/types';

/**
 * The input `datetime-local` gives a local time with no time zone. The browser knows
 * the time zone of the organizer, so it converts the value to UTC here.
 */
function toUtc(localDateTime: string): string {
    if (localDateTime === '') {
        return localDateTime;
    }

    return new Date(localDateTime).toISOString();
}

export default function CreateEvent() {
    setLayoutProps<{ breadcrumbs: BreadcrumbItem[] }>({
        breadcrumbs: [{ title: 'Create event', href: create() }],
    });

    return (
        <>
            <Head title="Create event" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:max-w-3xl">
                <h1 className="text-2xl font-semibold">Create event</h1>

                <Form
                    {...store.form()}
                    transform={(data) => ({
                        ...data,
                        starts_at: toUtc(String(data.starts_at ?? '')),
                    })}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="title">Title</Label>
                                <Input
                                    id="title"
                                    name="title"
                                    required
                                    maxLength={255}
                                />
                                <InputError message={errors.title} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="description">Description</Label>
                                <Textarea
                                    id="description"
                                    name="description"
                                    required
                                    maxLength={5000}
                                    rows={6}
                                />
                                <InputError message={errors.description} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="venue">Venue</Label>
                                <Input
                                    id="venue"
                                    name="venue"
                                    required
                                    maxLength={255}
                                />
                                <InputError message={errors.venue} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="starts_at">
                                    Starts at (your local time)
                                </Label>
                                <Input
                                    id="starts_at"
                                    name="starts_at"
                                    type="datetime-local"
                                    required
                                />
                                <InputError message={errors.starts_at} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="capacity">Seats</Label>
                                <Input
                                    id="capacity"
                                    name="capacity"
                                    type="number"
                                    required
                                    min={1}
                                    max={10000}
                                />
                                <InputError message={errors.capacity} />
                            </div>

                            <Button type="submit" disabled={processing}>
                                Create event
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}
