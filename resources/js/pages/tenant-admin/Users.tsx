import { Head } from '@inertiajs/react';
import { AdminTableDemo } from '@/components/admin/AdminTableDemo';
import { PageHeader } from '@/components/shared/PageHeader';
import { Button } from '@/components/ui/button';
import { TenantAdminLayout } from '@/layouts/TenantAdminLayout';

export default function Users() {
    return (
        <TenantAdminLayout>
            <Head title="Users" />
            <div className="space-y-6">
                <PageHeader
                    eyebrow="Demo directory"
                    title="Users"
                    description="Search, filter, table, pagination, loading, empty and error patterns share one design language."
                    actions={<Button disabled>Add user</Button>}
                />
                <AdminTableDemo />
            </div>
        </TenantAdminLayout>
    );
}
