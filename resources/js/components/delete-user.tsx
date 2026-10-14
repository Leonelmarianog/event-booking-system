import { Form } from '@inertiajs/react';
import { useRef } from 'react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import { FieldLabel } from '@/components/field-label';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { SettingsSection } from '@/components/settings-section';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';

export default function DeleteUser() {
    const passwordInput = useRef<HTMLInputElement>(null);

    return (
        <SettingsSection
            id="delete-heading"
            title="Delete account"
            description='Delete your account. Past events and bookings stay, with the name "Deleted user".'
            tone="danger"
        >
            <Dialog>
                <DialogTrigger asChild>
                    <Button
                        variant="destructive"
                        size="md"
                        data-test="delete-user-button"
                    >
                        Delete account
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <DialogTitle>Delete your account?</DialogTitle>
                    <DialogDescription>
                        Your drafts are deleted, and your name, email and
                        password are removed. Past events and bookings stay,
                        with the name &quot;Deleted user&quot;. You cannot undo
                        this. Enter your password to confirm.
                    </DialogDescription>

                    <Form
                        {...ProfileController.destroy.form()}
                        options={{
                            preserveScroll: true,
                        }}
                        onError={() => passwordInput.current?.focus()}
                        resetOnSuccess
                        className="mt-3 grid gap-2"
                    >
                        {({ resetAndClearErrors, processing, errors }) => (
                            <>
                                <FieldLabel htmlFor="password">
                                    Password
                                </FieldLabel>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    ref={passwordInput}
                                    size="lg"
                                    required
                                    autoComplete="current-password"
                                    className="bg-background"
                                />
                                <InputError message={errors.password} />

                                <DialogFooter className="mt-5">
                                    <DialogClose asChild>
                                        <Button
                                            variant="outline"
                                            size="md"
                                            onClick={() =>
                                                resetAndClearErrors()
                                            }
                                        >
                                            Keep account
                                        </Button>
                                    </DialogClose>
                                    <Button
                                        type="submit"
                                        variant="destructive"
                                        size="md"
                                        disabled={processing}
                                        data-test="confirm-delete-user-button"
                                    >
                                        Delete account
                                    </Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>
        </SettingsSection>
    );
}
