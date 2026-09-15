import { Link, usePage } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';
import type { ReactNode } from 'react';
import { t, useClientTranslation } from '@/i18n';
import { useLocaleSync } from '@/i18n/useLocaleSync';
import { userThemeStyle } from '@/lib/user-theme';
import type { SharedProps } from '@/types/global';

export function UserArticleLayout({
    title,
    backHref,
    children,
}: {
    title: string;
    backHref: string;
    children: ReactNode;
}) {
    useLocaleSync();
    useClientTranslation();
    const { tenant } = usePage<SharedProps>().props;
    return (
        <div
            className="user-theme min-h-screen bg-[var(--user-canvas)] text-foreground"
            style={userThemeStyle(tenant?.branding.primaryColor)}
        >
            <div className="user-shell mx-auto min-h-screen">
                <header className="user-article-header">
                    <Link href={backHref} className="user-article-back" aria-label={t('Back')}>
                        <ChevronLeft aria-hidden="true" />
                    </Link>
                    <h1>{title}</h1>
                </header>
                <main className="user-article-main">{children}</main>
            </div>
        </div>
    );
}
