import { t, useClientTranslation } from '@/i18n';
import { Head } from '@inertiajs/react';
import { PageHeader } from '@/components/shared/PageHeader';
import { Card, CardContent } from '@/components/ui/card';
import { UserLayout } from '@/layouts/UserLayout';

export default function DemoWallet() {
    useClientTranslation();
    return (
        <UserLayout>
            <Head title={t('Demo wallet')} />
            <div className="space-y-6">
                <PageHeader
                    eyebrow="Local demo only"
                    title={t('Mock wallet preview')}
                    description={t(
                        'This isolated page contains no production balance or transaction data.',
                    )}
                />
                <Card>
                    <CardContent className="p-6 text-sm text-muted-foreground">
                        {t(
                            'MOCK / TEST presentation only. Sign in and open /wallet for the real ledger-backed wallet.',
                        )}
                    </CardContent>
                </Card>
            </div>
        </UserLayout>
    );
}
