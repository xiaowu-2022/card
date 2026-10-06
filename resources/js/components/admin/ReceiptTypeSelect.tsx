import { t } from '@/i18n/admin';

export function ReceiptTypeSelect({
    value,
    canAdvance,
    disabled,
    onChange,
}: {
    value: 'ACTUAL' | 'ADVANCE';
    canAdvance: boolean;
    disabled: boolean;
    onChange: (value: 'ACTUAL' | 'ADVANCE') => void;
}) {
    return (
        <div className="space-y-2">
            <label className="flex items-center gap-3 text-sm">
                {t('Receipt type')}
                <select
                    className="h-9 rounded-md border bg-background px-3"
                    value={value}
                    disabled={disabled}
                    onChange={(event) => onChange(event.target.value as 'ACTUAL' | 'ADVANCE')}
                >
                    <option value="ACTUAL">{t('Actual receipt')}</option>
                    {canAdvance && <option value="ADVANCE">{t('Advance amount')}</option>}
                </select>
            </label>
            {value === 'ADVANCE' && (
                <p className="text-sm text-muted-foreground">
                    {t('Credit the wallet and record the same amount as a partner advance.')}
                </p>
            )}
        </div>
    );
}
