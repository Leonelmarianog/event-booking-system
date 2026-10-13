import { Form, Head } from '@inertiajs/react';
import { FieldLabel } from '@/components/field-label';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { store } from '@/routes/register';

type Props = {
    passwordRules: string;
};

export default function Register({ passwordRules }: Props) {
    return (
        <>
            <Head title="Register" />

            <Form
                {...store.form()}
                resetOnSuccess={['password', 'password_confirmation']}
                disableWhileProcessing
                className="flex flex-col gap-5"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-2">
                            <FieldLabel htmlFor="name">Name</FieldLabel>
                            <Input
                                id="name"
                                type="text"
                                name="name"
                                size="lg"
                                required
                                autoFocus
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
                                type="email"
                                name="email"
                                size="lg"
                                required
                                autoComplete="email"
                                placeholder="email@example.com"
                            />
                            <InputError message={errors.email} />
                        </div>

                        <div className="grid gap-2">
                            <FieldLabel htmlFor="password">Password</FieldLabel>
                            <PasswordInput
                                id="password"
                                name="password"
                                size="lg"
                                required
                                autoComplete="new-password"
                                passwordrules={passwordRules}
                            />
                            <InputError message={errors.password} />
                        </div>

                        <div className="grid gap-2">
                            <FieldLabel htmlFor="password_confirmation">
                                Confirm password
                            </FieldLabel>
                            <PasswordInput
                                id="password_confirmation"
                                name="password_confirmation"
                                size="lg"
                                required
                                autoComplete="new-password"
                                passwordrules={passwordRules}
                            />
                            <InputError
                                message={errors.password_confirmation}
                            />
                        </div>

                        <Button
                            type="submit"
                            size="lg"
                            className="mt-2 w-full"
                            data-test="register-user-button"
                        >
                            {processing && <Spinner />}
                            Create account
                        </Button>
                    </>
                )}
            </Form>

            <p className="mt-7 text-center text-muted-foreground">
                Already have an account?{' '}
                <TextLink href={login()} variant="accent">
                    Log in
                </TextLink>
            </p>
        </>
    );
}

Register.layout = {
    title: 'Register',
    description: 'Create an account. It takes a minute.',
    panel: {
        tint: 'violet',
        headline: 'Go out.\nFind your\npeople.',
        tagline: 'Book seats at events near you, or host your own.',
    },
};
