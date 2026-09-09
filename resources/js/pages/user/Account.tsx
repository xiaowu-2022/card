import { Head, usePage } from '@inertiajs/react';
import { PageHeader } from '@/components/shared/PageHeader';
import { StatusBadge } from '@/components/shared/StatusBadge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { UserLayout } from '@/layouts/UserLayout';
import type { SharedProps } from '@/types/global';

export default function Account() {
    const { auth } = usePage<SharedProps>().props;
    return (
        <UserLayout>
            <Head title="Account" />
            <div className="space-y-6">
                <PageHeader
                    eyebrow="Profile"
                    title="Your account"
                    description="Contact changes require a future verification workflow and are not available yet."
                />
                <Card>
                    <CardHeader>
                        <CardTitle>Account details</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-5 sm:grid-cols-2">
                        <div>
                            <p className="text-sm text-muted-foreground">Display name</p>
                            <p className="mt-1 font-medium">
                                {auth.user?.displayName ?? 'Not set'}
                            </p>
                        </div>
                        <div>
                            <p className="text-sm text-muted-foreground">Status</p>
                            <div className="mt-1">
                                <StatusBadge
                                    status={
                                        auth.user?.status === 'SUSPENDED' ? 'WARNING' : 'SUCCESS'
                                    }
                                    label={auth.user?.status ?? 'Unknown'}
                                />
                            </div>
                        </div>
                        <div>
                            <p className="text-sm text-muted-foreground">Email</p>
                            <p className="mt-1 font-medium">{auth.user?.email ?? 'Not added'}</p>
                        </div>
                        <div>
                            <p className="text-sm text-muted-foreground">Phone</p>
                            <p className="mt-1 font-medium">{auth.user?.phone ?? 'Not added'}</p>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </UserLayout>
    );
}
