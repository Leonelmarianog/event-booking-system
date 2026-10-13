import type { ComponentProps } from 'react';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

export function FieldLabel({
    className,
    ...props
}: ComponentProps<typeof Label>) {
    return (
        <Label
            className={cn(
                'text-[13px] leading-normal font-semibold tracking-[0.14em] text-muted-foreground uppercase',
                className,
            )}
            {...props}
        />
    );
}
