import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Link, router } from '@inertiajs/react';
import { t, dateTime } from '@/i18n/admin';
import { displayMoney } from '@/lib/exact-amount';
import type { AccountPage } from '@/components/shared/PlatformAccountTable';
export type CardLoadOrder = {
    id: string;
    tenantId: string;
    cardId: string;
    canVoid: boolean;
    companyName: string;
    userEmail: string;
    maskedPan: string;
    amount: string;
    requestedAmount: string | null;
    overflowAmount: string | null;
    manualFundingAmount: string;
    arrivalAmount: string | null;
    debitAmount: string | null;
    feeAmount: string | null;
    status: string;
    createdAt: string;
};
export function CardLoadOrders({
    page,
    canManage = false,
}: {
    page: AccountPage<CardLoadOrder>;
    canManage?: boolean;
}) {
    const [pending, setPending] = useState(false);
    const [error, setError] = useState('');
    const money = (v: string | null) => (v === null ? '—' : displayMoney(v));
    return (
        <div className="rounded-xl border bg-background overflow-x-auto">
            {error && (
                <p role="alert" className="p-3 text-destructive">
                    {t(error)}
                </p>
            )}
            <table className="w-full text-sm">
                <thead>
                    <tr className="border-b text-left">
                        {[
                            'Tenant',
                            'User',
                            'Card',
                            'Requested load',
                            'Actual arrival',
                            'Actual debit',
                            'Fee',
                            'Overflow amount',
                            'External channel funding',
                            'Status',
                            'Reload requested at',
                            ...(canManage ? ['Actions'] : []),
                        ].map((s) => (
                            <th className="p-3 whitespace-nowrap" key={s}>
                                {t(s)}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {page.data.map((o) => (
                        <tr className="border-b" key={o.id}>
                            <td className="p-3">{o.companyName}</td>
                            <td className="p-3">{o.userEmail}</td>
                            <td className="p-3 whitespace-nowrap">{o.maskedPan}</td>
                            <td className="p-3">{money(o.requestedAmount ?? o.amount)}</td>
                            <td className="p-3">
                                {money(o.status === 'SUCCEEDED' ? o.arrivalAmount : null)}
                            </td>
                            <td className="p-3">
                                {money(o.status === 'SUCCEEDED' ? o.debitAmount : null)}
                            </td>
                            <td className="p-3">{money(o.feeAmount)}</td>
                            <td className="p-3">{money(o.overflowAmount ?? '0')}</td>
                            <td className="p-3">
                                {money(o.status === 'SUCCEEDED' ? o.manualFundingAmount : null)}
                            </td>
                            <td className="p-3">
                                {t(
                                    (
                                        {
                                            QUOTING: 'Requesting quote',
                                            QUOTED: 'Awaiting confirmation',
                                            EXPIRED: 'Voided or expired',
                                        } as Record<string, string>
                                    )[o.status] ?? o.status,
                                )}
                            </td>
                            <td className="p-3 whitespace-nowrap">{dateTime(o.createdAt)}</td>
                            {canManage && (
                                <td className="p-3 whitespace-nowrap">
                                    {o.canVoid && (
                                        <Button
                                            disabled={pending}
                                            size="sm"
                                            variant="secondary"
                                            onClick={() =>
                                                router.post(
                                                    `/platform/tenants/${o.tenantId}/cards/${o.cardId}/loads/${o.id}/void`,
                                                    {},
                                                    {
                                                        preserveScroll: true,
                                                        onStart: () => {
                                                            setPending(true);
                                                            setError('');
                                                        },
                                                        onFinish: () => setPending(false),
                                                        onError: (errors) =>
                                                            setError(
                                                                errors.card_load ??
                                                                    Object.values(errors)[0] ??
                                                                    '',
                                                            ),
                                                    },
                                                )
                                            }
                                        >
                                            {t('Void reload')}
                                        </Button>
                                    )}
                                </td>
                            )}
                        </tr>
                    ))}
                </tbody>
            </table>
            {!page.data.length && (
                <p className="p-4 text-muted-foreground">{t('No records yet.')}</p>
            )}
            <div className="flex gap-3 p-3">
                {page.prev_page_url && (
                    <Link href={page.prev_page_url + '&tab=loads'} preserveState preserveScroll>
                        {t('Previous')}
                    </Link>
                )}
                <span>
                    {page.current_page} / {page.last_page}
                </span>
                {page.next_page_url && (
                    <Link href={page.next_page_url + '&tab=loads'} preserveState preserveScroll>
                        {t('Next')}
                    </Link>
                )}
            </div>
        </div>
    );
}
