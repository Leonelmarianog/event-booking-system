import { Link } from '@inertiajs/react';
import { useLocalDateTime } from '@/hooks/use-local-date-time';
import { index as events } from '@/routes/events';
import type { EventDetails } from '@/types';

const day: Intl.DateTimeFormatOptions = { day: 'numeric' };
const month: Intl.DateTimeFormatOptions = { month: 'short' };
const weekday: Intl.DateTimeFormatOptions = { weekday: 'short' };
const time: Intl.DateTimeFormatOptions = { hour: '2-digit', minute: '2-digit' };

/**
 * The lime date block, for example "19" and "Oct · Mon · 21:00". Screen readers skip
 * it, because the "When" row gives the same date.
 */
function DateBlock({ value }: { value: string }) {
    const dayText = useLocalDateTime(value, day);
    const parts = [
        useLocalDateTime(value, month),
        useLocalDateTime(value, weekday),
        useLocalDateTime(value, time),
    ];

    return (
        <div
            aria-hidden="true"
            className="bg-lime px-[22px] py-4 text-right font-display text-ink uppercase"
        >
            <div className="text-[104px] leading-[0.82]">{dayText}</div>
            <div className="text-2xl tracking-[0.06em]">
                {parts.join(' · ')}
            </div>
        </div>
    );
}

/**
 * The top of the event page: the "All events" link, the title and the date block on a
 * dark placeholder. The event photo takes the place of the placeholder later.
 */
export function EventHeader({ event }: { event: EventDetails }) {
    return (
        <section className="relative flex min-h-[520px] overflow-hidden bg-ink text-[#f2f0eb]">
            <div
                aria-hidden="true"
                className="absolute inset-0 bg-[radial-gradient(ellipse_at_30%_20%,#2b2b30_0%,#141416_55%,#0d0d0e_100%)]"
            />
            <div className="relative flex min-w-0 flex-1 flex-col justify-between gap-8 px-8 pt-8 pb-12">
                <Link
                    href={events()}
                    className="inline-flex min-h-11 items-center self-start bg-ink/60 px-4 text-sm font-semibold tracking-[0.08em] uppercase outline-none focus-visible:outline-3 focus-visible:outline-offset-3 focus-visible:outline-ring"
                >
                    ← All events
                </Link>
                <div className="flex flex-wrap items-end justify-between gap-8">
                    <h1 className="min-w-0 flex-[1_1_640px] font-display text-[clamp(56px,8vw,120px)] leading-[0.9] font-normal break-words uppercase">
                        {event.title}
                    </h1>
                    <DateBlock value={event.starts_at} />
                </div>
            </div>
        </section>
    );
}
