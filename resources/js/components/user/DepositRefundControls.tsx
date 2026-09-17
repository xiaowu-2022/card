import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { FinancialConfirmation } from '@/components/user/FinancialConfirmation';
import { t, dateTime } from '@/i18n';

export type DepositRefund = {
    pendingId: string | null;
    canRequest: boolean;
    waitDays: number | null;
    eligibleAt: string | null;
    serverNow: string;
    progress: string | null;
    cancelling: boolean;
};
export function DepositRefundControls({ refund }: { refund: DepositRefund }) {
    const [requestId, setRequestId] = useState(() => crypto.randomUUID());
    const [seconds, setSeconds] = useState(0);
    useEffect(() => {
        const started = performance.now();
        const remaining = refund.eligibleAt
            ? Math.max(0, Date.parse(refund.eligibleAt) - Date.parse(refund.serverNow))
            : 0;
        const tick = () =>
            setSeconds(Math.max(0, Math.ceil((remaining - (performance.now() - started)) / 1000)));
        tick();
        const timer = window.setInterval(tick, 1000);
        return () => window.clearInterval(timer);
    }, [refund.eligibleAt, refund.serverNow]);
    useEffect(() => {
        if (!refund.pendingId) return;
        const timer = window.setInterval(() => {
            if (document.visibilityState === 'visible') router.reload({ only: ['preview'] });
        }, 15000);
        return () => window.clearInterval(timer);
    }, [refund.pendingId]);
    const warning = [
        t('Applying for a deposit refund freezes your cards and locks card actions.'),
        t(
            'Cards cannot be used during the deposit refund period. Only transaction history is available.',
        ),
        t(
            'After the waiting period and confirmed card freezing, your deposit returns automatically to your wallet.',
        ),
    ];
    return (
        <section className="space-y-4 border-t pt-6">
            <h2 className="font-semibold">{t('Refund security deposit')}</h2>
            <ul className="list-disc space-y-2 pl-5 text-sm leading-6 text-muted-foreground">
                {warning.map((message) => (
                    <li key={message}>{message}</li>
                ))}
            </ul>
            {refund.waitDays !== null && !refund.pendingId && (
                <p className="text-sm">
                    {t('Deposit refund waiting period: {{days}} days', { days: refund.waitDays })}
                </p>
            )}
            {refund.waitDays === null && !refund.pendingId && (
                <p className="text-sm text-muted-foreground">
                    {t(
                        'The company has not configured the deposit refund waiting period. Please contact support.',
                    )}
                </p>
            )}
            {refund.pendingId && (
                <div className="space-y-2 rounded-xl bg-muted p-4 text-sm" role="status">
                    <p>
                        {t(
                            refund.cancelling
                                ? 'Restoring cards before cancelling the refund request.'
                                : refund.progress === 'legacy'
                                  ? 'This older request has no countdown. Cancel it and submit a new request to use the company waiting period.'
                                  : refund.progress === 'waiting'
                                    ? 'Cards are frozen. Waiting for the refund period to end.'
                                    : refund.progress === 'blocked'
                                      ? 'Card freezing or an earlier operation is still awaiting confirmation. No refund will be made until it is resolved.'
                                      : 'Freezing cards. Card actions are locked.',
                        )}
                    </p>
                    {refund.eligibleAt && !refund.cancelling && (
                        <>
                            <p>
                                {t(
                                    'Refund countdown: {{days}}d {{hours}}h {{minutes}}m {{seconds}}s',
                                    {
                                        days: Math.floor(seconds / 86400),
                                        hours: Math.floor((seconds % 86400) / 3600),
                                        minutes: Math.floor((seconds % 3600) / 60),
                                        seconds: seconds % 60,
                                    },
                                )}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {t('Scheduled refund time: {{time}}', {
                                    time: dateTime(refund.eligibleAt),
                                })}
                            </p>
                            {seconds === 0 && (
                                <p>
                                    {t(
                                        'The waiting period has ended. The system will complete the refund after card freezing is confirmed.',
                                    )}
                                </p>
                            )}
                        </>
                    )}
                </div>
            )}
            {refund.canRequest && (
                <div className="flex justify-center">
                    <FinancialConfirmation
                        title={t('Request deposit refund')}
                        warning={warning}
                        url="/security-deposit/refund"
                        payload={{ action: 'request', request_id: requestId }}
                        onCompleted={() => setRequestId(crypto.randomUUID())}
                    />
                </div>
            )}
            {refund.pendingId && !refund.cancelling && (
                <div className="flex flex-wrap gap-3">
                    <FinancialConfirmation
                        title={t('Cancel deposit refund request')}
                        warning={t(
                            'Cancelling keeps your deposit and restores only cards frozen by this request. Please wait for restoration; no new commission is earned.',
                        )}
                        url="/security-deposit/refund"
                        payload={{ action: 'cancel', refund_id: refund.pendingId }}
                    />
                </div>
            )}
        </section>
    );
}
