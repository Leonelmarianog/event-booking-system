import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type Props = {
    id: string;
    title: string;
    description?: string;
    tone?: 'default' | 'danger';
    aside?: ReactNode;
    className?: string;
    children: ReactNode;
};

/**
 * One block on a settings page: a section with an Anton heading, an intro line and the content.
 * The "danger" tone has an orange border, for actions that cannot be undone.
 */
export function SettingsSection({
    id,
    title,
    description,
    tone = 'default',
    aside,
    className,
    children,
}: Props) {
    const danger = tone === 'danger';

    return (
        <section
            aria-labelledby={id}
            className={cn(
                'max-w-[640px] min-w-0',
                danger && 'border border-destructive p-7',
                className,
            )}
        >
            <div className="flex items-start justify-between gap-4">
                <div className="min-w-0">
                    <h2
                        id={id}
                        className={cn(
                            'font-display leading-[1.05] uppercase',
                            danger
                                ? 'text-[28px] text-destructive-foreground'
                                : 'text-4xl',
                        )}
                    >
                        {title}
                    </h2>
                    {description && (
                        <p
                            className={cn(
                                danger
                                    ? 'mt-2 mb-5 text-foreground dark:text-[#b9b7ae]'
                                    : 'mt-1 mb-7 text-muted-foreground',
                            )}
                        >
                            {description}
                        </p>
                    )}
                </div>
                {aside}
            </div>
            {children}
        </section>
    );
}
