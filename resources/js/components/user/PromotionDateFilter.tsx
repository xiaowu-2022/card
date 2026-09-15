import { t, useClientTranslation } from '@/i18n';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

export function PromotionDateFilter({
    id,
    date,
    allowAll = false,
    onChange,
}: {
    id: string;
    date: string | null;
    allowAll?: boolean;
    onChange: (date: string | null) => void;
}) {
    useClientTranslation();
    return (
        <div className="promotion-date-row">
            <label htmlFor={id}>
                {t('Date')}
                {allowAll && !date && <span>{t('All dates')}</span>}
            </label>
            <Input
                id={id}
                type="date"
                value={date ?? ''}
                onChange={(event) => {
                    if (event.target.value || allowAll) onChange(event.target.value || null);
                }}
            />
            {allowAll && date && (
                <Button type="button" variant="ghost" onClick={() => onChange(null)}>
                    {t('All dates')}
                </Button>
            )}
        </div>
    );
}
