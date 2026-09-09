import { Head } from '@inertiajs/react';
import { PageHeader } from '@/components/shared/PageHeader';
import { Card, CardContent } from '@/components/ui/card';
import { UserLayout } from '@/layouts/UserLayout';

export default function DemoWallet() {
    return (
        <UserLayout>
            <Head title="Demo wallet" />
            <div className="space-y-6">
                <PageHeader
                    eyebrow="Local demo only"
                    title="Mock wallet preview"
                    description="This isolated page contains no production balance or transaction data."
                />
                <Card>
                    <CardContent className="p-6 text-sm text-muted-foreground">
                        MOCK / TEST presentation only. Sign in and open /wallet for the real
                        ledger-backed wallet.
                    </CardContent>
                </Card>
            </div>
        </UserLayout>
    );
}
