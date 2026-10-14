import { useEffect, useState } from 'react';

/**
 * Formats a date and time in the locale and time zone of the viewer. The server
 * cannot know them, so the first render uses en-US and UTC on both sides. After the
 * page loads in the browser, the text changes to the local format. Define the options
 * once, outside the component, so that the effect does not run on each render.
 */
export function useLocalDateTime(
    value: string,
    options: Intl.DateTimeFormatOptions,
): string {
    const [text, setText] = useState(() =>
        new Intl.DateTimeFormat('en-US', {
            ...options,
            timeZone: 'UTC',
        }).format(new Date(value)),
    );

    useEffect(() => {
        setText(
            new Intl.DateTimeFormat(undefined, options).format(new Date(value)),
        );
    }, [value, options]);

    return text;
}
