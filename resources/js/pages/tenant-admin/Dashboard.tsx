import { Head } from '@inertiajs/react';
import { CreditCard, FileCheck2, Users, WalletCards } from 'lucide-react';
import { PageHeader } from '@/components/shared/PageHeader';
import { StatusBadge } from '@/components/shared/StatusBadge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { TenantAdminLayout } from '@/layouts/TenantAdminLayout';

const metrics = [
    { label: 'Demo users', value: '2,418', change: '+4.2%', icon: Users },
    { label: 'KYC review queue', value: '18', change: 'Mock', icon: FileCheck2 },
    { label: 'Active cards', value: '1,904', change: 'Mock', icon: CreditCard },
    { label: 'Wallet volume', value: '$842K', change: 'Mock', icon: WalletCards },
];
export default function Dashboard() {
    return (
        <TenantAdminLayout>
            <Head title="Tenant admin" />
            <div className="space-y-6">
                <PageHeader
                    eyebrow="Tenant administration"
                    title="Overview"
                    description="Operational patterns shown with static demo data."
                />
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {metrics.map((metric) => (
                        <Card key={metric.label}>
                            <CardHeader>
                                <div className="flex justify-between">
                                    <p className="text-sm text-muted-foreground">{metric.label}</p>
                                    <metric.icon className="size-5 text-muted-foreground" />
                                </div>
                                <CardTitle className="mt-2 text-2xl">{metric.value}</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <span className="text-xs font-semibold text-primary">
                                    {metric.change}
                                </span>
                            </CardContent>
                        </Card>
                    ))}
                </div>
                <div className="grid gap-4 xl:grid-cols-[1.5fr_1fr]">
                    <Card>
                        <CardHeader>
                            <CardTitle>Account operations</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {[
                                {
                                    label: 'KYC review turnaround',
                                    value: '4h 12m',
                                    status: <StatusBadge status="SUCCESS" label="Within target" />,
                                },
                                {
                                    label: 'Provider operations',
                                    value: '3 unknown',
                                    status: <StatusBadge status="WARNING" label="Needs review" />,
                                },
                                {
                                    label: 'Support queue',
                                    value: '12 open',
                                    status: <StatusBadge status="INFO" label="Normal" />,
                                },
                            ].map((item) => (
                                <div
                                    key={item.label}
                                    className="grid grid-cols-[1fr_auto] items-center gap-4 border-b pb-4 last:border-0"
                                >
                                    <div>
                                        <p className="font-medium">{item.label}</p>
                                        <p className="text-sm text-muted-foreground">
                                            {item.value} · Demo
                                        </p>
                                    </div>
                                    {item.status}
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>System boundaries</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm text-muted-foreground">
                            <p>Tenant scope is resolved from the host.</p>
                            <p>Administrative permissions never include balance modification.</p>
                            <p>Provider timeouts remain UNKNOWN until reconciled.</p>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </TenantAdminLayout>
    );
}
