import { Head, Link, useForm, usePage } from '@inertiajs/react';
import {
    PhotonPayAccountForm,
    type PhotonPayConfiguration,
} from '@/components/shared/PhotonPayAccountForm';
import { useState } from 'react';
import { useAdminTranslation, t, dateTime, errorMessage } from '@/i18n/admin';
import { PageHeader } from '@/components/shared/PageHeader';
import { EmptyState } from '@/components/shared/EmptyState';
import { MoneyDisplay } from '@/components/shared/MoneyDisplay';
import type { AccountPage } from '@/components/shared/PlatformAccountTable';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import {
    Table,
    TableHeader,
    TableBody,
    TableRow,
    TableHead,
    TableCell,
} from '@/components/ui/table';
import type { SharedProps } from '@/types/global';

type Reference = {
    photonpay: PhotonPayConfiguration | null;
    id: string;
    name: string;
    referenceBalance: string | null;
    asset: string;
    version: number;
    localMock: boolean;
    updatedAt: string | null;
    apiConnected: boolean;
    sandbox: boolean;
    issuedCardCount: string | null;
};

function ReferenceForm({ record, close }: { record: Reference | null; close: () => void }) {
    const form = useForm({
        name: record?.name ?? '',
        reference_balance: record?.referenceBalance?.replace(/(\.\d{2})0+$/, '$1') ?? '0.00',
        request_id: crypto.randomUUID(),
        version: record?.version ?? 0,
    });
    return (
        <form
            className="space-y-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.transform((data) =>
                    record
                        ? {
                              name: data.name,
                              ...(record.apiConnected
                                  ? {}
                                  : { reference_balance: data.reference_balance }),
                              version: data.version,
                          }
                        : {
                              name: data.name,
                              reference_balance: data.reference_balance,
                              request_id: data.request_id,
                          },
                );
                if (record) form.put(`/platform/card-providers/${record.id}`, { onSuccess: close });
                else form.post('/platform/card-providers', { onSuccess: close });
            }}
        >
            <div className="space-y-2">
                <label className="text-sm font-medium" htmlFor="reference-name">
                    {t('Card provider name')}
                </label>
                <Input
                    id="reference-name"
                    autoFocus
                    maxLength={120}
                    required
                    value={form.data.name}
                    onChange={(event) => form.setData('name', event.target.value)}
                    disabled={form.processing}
                />
            </div>
            {!record?.apiConnected && (
                <div className="space-y-2">
                    <label className="text-sm font-medium" htmlFor="reference-balance">
                        {t('Reference balance (USDT)')}
                    </label>
                    <Input
                        id="reference-balance"
                        inputMode="decimal"
                        required
                        value={form.data.reference_balance}
                        onChange={(event) => form.setData('reference_balance', event.target.value)}
                        disabled={form.processing}
                    />
                </div>
            )}
            {record?.apiConnected && (
                <p className="text-sm text-muted-foreground">{t('API balances are read-only.')}</p>
            )}
            {Object.entries(form.errors).map(([key, message]) => (
                <p key={key} role="alert" className="text-sm text-destructive">
                    {errorMessage(message)}
                </p>
            ))}
            <div className="flex justify-end gap-2">
                <Button
                    type="button"
                    variant="secondary"
                    onClick={close}
                    disabled={form.processing}
                >
                    {t('Cancel')}
                </Button>
                <Button type="submit" disabled={form.processing}>
                    {form.processing ? t('Saving…') : t('Save')}
                </Button>
            </div>
        </form>
    );
}

