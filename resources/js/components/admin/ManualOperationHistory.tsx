import { t, dateTime } from '@/i18n/admin';
export type ManualOperation = {
    id: string;
    action: string;
    operatorId: string | null;
    operatorName: string | null;
    operatedAt: string;
    companyName: string | null;
    orderId: string;
    orderType: string;
};
export function ManualOperationHistory({ operations }: { operations: ManualOperation[] }) {
    return (
        <section className="space-y-3 rounded-xl border bg-surface p-5">
            <h2 className="font-semibold">{t('Financial operation records')}</h2>
            {operations.length === 0 ? (
                <p className="text-sm text-muted-foreground">{t('No manual operation records')}</p>
            ) : (
                <ul className="divide-y">
                    {operations.map((operation) => (
                        <li key={operation.id} className="space-y-1 py-3 text-sm">
                            <p className="font-medium">{t(operation.action)}</p>
                            <p>
                                {t('Operator')}: {operation.operatorName ?? t('Unknown operator')}{' '}
                                <span className="break-all text-xs text-muted-foreground">
                                    {operation.operatorId}
                                </span>
                            </p>
                            <p>
                                {t('Operation time')}: {dateTime(operation.operatedAt)}
                            </p>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
