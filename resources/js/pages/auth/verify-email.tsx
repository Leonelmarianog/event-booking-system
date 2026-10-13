import { Form, Head, Link, usePage } from '@inertiajs/react';
import { StatusMessage } from '@/components/status-message';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

export default function VerifyEmail({ status }: { status?: string }) {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Verify your email" />

            <p className="mt-2 mb-6 text-[#55544f] dark:text-[#b9b7ae]">
                We sent a link to{' '}
                <strong className="break-words text-foreground">
                    {auth.user.email}
                </strong>
                . Click it to finish setting up your account.
            </p>

            {status === 'verification-link-sent' && (
                <StatusMessage className="mb-6">
                    A new verification link is on its way.
                </StatusMessage>
            )}

            <div className="flex flex-col gap-3">
                <Form {...send.form()}>
                    {({ processing }) => (
                        <Button
                            size="lg"
                            className="w-full"
                            disabled={processing}
                        >
                            {processing && <Spinner />}
                            Resend verification email
                        </Button>
                    )}
                </Form>

                <Link
                    href={logout()}
                    as="button"
                    className="min-h-12 text-sm font-semibold tracking-[0.08em] text-muted-foreground uppercase hover:text-foreground"
                >
                    Log out
                </Link>
            </div>
        </>
    );
}

VerifyEmail.layout = {
    title: 'Verify your email',
    panel: {
        tint: 'lime',
        headline: 'Check\nyour\ninbox.',
    },
};
