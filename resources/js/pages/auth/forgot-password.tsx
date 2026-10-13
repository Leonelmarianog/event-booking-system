import { Form, Head } from '@inertiajs/react';
import { FieldLabel } from '@/components/field-label';
import InputError from '@/components/input-error';
import { StatusMessage } from '@/components/status-message';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { email } from '@/routes/password';

export default function ForgotPassword({ status }: { status?: string }) {
    return (
        <>
            <Head title="Forgot password" />

            {status && <StatusMessage className="mb-6">{status}</StatusMessage>}

            <Form {...email.form()} className="flex flex-col gap-5">
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-2">
                            <FieldLabel htmlFor="email">
                                Email address
                            </FieldLabel>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                size="lg"
                                required
                                autoFocus
                                autoComplete="email"
                                placeholder="email@example.com"
                            />
                            <InputError message={errors.email} />
                        </div>

                        <Button
                            size="lg"
                            className="w-full"
                            disabled={processing}
                            data-test="email-password-reset-link-button"
                        >
                            {processing && <Spinner />}
                            Email reset link
                        </Button>
                    </>
                )}
            </Form>

            <p className="mt-7 text-center text-muted-foreground">
                Or, return to{' '}
                <TextLink href={login()} variant="accent">
                    log in
                </TextLink>
            </p>
        </>
    );
}

ForgotPassword.layout = {
    title: 'Forgot password',
    description:
        "Enter your email and we'll send you a link to choose a new password.",
    panel: {
        tint: 'orange',
        headline: 'It\nhappens.',
    },
};
