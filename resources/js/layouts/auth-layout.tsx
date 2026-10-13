import AuthSplitLayout from '@/layouts/auth/auth-split-layout';
import type { AuthLayoutProps } from '@/types';

export default function AuthLayout({
    title = '',
    description = '',
    panel,
    children,
}: AuthLayoutProps) {
    return (
        <AuthSplitLayout title={title} description={description} panel={panel}>
            {children}
        </AuthSplitLayout>
    );
}
