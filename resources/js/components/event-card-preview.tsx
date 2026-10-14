const shortDateTime: Intl.DateTimeFormatOptions = {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
};

type PreviewDate = {
    day: string;
    month: string;
    weekday: string;
    line: string;
};

/**
 * Formats the start time for the card. The value is a local time from the form, so it is only
 * set in the browser. It returns null for an empty value or a value that is not a valid date.
 */
function previewDate(value: string): PreviewDate | null {
    if (value === '') {
        return null;
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return null;
    }

    const format = (options: Intl.DateTimeFormatOptions) =>
        new Intl.DateTimeFormat(undefined, options).format(date);

    return {
        day: format({ day: 'numeric' }),
        month: format({ month: 'short' }),
        weekday: format({ weekday: 'short' }),
        line: format(shortDateTime),
    };
}

/**
 * The card of the event as it shows in the events list, made from the values in the form.
 * Screen readers skip the card, because it repeats the form.
 */
export function EventCardPreview({
    title,
    venue,
    startsAt,
    note,
}: {
    title: string;
    venue: string;
    startsAt: string;
    note: string;
}) {
    const date = previewDate(startsAt);
    const hasTitle = title.trim() !== '';
    const line = `${date?.line ?? 'Day, date, time'} · ${venue.trim() === '' ? 'Venue' : venue}`;

    return (
        <aside
            aria-label="Card preview"
            className="max-w-[380px] min-w-0 flex-[1_1_320px]"
        >
            <p className="mb-3 text-[13px] font-semibold tracking-[0.14em] text-muted-foreground uppercase">
                Card preview
            </p>
            <div aria-hidden="true" className="border bg-field">
                <div className="relative flex h-[220px] items-center justify-center bg-muted text-sm font-semibold tracking-[0.1em] text-muted-foreground uppercase dark:bg-[#1c1c1f]">
                    Your photo
                    <div className="absolute bottom-0 left-0 flex items-baseline gap-2 bg-lime px-3.5 py-2.5 font-display text-ink uppercase">
                        <span className="text-5xl leading-[0.85]">
                            {date?.day ?? '00'}
                        </span>
                        <span className="text-base leading-none">
                            {date?.month ?? 'Mon'}
                            <br />
                            {date?.weekday ?? 'Day'}
                        </span>
                    </div>
                </div>
                <div className="flex flex-col gap-2 px-6 pt-5 pb-6">
                    <p
                        className={
                            hasTitle
                                ? 'font-display text-[28px] leading-[1.05] break-words uppercase'
                                : 'font-display text-[28px] leading-[1.05] text-muted-foreground uppercase'
                        }
                    >
                        {hasTitle ? title : 'Your title'}
                    </p>
                    <p className="text-[15px] break-words text-muted-foreground">
                        {line}
                    </p>
                </div>
            </div>
            <p className="mt-3 text-sm text-muted-foreground">{note}</p>
        </aside>
    );
}
