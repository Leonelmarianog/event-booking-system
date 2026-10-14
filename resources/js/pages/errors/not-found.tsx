import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { index as events } from '@/routes/events';

export default function NotFound({
    title,
    message,
}: {
    title: string;
    message: string;
}) {
    return (
        <>
            <Head title={title} />
            <div className="px-8 py-[120px]">
                <p
                    aria-hidden="true"
                    className="font-display text-[160px] leading-[0.85] text-border dark:text-[#2b2b30]"
                >
                    404
                </p>
                <h1 className="mt-4 mb-3 font-display text-[clamp(48px,7vw,96px)] leading-[0.9] font-normal uppercase">
                    {title}
                </h1>
                <p className="mb-8 max-w-[520px] text-lg text-muted-foreground dark:text-[#b9b7ae]">
                    {message}
                </p>
                <Button asChild size="lg">
                    <Link href={events()}>Find events</Link>
                </Button>
            </div>
        </>
    );
}
