import { Head, Link, router } from '@inertiajs/react';
import { ChevronRight, Info, LogOut } from 'lucide-react';
import { t, useClientTranslation } from '@/i18n';
import { UserLayout } from '@/layouts/UserLayout';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { LanguageSwitcher } from '@/components/user/LanguageSwitcher';

export default function AccountSettings() {
    useClientTranslation();
    return (
        <UserLayout>
            <Head title={t('Settings')} />
            <UserPageHeader title={t('Settings')} backHref="/account" />
            <div className="user-settings-list">
                <LanguageSwitcher variant="row" />
                <Link href="/about" className="user-settings-row">
                    <Info
                        className="size-5 shrink-0 text-[var(--user-primary)]"
                        aria-hidden="true"
                    />
                    <span className="min-w-0 flex-1">{t('About us')}</span>
                    <ChevronRight
                        className="size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                </Link>
            </div>
            <button type="button" className="user-signout" onClick={() => router.post('/logout')}>
                <LogOut className="size-5" aria-hidden="true" />
                {t('Log out')}
            </button>
        </UserLayout>
    );
}
