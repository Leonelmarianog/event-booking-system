import type { ReactNode } from 'react';

export type AppLayoutProps = {
    children: ReactNode;
};

export type FlashToast = {
    type: 'success' | 'info' | 'warning' | 'error';
    message: string;
};

export type AuthPanelTint = 'lime' | 'violet' | 'orange' | 'green';

export type AuthPanel = {
    tint: AuthPanelTint;
    headline: string;
    tagline?: string;
};

export type AuthLayoutProps = {
    children?: ReactNode;
    title?: string;
    description?: string;
    panel?: AuthPanel;
};
