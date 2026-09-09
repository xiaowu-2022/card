import { Head } from '@inertiajs/react';
import { UserEmptyState } from '@/components/user/UserEmptyState';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { UserLayout } from '@/layouts/UserLayout';

export default function Cards() {
    return (
        <UserLayout>
            <Head title="Cards" />
            <div className="space-y-6">
                <UserPageHeader title="Cards" backHref="/dashboard" />
                <div>
                    <UserEmptyState
                        title="Virtual cards will be available here"
                        description="There are no cards on this account."
                    />
                </div>
            </div>
        </UserLayout>
    );
}
