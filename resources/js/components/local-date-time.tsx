import { useEffect, useState } from 'react';

const options: Intl.DateTimeFormatOptions = {
    weekday: 'long',
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    timeZoneName: 'short',
};

function format(value: string, timeZone?: string): string {
    return new Intl.DateTimeFormat(undefined, { ...options, timeZone }).format(
        new Date(value),
    );
}

/**
 * Shows a date and time in the time zone of the viewer. The server renders the
 * page in UTC, so the first render uses UTC on both sides. After the page loads in
 * the browser, the component changes to the local time zone.
 */
export function LocalDateTime({ value }: { value: string }) {
    const [text, setText] = useState(() => format(value, 'UTC'));

    useEffect(() => {
        setText(format(value));
    }, [value]);

    return <time dateTime={value}>{text}</time>;
}
