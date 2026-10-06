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

function format(value: string, locale?: string, timeZone?: string): string {
    return new Intl.DateTimeFormat(locale, { ...options, timeZone }).format(
        new Date(value),
    );
}

/**
 * Shows a date and time in the locale and time zone of the viewer. The server
 * cannot know them, so the first render uses en-US and UTC on both sides. After the
 * page loads in the browser, the component changes to the local format.
 */
export function LocalDateTime({ value }: { value: string }) {
    const [text, setText] = useState(() => format(value, 'en-US', 'UTC'));

    useEffect(() => {
        setText(format(value));
    }, [value]);

    return <time dateTime={value}>{text}</time>;
}
