import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { useClipboard } from '@/hooks/use-clipboard';

/**
 * An outlined button that copies the text. It says "Copied" for 2 seconds after a copy.
 */
export function CopyButton({
    text,
    label,
    className,
}: {
    text: string | null;
    label: string;
    className?: string;
}) {
    const [, copy] = useClipboard();
    const [copied, setCopied] = useState(false);

    useEffect(() => {
        if (!copied) {
            return;
        }

        const timer = setTimeout(() => setCopied(false), 2000);

        return () => clearTimeout(timer);
    }, [copied]);

    return (
        <>
            <Button
                type="button"
                variant="outline"
                size="md"
                className={className}
                disabled={!text}
                onClick={async () => {
                    if (text && (await copy(text))) {
                        setCopied(true);
                    }
                }}
            >
                {copied ? 'Copied' : label}
            </Button>
            <span role="status" className="sr-only">
                {copied ? 'Copied' : ''}
            </span>
        </>
    );
}
