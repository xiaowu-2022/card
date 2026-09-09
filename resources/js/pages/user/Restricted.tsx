import { Head } from '@inertiajs/react';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { UserStatusBanner } from '@/components/user/UserStatusBanner';
import { UserLayout } from '@/layouts/UserLayout';

export default function Restricted({ tenantRestricted }: { tenantRestricted: boolean }) {
    return (
        <UserLayout>
            <Head title="Account restricted" />
            <div className="mx-auto max-w-2xl space-y-6">
                <UserPageHeader title="Account" />
                <UserStatusBanner
                    tone="warning"
                    title="Your account is currently restricted"
                    description={
                        tenantRestricted
                            ? 'This service is temporarily restricted for all users of your organization.'
                            : 'You can still review account security and change your password.'
                    }
                    action={{ label: 'Review security', href: '/account/security' }}
                />
                <p className="px-1 text-sm leading-6 text-muted-foreground">
                    Financial and card actions are unavailable while access is restricted. Contact
                    support if you need help.
                </p>
            </div>
        </UserLayout>
    );
}
