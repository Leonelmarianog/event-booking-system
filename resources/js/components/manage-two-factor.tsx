import { Form } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { SettingsSection } from '@/components/settings-section';
import TwoFactorRecoveryCodes from '@/components/two-factor-recovery-codes';
import TwoFactorSetupModal from '@/components/two-factor-setup-modal';
import { Button } from '@/components/ui/button';
import { useTwoFactorAuth } from '@/hooks/use-two-factor-auth';
import { cn } from '@/lib/utils';
import { disable, enable } from '@/routes/two-factor';

export type Props = {
    canManageTwoFactor?: boolean;
    requiresConfirmation?: boolean;
    twoFactorEnabled?: boolean;
};

function TwoFactorBadge({ on }: { on: boolean }) {
    return (
        <span
            className={cn(
                'mt-2 shrink-0 border px-2.5 py-1 text-xs font-bold tracking-[0.14em] uppercase',
                on
                    ? 'border-foreground dark:border-lime dark:text-lime'
                    : 'border-input text-muted-foreground',
            )}
        >
            {on ? 'On' : 'Off'}
        </span>
    );
}

export default function ManageTwoFactor(props: Props) {
    const requiresConfirmation = props.requiresConfirmation ?? false;
    const twoFactorEnabled = props.twoFactorEnabled ?? false;

    const {
        qrCodeSvg,
        hasSetupData,
        manualSetupKey,
        clearSetupData,
        clearTwoFactorAuthData,
        fetchSetupData,
        recoveryCodesList,
        fetchRecoveryCodes,
        errors,
    } = useTwoFactorAuth();
    const [showSetupModal, setShowSetupModal] = useState(false);
    const [codesOpen, setCodesOpen] = useState(false);
    const prevTwoFactorEnabled = useRef(twoFactorEnabled);

    useEffect(() => {
        if (prevTwoFactorEnabled.current && !twoFactorEnabled) {
            clearTwoFactorAuthData();
        }

        prevTwoFactorEnabled.current = twoFactorEnabled;
    }, [twoFactorEnabled, clearTwoFactorAuthData]);

    if (!(props.canManageTwoFactor ?? false)) {
        return null;
    }

    const toggleCodes = () => {
        if (!codesOpen && !recoveryCodesList.length) {
            void fetchRecoveryCodes();
        }

        setCodesOpen(!codesOpen);
    };

    return (
        <SettingsSection
            id="twofa-heading"
            title="Two-factor authentication"
            description="Ask for a code from an authenticator app when you log in."
            aside={<TwoFactorBadge on={twoFactorEnabled} />}
        >
            {twoFactorEnabled ? (
                <>
                    <div className="flex flex-wrap gap-3">
                        <Button
                            size="md"
                            onClick={toggleCodes}
                            aria-expanded={codesOpen}
                            aria-controls="recovery-codes"
                        >
                            {codesOpen
                                ? 'Hide recovery codes'
                                : 'Show recovery codes'}
                        </Button>
                        <Form
                            {...disable.form()}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setCodesOpen(false)}
                        >
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    variant="outline"
                                    size="md"
                                    disabled={processing}
                                >
                                    Turn off two-factor
                                </Button>
                            )}
                        </Form>
                    </div>

                    {codesOpen && (
                        <TwoFactorRecoveryCodes
                            id="recovery-codes"
                            recoveryCodesList={recoveryCodesList}
                            fetchRecoveryCodes={fetchRecoveryCodes}
                            errors={errors}
                        />
                    )}
                </>
            ) : hasSetupData ? (
                <Button
                    size="md"
                    aria-haspopup="dialog"
                    onClick={() => setShowSetupModal(true)}
                >
                    Continue setup
                </Button>
            ) : (
                <Form
                    {...enable.form()}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setShowSetupModal(true)}
                >
                    {({ processing }) => (
                        <Button
                            type="submit"
                            size="md"
                            aria-haspopup="dialog"
                            disabled={processing}
                        >
                            Turn on two-factor
                        </Button>
                    )}
                </Form>
            )}

            <TwoFactorSetupModal
                isOpen={showSetupModal}
                onClose={() => setShowSetupModal(false)}
                requiresConfirmation={requiresConfirmation}
                qrCodeSvg={qrCodeSvg}
                manualSetupKey={manualSetupKey}
                clearSetupData={clearSetupData}
                fetchSetupData={fetchSetupData}
                recoveryCodesList={recoveryCodesList}
                fetchRecoveryCodes={fetchRecoveryCodes}
                errors={errors}
            />
        </SettingsSection>
    );
}
