import { Head, router, useForm } from '@inertiajs/react';
import { PageHeader } from '@/components/shared/PageHeader';
import { StatusBadge } from '@/components/shared/StatusBadge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { TenantAdminLayout } from '@/layouts/TenantAdminLayout';

type Domain = {
    id: string;
    hostname: string;
    type: 'SYSTEM_SUBDOMAIN' | 'CUSTOM_DOMAIN';
    status: 'PENDING_VERIFICATION' | 'VERIFIED' | 'ACTIVE';
    primary: boolean;
    verificationToken: string | null;
    sslStatus: string;
};
export default function Domains({ domains }: { domains: Domain[] }) {
    const form = useForm({ hostname: '' });
    return (
        <TenantAdminLayout>
            <Head title="Domains" />
            <div className="space-y-6">
                <PageHeader
                    eyebrow="Tenant routing"
                    title="Domains"
                    description="System domains are immutable. Custom domains require ownership verification before activation and primary selection."
                />
                <Alert>
                    <AlertTitle>Local verification adapter</AlertTitle>
                    <AlertDescription>
                        Development uses a deterministic local verifier. Production requires a real
                        DNS verification adapter and SSL provisioning before traffic is served.
                    </AlertDescription>
                </Alert>
                <Card>
                    <CardHeader>
                        <CardTitle>Add custom domain</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form
                            className="flex max-w-xl flex-col gap-3 sm:flex-row sm:items-end"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.post('/admin/domains', { onSuccess: () => form.reset() });
                            }}
                        >
                            <FormField
                                id="hostname"
                                label="Hostname"
                                description="Hostname only; no scheme, port or path."
                                error={form.errors.hostname}
                            >
                                <Input
                                    id="hostname"
                                    placeholder="cards.example.com"
                                    value={form.data.hostname}
                                    onChange={(event) =>
                                        form.setData('hostname', event.target.value)
                                    }
                                />
                            </FormField>
                            <Button disabled={form.processing}>Add domain</Button>
                        </form>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>Configured domains</CardTitle>
                    </CardHeader>
                    <CardContent className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Domain</TableHead>
                                    <TableHead>State</TableHead>
                                    <TableHead>SSL</TableHead>
                                    <TableHead>Controls</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {domains.map((domain) => (
                                    <TableRow key={domain.id}>
                                        <TableCell>
                                            <p className="font-medium">{domain.hostname}</p>
                                            <p className="text-xs text-muted-foreground">
                                                {domain.type}
                                                {domain.primary ? ' · Primary' : ''}
                                            </p>
                                            {domain.verificationToken &&
                                                domain.status === 'PENDING_VERIFICATION' && (
                                                    <code className="mt-2 block max-w-md break-all rounded bg-muted p-2 text-xs">
                                                        TXT _vc-verification.{domain.hostname} ={' '}
                                                        {domain.verificationToken}
                                                    </code>
                                                )}
                                        </TableCell>
                                        <TableCell>
                                            <StatusBadge
                                                status={
                                                    domain.status === 'ACTIVE'
                                                        ? 'SUCCESS'
                                                        : domain.status === 'VERIFIED'
                                                          ? 'INFO'
                                                          : 'WARNING'
                                                }
                                                label={domain.status}
                                            />
                                        </TableCell>
                                        <TableCell>{domain.sslStatus}</TableCell>
                                        <TableCell>
                                            <div className="flex flex-wrap gap-2">
                                                {domain.type === 'CUSTOM_DOMAIN' &&
                                                    domain.status === 'PENDING_VERIFICATION' && (
                                                        <Button
                                                            size="sm"
                                                            variant="secondary"
                                                            onClick={() =>
                                                                router.post(
                                                                    `/admin/domains/${domain.id}/verify`,
                                                                )
                                                            }
                                                        >
                                                            Check verification
                                                        </Button>
                                                    )}
                                                {domain.type === 'CUSTOM_DOMAIN' &&
                                                    domain.status === 'VERIFIED' && (
                                                        <Button
                                                            size="sm"
                                                            onClick={() =>
                                                                router.post(
                                                                    `/admin/domains/${domain.id}/activate`,
                                                                )
                                                            }
                                                        >
                                                            Activate
                                                        </Button>
                                                    )}
                                                {domain.status === 'ACTIVE' && !domain.primary && (
                                                    <Button
                                                        size="sm"
                                                        variant="secondary"
                                                        onClick={() =>
                                                            router.post(
                                                                `/admin/domains/${domain.id}/primary`,
                                                            )
                                                        }
                                                    >
                                                        Make primary
                                                    </Button>
                                                )}
                                                {domain.type === 'CUSTOM_DOMAIN' &&
                                                    !domain.primary && (
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            onClick={() =>
                                                                router.delete(
                                                                    `/admin/domains/${domain.id}`,
                                                                )
                                                            }
                                                        >
                                                            Remove
                                                        </Button>
                                                    )}
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </TenantAdminLayout>
    );
}
