import type { ChangeEvent, ComponentProps } from 'react';
import { Input } from '@/components/ui/input';
import { OTP_MAX_LENGTH } from '@/hooks/use-two-factor-auth';
import { cn } from '@/lib/utils';

type Props = Omit<
    ComponentProps<typeof Input>,
    'type' | 'size' | 'inputMode' | 'autoComplete' | 'maxLength'
>;

/**
 * The 6-digit code field. It keeps only the digits, so a pasted "123 456" becomes "123456".
 * It has no maxLength, because the browser cuts a paste at maxLength before the spaces go.
 */
export function CodeInput({ className, onChange, ...props }: Props) {
    const keepDigits = (event: ChangeEvent<HTMLInputElement>) => {
        const input = event.currentTarget;
        const digits = input.value.replace(/\D/g, '').slice(0, OTP_MAX_LENGTH);

        if (digits !== input.value) {
            input.value = digits;
        }

        onChange?.(event);
    };

    return (
        <Input
            size="lg"
            inputMode="numeric"
            autoComplete="one-time-code"
            className={cn(
                'h-16 font-mono text-[28px] tracking-[0.4em] md:text-[28px]',
                className,
            )}
            onChange={keepDigits}
            {...props}
        />
    );
}
