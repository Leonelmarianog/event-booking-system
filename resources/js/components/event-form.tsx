import { Form, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useEffect, useRef } from 'react';
import { FieldLabel } from '@/components/field-label';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import type { RouteDefinition, RouteFormDefinition } from '@/wayfinder';

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

type ControlProps = {
    id: string;
    'aria-invalid': true | undefined;
    'aria-describedby': string | undefined;
};

/**
 * One form field: the label, the control, the hint and the error. The hint and the error are
 * joined to the control, so that a screen reader reads them with the field.
 */
function Field({
    id,
    label,
    hint,
    error,
    children,
}: {
    id: string;
    label: string;
    hint?: string;
    error?: string;
    children: (control: ControlProps) => ReactNode;
}) {
    const hintId = hint ? `${id}-help` : undefined;
    const errorId = error ? `${id}-error` : undefined;
    const describedBy = [hintId, errorId].filter(Boolean).join(' ');

    return (
        <div className="flex min-w-0 flex-col gap-2">
            <FieldLabel htmlFor={id}>{label}</FieldLabel>
            {children({
                id,
                'aria-invalid': error ? true : undefined,
                'aria-describedby':
                    describedBy === '' ? undefined : describedBy,
            })}
            {hint && (
                <p id={hintId} className="text-[13px] text-muted-foreground">
                    {hint}
                </p>
            )}
            <InputError
                id={errorId}
                message={error}
                className="font-semibold text-destructive-foreground dark:text-destructive-foreground"
            />
        </div>
    );
}

export function EventForm({
    form,
    defaults,
    submitLabel,
    cancelHref,
}: {
    form: RouteFormDefinition<'post'>;
    defaults?: EventFormDefaults;
    submitLabel: string;
    cancelHref: RouteDefinition<'get'>;
    previewNote: string;
}) {
    const startsAtInput = useRef<HTMLInputElement>(null);
    const defaultStartsAt = defaults?.starts_at;
    const isCreate = defaults === undefined;

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
            id="event-form"
            tabIndex={-1}
            transform={(data) => ({
                ...data,
                starts_at: toUtc(String(data.starts_at ?? '')),
            })}
            className="flex min-w-0 flex-[999_1_560px] flex-col gap-7 outline-none"
        >
            {({ processing, errors }) => (
                <>
                    <Field id="title" label="Title" error={errors.title}>
                        {(control) => (
                            <Input
                                {...control}
                                name="title"
                                size="lg"
                                required
                                maxLength={255}
                                placeholder={
                                    isCreate
                                        ? 'Give it a name people remember'
                                        : undefined
                                }
                                defaultValue={defaults?.title}
                                className="text-lg md:text-lg"
                            />
                        )}
                    </Field>

                    <Field
                        id="description"
                        label="Description"
                        hint="Up to 5,000 characters."
                        error={errors.description}
                    >
                        {(control) => (
                            <Textarea
                                {...control}
                                name="description"
                                size="lg"
                                required
                                maxLength={5000}
                                rows={6}
                                placeholder={
                                    isCreate
                                        ? 'What happens, who it is for, what to bring'
                                        : undefined
                                }
                                defaultValue={defaults?.description}
                            />
                        )}
                    </Field>

                    <Field id="venue" label="Venue" error={errors.venue}>
                        {(control) => (
                            <Input
                                {...control}
                                name="venue"
                                size="lg"
                                required
                                maxLength={255}
                                placeholder={
                                    isCreate ? 'e.g. Armazém 16' : undefined
                                }
                                defaultValue={defaults?.venue}
                            />
                        )}
                    </Field>

                    <div className="grid grid-cols-[repeat(auto-fit,minmax(220px,1fr))] gap-6">
                        <Field
                            id="starts_at"
                            label="Starts at"
                            hint="In your time zone."
                            error={errors.starts_at}
                        >
                            {(control) => (
                                <Input
                                    {...control}
                                    ref={startsAtInput}
                                    name="starts_at"
                                    type="datetime-local"
                                    size="lg"
                                    required
                                    className="dark:[color-scheme:dark]"
                                />
                            )}
                        </Field>

                        <Field
                            id="capacity"
                            label="Seats"
                            hint="1 to 10,000."
                            error={errors.capacity}
                        >
                            {(control) => (
                                <Input
                                    {...control}
                                    name="capacity"
                                    type="number"
                                    size="lg"
                                    required
                                    min={1}
                                    max={10000}
                                    placeholder={isCreate ? '50' : undefined}
                                    defaultValue={defaults?.capacity}
                                    className="dark:[color-scheme:dark]"
                                />
                            )}
                        </Field>
                    </div>

                    <div className="flex flex-wrap gap-3 pt-2">
                        <Button type="submit" size="lg" disabled={processing}>
                            {submitLabel}
                        </Button>
                        <Button
                            asChild
                            variant="outline"
                            size="lg"
                            className="border-input"
                        >
                            <Link href={cancelHref}>Cancel</Link>
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
