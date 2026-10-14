import { Form } from '@inertiajs/react';
import AlertError from '@/components/alert-error';
import { CopyButton } from '@/components/copy-button';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { regenerateRecoveryCodes } from '@/routes/two-factor';

/**
 * The recovery codes in 2 columns, or a loading line while they are not there yet.
 */
export function RecoveryCodeList({ codes }: { codes: string[] }) {
    if (!codes.length) {
        return (
            <p className="flex items-center gap-2 text-muted-foreground">
                <Spinner /> Loading recovery codes…
            </p>
        );
    }

    return (
        <ul
            aria-label="Recovery codes"
            className="grid grid-cols-2 gap-x-4 gap-y-2 border border-border bg-background p-4 font-mono text-[15px] whitespace-nowrap"
        >
            {codes.map((code) => (
                <li key={code}>{code}</li>
            ))}
        </ul>
    );
}

type Props = {
    id: string;
    recoveryCodesList: string[];
    fetchRecoveryCodes: () => Promise<void>;
    errors: string[];
};

export default function TwoFactorRecoveryCodes({
    id,
    recoveryCodesList,
    fetchRecoveryCodes,
    errors,
}: Props) {
    return (
        <div id={id} className="mt-6 grid max-w-[520px] gap-4">
            {errors.length ? (
                <AlertError errors={errors} />
            ) : (
                <RecoveryCodeList codes={recoveryCodesList} />
            )}

            <div className="flex flex-wrap gap-3">
                <CopyButton
                    text={
                        recoveryCodesList.length
                            ? recoveryCodesList.join('\n')
                            : null
                    }
                    label="Copy codes"
                />
                <Form
                    {...regenerateRecoveryCodes.form()}
                    options={{ preserveScroll: true, preserveState: true }}
                    onSuccess={fetchRecoveryCodes}
                >
                    {({ processing }) => (
                        <Button
                            type="submit"
                            variant="outline"
                            size="md"
                            disabled={processing}
                            aria-describedby="recovery-codes-note"
                        >
                            Regenerate codes
                        </Button>
                    )}
                </Form>
            </div>

            <p
                id="recovery-codes-note"
                className="text-sm text-muted-foreground"
            >
                Each code logs you in once.
            </p>
        </div>
    );
}
