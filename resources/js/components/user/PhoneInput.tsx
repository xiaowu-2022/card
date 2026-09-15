import { dialCountries } from '@/lib/phone-input';
import { SearchSelect } from '@/components/ui/search-select';
import { Input } from '@/components/ui/input';
import { countryOptions } from '@/hooks/useCardGeography';
import { t, useClientTranslation } from '@/i18n';
export function PhoneInput({
    id,
    value,
    region,
    disabled,
    onChange,
    onRegionChange,
}: {
    id: string;
    value: string;
    region: string;
    disabled?: boolean;
    onChange: (value: string) => void;
    onRegionChange: (value: string) => void;
}) {
    const { i18n } = useClientTranslation();
    return (
        <div className="flex min-w-0 gap-2">
            <div className="w-24 shrink-0">
                <SearchSelect
                    id={`${id}-region`}
                    label={t('Country code')}
                    options={countryOptions(dialCountries, i18n.language, true)}
                    value={region}
                    onValueChange={onRegionChange}
                    compact
                    placeholder={t('Country code')}
                    searchLabel={t('Search options')}
                    emptyLabel={t('No matching options')}
                    disabled={disabled}
                />
            </div>
            <Input
                id={id}
                className="min-w-0 flex-1"
                type="tel"
                inputMode="tel"
                autoComplete="tel-national"
                placeholder={t('Phone number')}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                disabled={disabled}
                maxLength={30}
                required
            />
        </div>
    );
}
