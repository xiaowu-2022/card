import { Head } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { UserLayout } from '@/layouts/UserLayout';

export default function Restricted({ tenantRestricted }: { tenantRestricted: boolean }) {
    return (
        <UserLayout>
            <Head title="Account restricted" />
            <div className="mx-auto max-w-2xl space-y-6">
                <Alert>
                    <AlertTriangle className="size-5" />
                    <AlertTitle>Your account is currently restricted.</AlertTitle>
                    <AlertDescription>
                        {tenantRestricted
                            ? 'This service is temporarily restricted for all users of this organization.'
                            : 'Your account has limited access. You can still review account security and change your password.'}
                    </AlertDescription>
                </Alert>
                <Card>
                    <CardHeader>
                        <CardTitle>What you can do</CardTitle>
                    </CardHeader>
                    <CardContent className="text-sm text-muted-foreground">
                        <p>
                            Review account details, change your password, contact support, or sign
                            out. Financial and card operations are unavailable.
                        </p>
                    </CardContent>
                </Card>
            </div>
        </UserLayout>
    );
}
