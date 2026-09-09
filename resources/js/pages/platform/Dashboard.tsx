import { Head } from '@inertiajs/react';
import { Activity, Building2, CreditCard, ServerCog } from 'lucide-react';
import { PageHeader } from '@/components/shared/PageHeader';
import { StatusBadge } from '@/components/shared/StatusBadge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { PlatformLayout } from '@/layouts/PlatformLayout';

const metrics = [
    { label: 'Active tenants', value: '24', icon: Building2 },
    { label: 'Demo active cards', value: '18.4K', icon: CreditCard },
    { label: 'Provider health', value: '2 / 2', icon: ServerCog },
    { label: 'Unknown operations', value: '7', icon: Activity },
];
export default function Dashboard() {
    return (
        <PlatformLayout>
            <Head title="Platform overview" />
            <div className="space-y-6">
                <PageHeader
                    eyebrow="Platform scope"
                    title="Control center"
                    description="Cross-tenant operational overview using mock metrics only."
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
                                <p className="text-xs text-muted-foreground">Static demo data</p>
                            </CardContent>
                        </Card>
                    ))}
                </div>
                <Card>
                    <CardHeader>
                        <CardTitle>Provider operations</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {[
                            {
                                provider: 'Mock Provider EU',
                                state: 'Operational',
                                tone: 'SUCCESS' as const,
                            },
                            {
                                provider: 'Mock Provider US',
                                state: 'Reconciling unknown results',
                                tone: 'WARNING' as const,
                            },
                        ].map((row) => (
                            <div
                                key={row.provider}
                                className="flex flex-col gap-2 border-b pb-4 last:border-0 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div>
                                    <p className="font-medium">{row.provider}</p>
                                    <p className="text-sm text-muted-foreground">
                                        TEST connection · no external API
                                    </p>
                                </div>
                                <StatusBadge status={row.tone} label={row.state} />
                            </div>
                        ))}
                    </CardContent>
                </Card>
            </div>
        </PlatformLayout>
    );
}
