import { Link } from '@inertiajs/react';
import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

type Props = ComponentProps<typeof Link> & {
    variant?: 'default' | 'accent';
};

export default function TextLink({
    className = '',
    variant = 'default',
    children,
    ...props
}: Props) {
    return (
        <Link
            className={cn(
                'text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500',
                variant === 'accent' &&
                    'font-semibold dark:text-lime dark:no-underline dark:hover:text-[#f4ffa6]',
                className,
            )}
            {...props}
        >
            {children}
        </Link>
    );
}
