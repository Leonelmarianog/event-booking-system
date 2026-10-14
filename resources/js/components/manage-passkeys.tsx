import { router } from '@inertiajs/react';
import { destroy } from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyRegistrationController';
import PasskeyItem from '@/components/passkey-item';
import PasskeyRegistration from '@/components/passkey-register';
import { SettingsSection } from '@/components/settings-section';
import type { Passkey } from '@/types/auth';

export type Props = {
    canManagePasskeys?: boolean;
    passkeys?: Passkey[];
};

export default function ManagePasskeys(props: Props) {
    const passkeys = props.passkeys ?? [];

    const handleDelete = (id: number, onError: () => void) => {
        router.delete(destroy.url(id), {
            preserveScroll: true,
            onError,
        });
    };

    const handleRegisterSuccess = () => {
        router.reload();
    };

    if (!(props.canManagePasskeys ?? false)) {
        return null;
    }

    return (
        <SettingsSection
            id="passkeys-heading"
            title="Passkeys"
            description="Log in without a password, with your fingerprint, face or device PIN."
        >
            {passkeys.length > 0 ? (
                <ul className="mb-6 border-t border-border">
                    {passkeys.map((passkey) => (
                        <PasskeyItem
                            key={passkey.id}
                            passkey={passkey}
                            onDelete={handleDelete}
                        />
                    ))}
                </ul>
            ) : (
                <p className="mb-6 text-muted-foreground">No passkeys yet.</p>
            )}

            <PasskeyRegistration onSuccess={handleRegisterSuccess} />
        </SettingsSection>
    );
}
