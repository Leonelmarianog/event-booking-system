import { Form, Head, usePage } from '@inertiajs/react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/delete-user';
import { FieldLabel } from '@/components/field-label';
import InputError from '@/components/input-error';
import { SettingsSection } from '@/components/settings-section';
import { StatusMessage } from '@/components/status-message';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { send } from '@/routes/verification';
import type { Auth } from '@/types';

type PageProps = {
    auth: Auth;
};

export default function Profile({
    mustVerifyEmail,
    status,
}: {
    mustVerifyEmail: boolean;
    status?: string;
}) {
    const { auth } = usePage<PageProps>().props;

    return (
        <>
            <Head title="Profile settings" />

            <SettingsSection
                id="profile-heading"
                title="Profile"
                description="Update your name and email address."
            >
                <Form
                    {...ProfileController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    className="flex flex-col gap-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <FieldLabel htmlFor="name">Name</FieldLabel>
                                <Input
                                    id="name"
                                    name="name"
                                    size="lg"
                                    defaultValue={auth.user.name}
                                    required
                                    autoComplete="name"
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <FieldLabel htmlFor="email">
                                    Email address
                                </FieldLabel>
                                <Input
                                    id="email"
                                    name="email"
                                    type="email"
                                    size="lg"
                                    defaultValue={auth.user.email}
                                    required
                                    autoComplete="email"
                                />
                                <InputError message={errors.email} />

                                {mustVerifyEmail &&
                                    auth.user.email_verified_at === null && (
                                        <p className="text-muted-foreground">
                                            Your email address is not verified.{' '}
                                            <TextLink
                                                href={send()}
                                                as="button"
                                                variant="accent"
                                            >
                                                Send the link again
                                            </TextLink>
                                        </p>
                                    )}
                            </div>

                            {status === 'verification-link-sent' && (
                                <StatusMessage>
                                    A new verification link is on its way.
                                </StatusMessage>
                            )}

                            <div>
                                <Button
                                    size="lg"
                                    disabled={processing}
                                    data-test="update-profile-button"
                                >
                                    Save
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </SettingsSection>

            <DeleteUser />
        </>
    );
}