export default function CardProviders({ providers }: { providers: AccountPage<Reference> }) {
    useAdminTranslation();
    const canManage = usePage<SharedProps>().props.auth.admin?.permissions.includes(
        'card_provider_reference.manage',
    );
    const [editing, setEditing] = useState<Reference | 'new' | null>(null);
    return (
        <PlatformLayout>
            <Head title={t('Card providers')} />
            <div className="space-y-6">
                <PageHeader
                    eyebrow={t('Operations')}
                    title={t('Card providers')}
                    actions={
                        canManage ? (
                            <Button onClick={() => setEditing('new')}>
                                {t('Add card provider')}
                            </Button>
                        ) : undefined
                    }
                />
                {providers.data.length === 0 ? (
                    <EmptyState
                        title={t('No card providers')}
                        description={t('No card providers are preloaded.')}
                    />
                ) : (
                    <div className="overflow-x-auto rounded-xl border bg-surface">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('Card provider name')}</TableHead>
                                    <TableHead>{t('Balance')}</TableHead>
                                    <TableHead>{t('Total cards issued')}</TableHead>
                                    <TableHead>{t('Updated')}</TableHead>
                                    {canManage && <TableHead>{t('Actions')}</TableHead>}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {providers.data.map((record) => (
                                    <TableRow key={record.id}>
                                        <TableCell className="max-w-64 whitespace-normal break-words">
                                            {record.name}
                                            {record.localMock && (
                                                <p className="text-xs text-muted-foreground">
                                                    {t('Mock (local only)')}
                                                </p>
                                            )}
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap">
                                            {record.referenceBalance === null ? (
                                                t('Temporarily unavailable')
                                            ) : (
                                                <MoneyDisplay
                                                    amount={record.referenceBalance}
                                                    asset={record.asset}
                                                />
                                            )}
                                            <p className="text-xs text-muted-foreground">
                                                {t(
                                                    record.apiConnected
                                                        ? record.sandbox
                                                            ? 'PhotonPay API · Sandbox'
                                                            : 'PhotonPay API · Production'
                                                        : 'Manual reference',
                                                )}
                                            </p>
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap">
                                            {record.issuedCardCount ?? t('Temporarily unavailable')}
                                            <p className="text-xs text-muted-foreground">
                                                {t(
                                                    record.apiConnected
                                                        ? 'Merchant card-list total, all statuses'
                                                        : 'Cards issued by this platform',
                                                )}
                                            </p>
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap">
                                            {record.updatedAt ? dateTime(record.updatedAt) : '—'}
                                        </TableCell>
                                        {canManage && (
                                            <TableCell>
                                                <Button
                                                    variant="secondary"
                                                    size="sm"
                                                    onClick={() => setEditing(record)}
                                                >
                                                    {t('Edit')}
                                                </Button>
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
                <div className="flex flex-wrap items-center justify-between gap-3 text-sm">
                    <span>
                        {t('Page {{page}} of {{pages}} · {{total}} records', {
                            page: providers.current_page,
                            pages: providers.last_page,
                            total: providers.total,
                        })}
                    </span>
                    <div className="flex gap-2">
                        {providers.prev_page_url && (
                            <Button variant="secondary" asChild>
                                <Link href={providers.prev_page_url}>{t('Previous')}</Link>
                            </Button>
                        )}
                        {providers.next_page_url && (
                            <Button variant="secondary" asChild>
                                <Link href={providers.next_page_url}>{t('Next')}</Link>
                            </Button>
                        )}
                    </div>
                </div>
            </div>
            <Dialog
                open={editing !== null}
                onOpenChange={(open) => {
                    if (!open) setEditing(null);
                }}
            >
                <DialogContent closeLabel={t('Close')} aria-describedby={undefined}>
                    <DialogHeader>
                        <DialogTitle>
                            {editing === 'new' ? t('Add card provider') : t('Edit card provider')}
                        </DialogTitle>
                    </DialogHeader>
                    {editing !== null && editing !== 'new' && editing.localMock ? (
                        <ReferenceForm record={editing} close={() => setEditing(null)} />
                    ) : (
                        editing !== null && (
                            <PhotonPayAccountForm
                                key={editing === 'new' ? 'new' : editing.id}
                                record={
                                    editing === 'new'
                                        ? null
                                        : (providers.data.find((item) => item.id === editing.id) ??
                                          editing)
                                }
                                close={() => setEditing(null)}
                            />
                        )
                    )}
                </DialogContent>
            </Dialog>
        </PlatformLayout>
    );
}
