import { Form, Head } from '@inertiajs/react';
import { useRef } from 'react';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import { FieldLabel } from '@/components/field-label';
import InputError from '@/components/input-error';
import type { Props as ManagePasskeysProps } from '@/components/manage-passkeys';
import ManagePasskeys from '@/components/manage-passkeys';
import type { Props as ManageTwoFactorProps } from '@/components/manage-two-factor';
import ManageTwoFactor from '@/components/manage-two-factor';
import PasswordInput from '@/components/password-input';
import { SettingsSection } from '@/components/settings-section';
import { Button } from '@/components/ui/button';

// oxfmt-ignore
type Props = {
    passwordRules: string;
} & ManagePasskeysProps &
    ManageTwoFactorProps;

export default function Security(props: Props) {
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);

    return (
        <>
            <Head title="Security settings" />

            <SettingsSection
                id="password-heading"
                title="Update password"
                description="Use a long, random password to keep your account safe."
            >
                <Form
                    {...SecurityController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    resetOnError={[
                        'password',
                        'password_confirmation',
                        'current_password',
                    ]}
                    resetOnSuccess
                    onError={(errors) => {
                        if (errors.password) {
                            passwordInput.current?.focus();
                        }

                        if (errors.current_password) {
                            currentPasswordInput.current?.focus();
                        }
                    }}
                    className="flex flex-col gap-6"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2">
                                <FieldLabel htmlFor="current_password">
                                    Current password
                                </FieldLabel>
                                <PasswordInput
                                    id="current_password"
                                    ref={currentPasswordInput}
                                    name="current_password"
                                    size="lg"
                                    required
                                    autoComplete="current-password"
                                />
                                <InputError message={errors.current_password} />
                            </div>

                            <div className="grid gap-6 md:grid-cols-2">
                                <div className="grid content-start gap-2">
                                    <FieldLabel htmlFor="password">
                                        New password
                                    </FieldLabel>
                                    <PasswordInput
                                        id="password"
                                        ref={passwordInput}
                                        name="password"
                                        size="lg"
                                        required
                                        autoComplete="new-password"
                                        passwordrules={props.passwordRules}
                                    />
                                    <InputError message={errors.password} />
                                </div>

                                <div className="grid content-start gap-2">
                                    <FieldLabel htmlFor="password_confirmation">
                                        Confirm password
                                    </FieldLabel>
                                    <PasswordInput
                                        id="password_confirmation"
                                        name="password_confirmation"
                                        size="lg"
                                        required
                                        autoComplete="new-password"
                                        passwordrules={props.passwordRules}
                                    />
                                    <InputError
                                        message={errors.password_confirmation}
                                    />
                                </div>
                            </div>

                            <div>
                                <Button
                                    size="lg"
                                    disabled={processing}
                                    data-test="update-password-button"
                                >
                                    Save password
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </SettingsSection>

            <ManageTwoFactor
                canManageTwoFactor={props.canManageTwoFactor}
                requiresConfirmation={props.requiresConfirmation}
                twoFactorEnabled={props.twoFactorEnabled}
            />

            <ManagePasskeys
                canManagePasskeys={props.canManagePasskeys}
                passkeys={props.passkeys}
            />
        </>
    );
}
