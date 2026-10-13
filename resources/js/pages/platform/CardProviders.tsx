import { AdminPagination } from '@/components/admin/AdminPagination';
import { adminPageHref } from '@/lib/admin-pagination';
import { OperationFeedback } from '@/components/admin/OperationFeedback';
import { displayMoney } from '@/lib/admin-amount';
import { Head, useForm, usePage } from '@inertiajs/react';
import {
    PhotonPayAccountForm,
    type PhotonPayConfiguration,
} from '@/components/shared/PhotonPayAccountForm';
import { useState } from 'react';
import { useAdminTranslation, t, dateTime, errorMessage } from '@/i18n/admin';
import { EmptyState } from '@/components/shared/EmptyState';
import { MoneyDisplay } from '@/components/admin/MoneyDisplay';
import type { AccountPage } from '@/components/shared/PlatformAccountTable';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Table,
    TableHeader,
    TableBody,
    TableRow,
    TableHead,
    TableCell,
} from '@/components/ui/table';
import type { SharedProps } from '@/types/global';
import { U1AccountForm, type U1Configuration } from '@/components/shared/U1AccountForm';

type Reference = {
    u1: U1Configuration | null;
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
    canDelete: boolean;
};

function ProviderAccountForm({ record, close }: { record: Reference | null; close: () => void }) {
    const [type, setType] = useState(record?.u1 ? 'U1' : 'PhotonPay');
    return (
        <div className="space-y-4">
            <label className="block text-sm">
                {t('Card provider type')}
                <select
                    className="w-full rounded border p-2"
                    value={type}
                    disabled={!!record?.u1 || !!record?.photonpay}
                    onChange={(event) => setType(event.target.value)}
                >
                    <option value="PhotonPay">PhotonPay</option>
                    <option value="U1">U1</option>
                </select>
            </label>
            {type === 'U1' ? (
                <U1AccountForm record={record} close={close} />
            ) : (
                <PhotonPayAccountForm record={record} close={close} />
            )}
        </div>
    );
}

function ReferenceForm({ record, close }: { record: Reference | null; close: () => void }) {
    const form = useForm({
        name: record?.name ?? '',
        reference_balance: displayMoney(record?.referenceBalance ?? '0'),
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
                <OperationFeedback key={key} role="alert" className="text-sm text-destructive">
                    {errorMessage(message)}
                </OperationFeedback>
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
    const [deleting, setDeleting] = useState<Reference | null>(null);
    const deletion = useForm({ version: 0, confirmed: true, request_id: '' });
    return (
        <PlatformLayout
            title={t('Card providers')}
            actions={
                canManage ? (
                    <Button onClick={() => setEditing('new')}>{t('Add card provider')}</Button>
                ) : undefined
            }
        >
            <Head title={t('Card providers')} />
            <div className="space-y-4">
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
                                            {record.u1 && (
                                                <p className="text-xs text-muted-foreground">U1</p>
                                            )}
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
                                                    record.u1
                                                        ? 'U1 API'
                                                        : record.apiConnected
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
                                                <div className="flex gap-2">
                                                    <Button
                                                        variant="secondary"
                                                        size="sm"
                                                        onClick={() => setEditing(record)}
                                                    >
                                                        {t('Edit')}
                                                    </Button>
                                                    <span
                                                        title={
                                                            record.canDelete === false
                                                                ? t(
                                                                      'This card provider is in use and cannot be deleted.',
                                                                  )
                                                                : undefined
                                                        }
                                                    >
                                                        <Button
                                                            variant="destructive"
                                                            size="sm"
                                                            disabled={record.canDelete === false}
                                                            onClick={() => {
                                                                deletion.clearErrors();
                                                                deletion.setData({
                                                                    version: record.version,
                                                                    confirmed: true,
                                                                    request_id: crypto.randomUUID(),
                                                                });
                                                                setDeleting(record);
                                                            }}
                                                        >
                                                            {t('Delete')}
                                                        </Button>
                                                    </span>
                                                </div>
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
                <AdminPagination
                    page={providers.current_page}
                    pages={providers.last_page}
                    total={providers.total}
                    href={(page) => adminPageHref(providers, page)}
                />
            </div>
            <Dialog
                open={editing !== null}
                onOpenChange={(open) => {
                    if (!open) setEditing(null);
                }}
            >
                <DialogContent
                    presentation="drawer"
                    closeLabel={t('Close')}
                    aria-describedby={undefined}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {editing === 'new' ? t('Add card provider') : t('Edit card provider')}
                        </DialogTitle>
                    </DialogHeader>
                    {editing !== null && editing !== 'new' && editing.localMock ? (
                        <ReferenceForm record={editing} close={() => setEditing(null)} />
                    ) : (
                        editing !== null && (
                            <ProviderAccountForm
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
            <Dialog
                open={deleting !== null}
                onOpenChange={(open) => {
                    if (!open && !deletion.processing) setDeleting(null);
                }}
            >
                <DialogContent closeLabel={t('Close')}>
                    <DialogHeader>
                        <DialogTitle>{t('Delete card provider')}</DialogTitle>
                        <DialogDescription>
                            {t('Delete {{name}}? This cannot be undone.', {
                                name: deleting?.name ?? '',
                            })}
                        </DialogDescription>
                    </DialogHeader>
                    <form
                        className="space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            if (!deleting || deletion.processing) return;
                            deletion.delete(`/platform/card-providers/${deleting.id}`, {
                                preserveScroll: true,
                                onSuccess: () => setDeleting(null),
                            });
                        }}
                    >
                        {Object.entries(deletion.errors).map(([key, message]) => (
                            <OperationFeedback
                                key={key}
                                role="alert"
                                className="text-sm text-destructive"
                            >
                                {errorMessage(message)}
                            </OperationFeedback>
                        ))}
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="secondary"
                                disabled={deletion.processing}
                                onClick={() => setDeleting(null)}
                            >
                                {t('Cancel')}
                            </Button>
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={deletion.processing}
                            >
                                {t('Confirm deletion')}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </PlatformLayout>
    );
}
