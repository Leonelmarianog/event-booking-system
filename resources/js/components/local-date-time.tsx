import { useLocalDateTime } from '@/hooks/use-local-date-time';

const longDateTime: Intl.DateTimeFormatOptions = {
    weekday: 'long',
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    timeZoneName: 'short',
};

/**
 * Shows a date and time in the locale and time zone of the viewer (see
 * useLocalDateTime). The default format is long, with the time zone.
 */
export function LocalDateTime({
    value,
    options = longDateTime,
}: {
    value: string;
    options?: Intl.DateTimeFormatOptions;
}) {
    const text = useLocalDateTime(value, options);

    return <time dateTime={value}>{text}</time>;
}
