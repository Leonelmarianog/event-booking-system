import { Eye, EyeOff } from 'lucide-react';
import type { ComponentProps, Ref } from 'react';
import { useState } from 'react';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

export default function PasswordInput({
    className,
    ref,
    size = 'default',
    ...props
}: Omit<ComponentProps<typeof Input>, 'type'> & {
    ref?: Ref<HTMLInputElement>;
}) {
    const [showPassword, setShowPassword] = useState(false);

    return (
        <div className="relative">
            <Input
                type={showPassword ? 'text' : 'password'}
                size={size}
                className={cn(size === 'lg' ? 'pr-14' : 'pr-10', className)}
                ref={ref}
                {...props}
            />
            <button
                type="button"
                onClick={() => setShowPassword((prev) => !prev)}
                className={cn(
                    'absolute inset-y-0 right-0 flex items-center rounded-r-md text-muted-foreground hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none',
                    size === 'lg' ? 'px-4' : 'px-3',
                )}
                aria-label={showPassword ? 'Hide password' : 'Show password'}
                tabIndex={-1}
            >
                {showPassword ? (
                    <EyeOff className={size === 'lg' ? 'size-5' : 'size-4'} />
                ) : (
                    <Eye className={size === 'lg' ? 'size-5' : 'size-4'} />
                )}
            </button>
        </div>
    );
}
