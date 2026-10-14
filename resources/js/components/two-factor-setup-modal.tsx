import { Form } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import AlertError from '@/components/alert-error';
import { CodeInput } from '@/components/code-input';
import { CopyButton } from '@/components/copy-button';
import { FieldLabel } from '@/components/field-label';
import InputError from '@/components/input-error';
import { RecoveryCodeList } from '@/components/two-factor-recovery-codes';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { OTP_MAX_LENGTH } from '@/hooks/use-two-factor-auth';
import { confirm } from '@/routes/two-factor';

type Step = 'scan' | 'code' | 'done';

const steps: Record<
    Step,
    { number: number; title: string; description: string }
> = {
    scan: {
        number: 1,
        title: 'Turn on two-factor',
        description:
            'Scan the QR code with your authenticator app, or enter the setup key.',
    },
    code: {
        number: 2,
        title: 'Enter the code',
        description: 'Enter the 6-digit code from your authenticator app.',
    },
    done: {
        number: 3,
        title: 'Two-factor is on',
        description:
            'Save these recovery codes somewhere safe. Each one logs you in once if you lose your phone.',
    },
};

function ScanStep({
    qrCodeSvg,
    manualSetupKey,
    errors,
    onCancel,
    onContinue,
}: {
    qrCodeSvg: string | null;
    manualSetupKey: string | null;
    errors: string[];
    onCancel: () => void;
    onContinue: () => void;
}) {
    if (errors.length) {
        return (
            <>
                <AlertError errors={errors} />
                <DialogFooter>
                    <Button variant="outline" size="md" onClick={onCancel}>
                        Cancel
                    </Button>
                </DialogFooter>
            </>
        );
    }

    return (
        <>
            <div className="mt-3 flex flex-wrap items-center gap-6">
                <div
                    role="img"
                    aria-label="QR code for your authenticator app"
                    className="size-44 shrink-0 bg-white p-2.5 [&_svg]:size-full"
                >
                    {qrCodeSvg ? (
                        <div
                            className="size-full"
                            dangerouslySetInnerHTML={{ __html: qrCodeSvg }}
                        />
                    ) : (
                        <div className="flex size-full items-center justify-center text-[#0d0d0e]">
                            <Spinner />
                        </div>
                    )}
                </div>

                <div className="grid min-w-0 flex-1 gap-2">
                    <p className="text-[13px] font-semibold tracking-[0.14em] text-muted-foreground uppercase">
                        Setup key
                    </p>
                    {manualSetupKey ? (
                        <p className="font-mono text-lg break-all">
                            {manualSetupKey}
                        </p>
                    ) : (
                        <Spinner />
                    )}
                    <CopyButton
                        text={manualSetupKey}
                        label="Copy key"
                        className="justify-self-start"
                    />
                </div>
            </div>

            <DialogFooter className="mt-5">
                <Button variant="outline" size="md" onClick={onCancel}>
                    Cancel
                </Button>
                <Button
                    size="md"
                    onClick={onContinue}
                    disabled={!manualSetupKey}
                >
                    Continue
                </Button>
            </DialogFooter>
        </>
    );
}

function CodeStep({
    onBack,
    onConfirmed,
}: {
    onBack: () => void;
    onConfirmed: () => Promise<void>;
}) {
    const [code, setCode] = useState('');
    const codeInput = useRef<HTMLInputElement>(null);

    return (
        <Form
            {...confirm.form()}
            options={{ preserveState: true, preserveScroll: true }}
            onSuccess={onConfirmed}
            onError={() => {
                setCode('');
                codeInput.current?.focus();
            }}
            resetOnError
            className="mt-3 grid gap-2"
        >
            {({
                processing,
                errors,
            }: {
                processing: boolean;
                errors?: { confirmTwoFactorAuthentication?: { code?: string } };
            }) => (
                <>
                    <FieldLabel htmlFor="setup-code">Code</FieldLabel>
                    <CodeInput
                        id="setup-code"
                        ref={codeInput}
                        name="code"
                        required
                        autoFocus
                        readOnly={processing}
                        aria-invalid={
                            errors?.confirmTwoFactorAuthentication?.code
                                ? true
                                : undefined
                        }
                        aria-describedby="setup-code-error"
                        onChange={(e) => setCode(e.currentTarget.value)}
                        className="bg-background"
                    />
                    <InputError
                        id="setup-code-error"
                        role="alert"
                        message={errors?.confirmTwoFactorAuthentication?.code}
                    />

                    <DialogFooter className="mt-5">
                        <Button
                            type="button"
                            variant="outline"
                            size="md"
                            onClick={onBack}
                            disabled={processing}
                        >
                            Back
                        </Button>
                        <Button
                            type="submit"
                            size="md"
                            disabled={
                                processing || code.length < OTP_MAX_LENGTH
                            }
                        >
                            Turn on
                        </Button>
                    </DialogFooter>
                </>
            )}
        </Form>
    );
}

