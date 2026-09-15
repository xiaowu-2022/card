import { t, useClientTranslation } from '@/i18n';
import { Head } from '@inertiajs/react';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { UserStatusBanner } from '@/components/user/UserStatusBanner';
import { UserLayout } from '@/layouts/UserLayout';

export default function Restricted({ tenantRestricted }: { tenantRestricted: boolean }) {
    useClientTranslation();
    return (
        <UserLayout>
            <Head title={t('Account restricted')} />
            <div className="mx-auto max-w-2xl space-y-6">
                <UserPageHeader title={t('Account')} />
                <UserStatusBanner
                    tone="warning"
                    title={t('Your account is currently restricted')}
                    description={
                        tenantRestricted
                            ? t(
                                  'This service is temporarily restricted for all users of your organization.',
                              )
                            : t('You can still review account security and change your password.')
                    }
                    action={{ label: t('Review security'), href: '/account/security' }}
                />
                <p className="px-1 text-sm leading-6 text-muted-foreground">
                    {t(
                        'Financial and card actions are unavailable while access is restricted. Contact support if you need help.',
                    )}
                </p>
            </div>
        </UserLayout>
    );
}
