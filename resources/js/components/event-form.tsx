import { Form } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { RouteFormDefinition } from '@/wayfinder';

export type EventFormDefaults = {
    title: string;
    description: string;
    venue: string;
    starts_at: string;
    capacity: number;
};

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

/**
 * Converts an ISO 8601 time to the value of a `datetime-local` input, in the time zone
 * of the browser.
 */
function toLocalInputValue(isoDateTime: string): string {
    const date = new Date(isoDateTime);
    const pad = (value: number) => String(value).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

export function EventForm({
    form,
    defaults,
    submitLabel,
}: {
    form: RouteFormDefinition<'post'>;
    defaults?: EventFormDefaults;
    submitLabel: string;
}) {
    const startsAtInput = useRef<HTMLInputElement>(null);
    const defaultStartsAt = defaults?.starts_at;

    /**
     * The server renders the page in UTC, so the start time input is empty on the first
     * render. After the page loads in the browser, the input gets the local time.
     */
    useEffect(() => {
        if (defaultStartsAt && startsAtInput.current) {
            startsAtInput.current.value = toLocalInputValue(defaultStartsAt);
        }
    }, [defaultStartsAt]);

    return (
        <Form
            {...form}
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
                            defaultValue={defaults?.title}
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
                            defaultValue={defaults?.description}
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
                            defaultValue={defaults?.venue}
                        />
                        <InputError message={errors.venue} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="starts_at">
                            Starts at (your local time)
                        </Label>
                        <Input
                            ref={startsAtInput}
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
                            defaultValue={defaults?.capacity}
                        />
                        <InputError message={errors.capacity} />
                    </div>

                    <Button type="submit" disabled={processing}>
                        {submitLabel}
                    </Button>
                </>
            )}
        </Form>
    );
}