type Props = {
    isOpen: boolean;
    onClose: () => void;
    requiresConfirmation: boolean;
    qrCodeSvg: string | null;
    manualSetupKey: string | null;
    clearSetupData: () => void;
    fetchSetupData: () => Promise<void>;
    recoveryCodesList: string[];
    fetchRecoveryCodes: () => Promise<void>;
    errors: string[];
};

export default function TwoFactorSetupModal({
    isOpen,
    onClose,
    requiresConfirmation,
    qrCodeSvg,
    manualSetupKey,
    clearSetupData,
    fetchSetupData,
    recoveryCodesList,
    fetchRecoveryCodes,
    errors,
}: Props) {
    const [step, setStep] = useState<Step>('scan');
    const current = steps[step];

    const handleClose = () => {
        if (step === 'done') {
            clearSetupData();
        }

        setStep('scan');
        onClose();
    };

    const handleContinue = () => {
        if (requiresConfirmation) {
            setStep('code');

            return;
        }

        clearSetupData();
        handleClose();
    };

    // The dialog can close while the code is checked. Step 3 then must not show on the next open.
    const isOpenRef = useRef(isOpen);

    useEffect(() => {
        isOpenRef.current = isOpen;
    }, [isOpen]);

    const handleConfirmed = async () => {
        await fetchRecoveryCodes();

        if (isOpenRef.current) {
            setStep('done');
        }
    };

    const fetchSetupDataRef = useRef(fetchSetupData);

    useEffect(() => {
        fetchSetupDataRef.current = fetchSetupData;
    }, [fetchSetupData]);

    useEffect(() => {
        if (isOpen && !qrCodeSvg) {
            void fetchSetupDataRef.current();
        }
    }, [isOpen, qrCodeSvg]);

    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && handleClose()}>
            <DialogContent>
                <DialogHeader className="gap-3 text-left sm:text-left">
                    <DialogTitle>{current.title}</DialogTitle>
                    <DialogDescription>{current.description}</DialogDescription>
                    <p
                        aria-hidden="true"
                        className="text-[13px] font-semibold tracking-[0.14em] text-muted-foreground uppercase"
                    >
                        Step {current.number} of 3
                    </p>
                </DialogHeader>

                {step === 'scan' && (
                    <ScanStep
                        qrCodeSvg={qrCodeSvg}
                        manualSetupKey={manualSetupKey}
                        errors={errors}
                        onCancel={handleClose}
                        onContinue={handleContinue}
                    />
                )}

                {step === 'code' && (
                    <CodeStep
                        onBack={() => setStep('scan')}
                        onConfirmed={handleConfirmed}
                    />
                )}

                {step === 'done' && (
                    <>
                        {errors.length ? (
                            <AlertError errors={errors} />
                        ) : (
                            <RecoveryCodeList codes={recoveryCodesList} />
                        )}
                        <DialogFooter className="mt-5">
                            <CopyButton
                                text={
                                    recoveryCodesList.length
                                        ? recoveryCodesList.join('\n')
                                        : null
                                }
                                label="Copy codes"
                            />
                            <Button size="md" onClick={handleClose}>
                                Done
                            </Button>
                        </DialogFooter>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}
