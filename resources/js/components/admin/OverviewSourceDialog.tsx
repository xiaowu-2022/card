import { useEffect, useState } from 'react';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from '@/components/ui/dialog';
import { AdminPagination } from './AdminPagination';
import { UserInfoCell, type UserInfo } from './UserInfoCell';
import { MoneyDisplay } from './MoneyDisplay';
import { Button } from '@/components/ui/button';
import { t, dateTime } from '@/i18n/admin';
import { readEditorResponse } from './editor-response';

export type OverviewSourceTarget = {
    metric: string;
    period?: string;
    asset?: string;
    label: string;
};
type Report = {
    amount: string | null;
    asset: string;
    updatedAt: string;
    timezone: string;
    rows: {
        page: number;
        lastPage: number;
        total: number;
        items: {
            id: string;
            companyName?: string;
            name?: string;
            userInfo?: UserInfo | null;
            amount: string | null;
            asset: string;
            usdt: string | null;
            source: string;
            time: string | null;
        }[];
    };
};
const sourceLabels: Record<string, string> = {
    deposits: 'Ordinary member activation deposits',
    annual: 'Annual fee income',
    depositRefunds: 'Ordinary member deposit refunds',
    activation: 'Activation commission',
    rebates: 'Annual fee returns',
    annualCommission: 'Annual commission',
    reimbursement: 'Reimbursements',
};
function SourceMoney({ amount, asset }: { amount: string | null; asset: string }) {
    return amount === null ? (
        <span>{t('Unavailable')}</span>
    ) : (
        <MoneyDisplay amount={amount} asset={asset} hideSymbol />
    );
}
export function OverviewSourceDialog({
    target,
    onClose,
}: {
    target: OverviewSourceTarget;
    onClose: () => void;
}) {
    const [page, setPage] = useState(1);
    const [report, setReport] = useState<Report | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [retry, setRetry] = useState(0);
    useEffect(() => {
        const controller = new AbortController();
        setLoading(true);
        setError('');
        const params = new URLSearchParams({
            metric: target.metric,
            period: target.period ?? 'total',
            page: String(page),
        });
        if (target.asset) params.set('asset', target.asset);
        void fetch(`/platform/overview/sources?${params}`, {
            credentials: 'same-origin',
            cache: 'no-store',
            signal: controller.signal,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then(async (response) => {
                const data = await readEditorResponse(response);
                if (!response.ok || !data.rows) throw new Error(t('Unable to load. Please retry.'));
                if (!controller.signal.aborted) setReport(data as Report);
            })
            .catch(() => {
                if (!controller.signal.aborted) setError(t('Unable to load. Please retry.'));
            })
            .finally(() => {
                if (!controller.signal.aborted) setLoading(false);
            });
        return () => controller.abort();
    }, [target, page, retry]);
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) onClose();
            }}
        >
            <DialogContent className="max-w-5xl" closeLabel={t('Close')}>
                <DialogHeader>
                    <DialogTitle>
                        {target.label} · {t('Data sources')}
                    </DialogTitle>
                    <DialogDescription>{t('Source records')}</DialogDescription>
                </DialogHeader>
                {loading && (
                    <p className="mt-4 text-sm text-muted-foreground" role="status">
                        {t('Loading...')}
                    </p>
                )}
                {error && (
                    <div className="mt-4 space-y-3" role="alert">
                        <p>{error}</p>
                        <Button variant="secondary" onClick={() => setRetry((x) => x + 1)}>
                            {t('Retry')}
                        </Button>
                    </div>
                )}
                {!error && !loading && report && (
                    <div className="mt-4 space-y-4">
                        <div className="flex flex-wrap justify-between gap-2 text-sm">
                            <p className="font-semibold">
                                {t('Total')}:{' '}
                                <SourceMoney amount={report.amount} asset={report.asset} />
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {t('Updated at')}: {dateTime(report.updatedAt, report.timezone)}
                            </p>
                        </div>
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full min-w-[720px] text-sm">
                                <thead>
                                    <tr className="border-b bg-muted/40 text-left text-muted-foreground">
                                        {[
                                            'Customer',
                                            'Source',
                                            'Time',
                                            'Currency',
                                            'Amount',
                                            'USDT estimate',
                                            'Reference',
                                        ].map((label) => (
                                            <th key={label} className="px-3 py-2.5 font-medium">
                                                {t(label)}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {report.rows.items.length === 0 ? (
                                        <tr>
                                            <td
                                                colSpan={7}
                                                className="p-6 text-center text-muted-foreground"
                                            >
                                                {t('No source records.')}
                                            </td>
                                        </tr>
                                    ) : (
                                        report.rows.items.map((row) => (
                                            <tr
                                                key={`${row.source}:${row.id}`}
                                                className="border-b last:border-0"
                                            >
                                                <td className="px-3 py-2.5">
                                                    {row.userInfo ? (
                                                        <UserInfoCell
                                                            user={row.userInfo}
                                                            interactive={false}
                                                        />
                                                    ) : (
                                                        row.name || row.companyName || '—'
                                                    )}
                                                </td>
                                                <td className="px-3 py-2.5">
                                                    {t(sourceLabels[row.source] ?? row.source)}
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-2.5">
                                                    {row.time
                                                        ? dateTime(row.time, report.timezone)
                                                        : '—'}
                                                </td>
                                                <td className="px-3 py-2.5">{row.asset}</td>
                                                <td className="whitespace-nowrap px-3 py-2.5 text-right">
                                                    <SourceMoney
                                                        amount={row.amount}
                                                        asset={row.asset}
                                                    />
                                                </td>
                                                <td className="whitespace-nowrap px-3 py-2.5 text-right">
                                                    <SourceMoney amount={row.usdt} asset="USDT" />
                                                </td>
                                                <td className="max-w-48 break-all px-3 py-2.5 text-xs text-muted-foreground">
                                                    {row.id}
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                        <AdminPagination
                            page={report.rows.page}
                            pages={report.rows.lastPage}
                            total={report.rows.total}
                            disabled={loading}
                            onPage={setPage}
                        />
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
