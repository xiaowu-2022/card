import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, LockKeyhole } from 'lucide-react';
import type { ReactNode } from 'react';
import { t, useClientTranslation } from '@/i18n';
import { useLocaleSync } from '@/i18n/useLocaleSync';
import { LanguageSwitcher } from '@/components/user/LanguageSwitcher';
import type { SharedProps } from '@/types/global';
import { userThemeStyle } from '@/lib/user-theme';

export function PasswordRecoveryLayout({
    title,
    children,
}: {
    title: string;
    children: ReactNode;
}) {
    useClientTranslation();
    useLocaleSync();
    const { tenant } = usePage<SharedProps>().props;
    return (
        <div
            className="user-theme min-h-screen bg-background text-foreground"
            style={userThemeStyle(tenant?.branding.primaryColor)}
        >
            <Head title={title} />
            <main className="user-auth-shell">
                <div className="flex items-center justify-between px-4 pt-4">
                    <Link
                        href="/login"
                        aria-label={t('Back to sign in')}
                        className="grid size-11 place-items-center"
                    >
                        <ArrowLeft className="size-5" />
                    </Link>
                    <LanguageSwitcher />
                </div>
                <div className="mx-auto max-w-lg px-5 pt-8 pb-12 sm:px-8">
                    <LockKeyhole
                        className="mb-5 size-8 text-[var(--user-primary)]"
                        aria-hidden="true"
                    />
                    <h1 className="mb-6 text-2xl font-semibold">{title}</h1>
                    {children}
                </div>
            </main>
        </div>
    );
}
