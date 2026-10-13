import { Form, Head, resetLayoutProps, setLayoutProps } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { FieldLabel } from '@/components/field-label';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { OTP_MAX_LENGTH } from '@/hooks/use-two-factor-auth';
import { store } from '@/routes/two-factor/login';

const modes = {
    code: {
        title: 'Authentication code',
        description: 'Enter the 6-digit code from your authenticator app.',
        switchHint: 'Lost your device?',
        switchText: 'Use a recovery code',
    },
    recovery: {
        title: 'Recovery code',
        description:
            'Enter one of the recovery codes you saved when you turned on two-factor.',
        switchHint: 'Have your phone?',
        switchText: 'Use an authentication code',
    },
};

export default function TwoFactorChallenge() {
    const [recovery, setRecovery] = useState(false);
    const mode = recovery ? modes.recovery : modes.code;

    setLayoutProps({
        title: mode.title,
        description: mode.description,
        panel: { tint: 'green', headline: 'One more\nstep.' },
    });

    // The layout props are shared, so they would stay on the next page.
    useEffect(() => () => resetLayoutProps(), []);

    return (
        <>
            <Head title="Two-factor authentication" />

            <Form
                {...store.form()}
                resetOnError
                className="flex flex-col gap-6"
            >
                {({ errors, processing, clearErrors }) => (
                    <>
                        {recovery ? (
                            <div key="recovery" className="grid gap-2">
                                <FieldLabel htmlFor="recovery_code">
                                    Recovery code
                                </FieldLabel>
                                <Input
                                    id="recovery_code"
                                    name="recovery_code"
                                    size="lg"
                                    required
                                    autoFocus
                                    autoComplete="off"
                                    autoCapitalize="none"
                                    autoCorrect="off"
                                    spellCheck={false}
                                    className="h-16 font-mono text-[22px] tracking-[0.1em] md:text-[22px]"
                                />
                                <InputError message={errors.recovery_code} />
                            </div>
                        ) : (
                            <div key="code" className="grid gap-2">
                                <FieldLabel htmlFor="code">Code</FieldLabel>
                                <Input
                                    id="code"
                                    name="code"
                                    size="lg"
                                    required
                                    autoFocus
                                    inputMode="numeric"
                                    autoComplete="one-time-code"
                                    maxLength={OTP_MAX_LENGTH}
                                    className="h-16 font-mono text-[28px] tracking-[0.4em] md:text-[28px]"
                                />
                                <InputError message={errors.code} />
                            </div>
                        )}

                        <Button
                            type="submit"
                            size="lg"
                            className="w-full"
                            disabled={processing}
                        >
                            Continue
                        </Button>

                        <p className="mt-1 text-center text-muted-foreground">
                            {mode.switchHint}{' '}
                            <button
                                type="button"
                                className="min-h-11 cursor-pointer px-1 font-semibold text-foreground underline underline-offset-4 dark:text-lime dark:decoration-lime/60"
                                onClick={() => {
                                    clearErrors();
                                    setRecovery(!recovery);
                                }}
                            >
                                {mode.switchText}
                            </button>
                        </p>
                    </>
                )}
            </Form>
        </>
    );
}
