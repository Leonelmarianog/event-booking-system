import { Form, Head } from '@inertiajs/react';
import { FieldLabel } from '@/components/field-label';
import InputError from '@/components/input-error';
import PasskeyVerify from '@/components/passkey-verify';
import PasswordInput from '@/components/password-input';
import { StatusMessage } from '@/components/status-message';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Props = {
    status?: string;
    canResetPassword: boolean;
};

export default function Login({ status, canResetPassword }: Props) {
    return (
        <>
            <Head title="Log in" />

            {status && <StatusMessage className="mb-6">{status}</StatusMessage>}

            <PasskeyVerify />

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-5"
            >
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
                                autoComplete="username"
                                placeholder="email@example.com"
                            />
                            <InputError message={errors.email} />
                        </div>

                        <div className="grid gap-2">
                            <div className="flex items-baseline justify-between">
                                <FieldLabel htmlFor="password">
                                    Password
                                </FieldLabel>
                                {canResetPassword && (
                                    <TextLink
                                        href={request()}
                                        variant="accent"
                                        className="text-sm"
                                    >
                                        Forgot password?
                                    </TextLink>
                                )}
                            </div>
                            <PasswordInput
                                id="password"
                                name="password"
                                size="lg"
                                required
                                autoComplete="current-password"
                            />
                            <InputError message={errors.password} />
                        </div>

                        <div className="flex min-h-11 items-center gap-3">
                            <Checkbox
                                id="remember"
                                name="remember"
                                className="size-5"
                            />
                            <label
                                htmlFor="remember"
                                className="cursor-pointer"
                            >
                                Remember me
                            </label>
                        </div>

                        <Button
                            type="submit"
                            size="lg"
                            className="w-full"
                            disabled={processing}
                            data-test="login-button"
                        >
                            {processing && <Spinner />}
                            Log in
                        </Button>
                    </>
                )}
            </Form>

            <p className="mt-7 text-center text-muted-foreground">
                No account yet?{' '}
                <TextLink href={register()} variant="accent">
                    Register
                </TextLink>
            </p>
        </>
    );
}

Login.layout = {
    title: 'Log in',
    description: 'Enter your email and password.',
    panel: {
        tint: 'lime',
        headline: 'Welcome\nback.',
        tagline:
            "Your seats, your events and what's on next are one login away.",
    },
};
