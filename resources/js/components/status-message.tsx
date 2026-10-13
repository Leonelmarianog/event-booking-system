import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export function StatusMessage({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <p
            role="status"
            className={cn(
                'border border-foreground px-[18px] py-3.5 text-[15px] font-semibold dark:border-lime dark:text-lime',
                className,
            )}
        >
            {children}
        </p>
    );
}
