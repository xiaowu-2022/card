import { MapPin, UserRound } from 'lucide-react';
import type { useCardholderMaterialsForm } from '@/hooks/useCardholderMaterialsForm';
import {
    countryOptions,
    placeOptions,
    useCardGeography,
    type Country,
    type Region,
} from '@/hooks/useCardGeography';
import { Button } from '@/components/ui/button';
import { localityMode } from '@/lib/card-locality';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { SearchSelect, type SearchOption } from '@/components/ui/search-select';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { clientI18n, errorMessage, t, useClientTranslation } from '@/i18n';
import {
    cardholderErrors,
    cardholderFieldError,
    type CardholderField,
    type CardholderValidationData,
} from '@/lib/cardholder-validation';

export function CardholderMaterialsForm({
    form,
    updateRequestId,
    onAdded,
}: {
    form: ReturnType<typeof useCardholderMaterialsForm>;
    updateRequestId?: string;
    onAdded: () => void;
}) {
    useClientTranslation();
    const countries = useCardGeography<Country[]>('countries');
    const regions = useCardGeography<Region[]>(form.data.residential_country_code);
    const locale = clientI18n.language;
    const countryItems = countryOptions(countries.data ?? [], locale);
    const stateItems = placeOptions(regions.data ?? [], locale);
    const cityItems = placeOptions(
        regions.data?.find((region) => region.value === form.data.residential_state)?.cities ?? [],
        locale,
    );
    const errors = form.errors as Record<string, string>;
    const validationContext = { countries: countries.data ?? [], regions: regions.data ?? [] };
    function validate(field: CardholderField, data: CardholderValidationData = form.data) {
        const error = cardholderFieldError(field, data, validationContext);
        if (error) form.setError(field, error);
        else form.clearErrors(field);
    }
    type TextField =
        | 'legal_first_name'
        | 'legal_last_name'
        | 'date_of_birth'
        | 'email'
        | 'residential_address'
        | 'residential_postal_code';
    type ChoiceField =
        | 'nationality_country_code'
        | 'residential_country_code'
        | 'residential_state'
        | 'residential_city';
    function input(name: TextField, label: string, type = 'text') {
        return (
            <FormField id={'holder-' + name} label={t(label)} error={errorMessage(errors[name])}>
                <Input
                    id={'holder-' + name}
                    type={type}
                    lang={locale}
                    required
                    disabled={form.processing}
                    value={form.data[name]}
                    autoComplete="off"
                    aria-invalid={!!errors[name] || undefined}
                    aria-describedby={errors[name] ? `holder-${name}-error` : undefined}
                    className={errors[name] ? 'border-danger' : undefined}
                    onBlur={() => validate(name)}
                    onChange={(event) => {
                        form.setData(name, event.target.value);
                        if (errors[name])
                            validate(name, { ...form.data, [name]: event.target.value });
                    }}
                />
            </FormField>
        );
    }
    function choice(
        name: ChoiceField,
        label: string,
        options: SearchOption[],
        onChange?: (value: string) => void,
    ) {
        const manual =
            (name === 'residential_state' || name === 'residential_city') &&
            localityMode(regions.data, form.data.residential_state, name) === 'manual';
        if (manual)
            return (
                <FormField
                    id={'holder-' + name}
                    label={t(label)}
                    error={errorMessage(errors[name])}
                >
                    <Input
                        id={'holder-' + name}
                        value={form.data[name]}
                        maxLength={50}
                        required
                        disabled={form.processing}
                        autoComplete="off"
                        aria-invalid={!!errors[name] || undefined}
                        placeholder={t('Enter the actual location name')}
                        onBlur={() => validate(name)}
                        onChange={(event) => {
                            if (onChange) onChange(event.target.value);
                            else form.setData(name, event.target.value);
                            form.clearErrors(name);
                            if (name === 'residential_state') form.clearErrors('residential_city');
                        }}
                    />
                </FormField>
            );
        return (
            <FormField id={'holder-' + name} label={t(label)} error={errorMessage(errors[name])}>
                <SearchSelect
                    id={'holder-' + name}
                    label={t(label)}
                    value={form.data[name]}
                    options={options}
                    placeholder={t('Please select')}
                    searchLabel={t('Search options')}
                    emptyLabel={t('No matching options')}
                    disabled={form.processing || !options.length}
                    invalid={!!errors[name]}
                    onValueChange={(value) => {
                        if (onChange) onChange(value);
                        else form.setData(name, value);
                        form.clearErrors(name);
                        if (name === 'residential_country_code')
                            form.clearErrors('residential_state', 'residential_city');
                        if (name === 'residential_state') form.clearErrors('residential_city');
                    }}
                />
            </FormField>
        );
    }
    return (
        <form
            className="cardholder-materials-form grid gap-7"
            noValidate
            onSubmit={(event) => {
                event.preventDefault();
                const invalid = cardholderErrors(form.data, validationContext);
                form.clearErrors();
                if (Object.keys(invalid).length) {
                    form.setError(invalid);
                    const first = Object.keys(invalid)[0];
                    const id =
                        first === 'document_type' ? 'holder-document-type' : `holder-${first}`;
                    event.currentTarget.querySelector<HTMLElement>(`[id="${id}"]`)?.focus();
                    return;
                }
                form.transform((data) => ({
                    ...data,
                    request_id: updateRequestId ?? data.request_id,
                }));
                form.post('/cards/cardholder', {
                    forceFormData: true,
                    preserveScroll: true,
                    preserveState: true,
                    onSuccess: () => {
                        form.reset();
                        form.setData('request_id', crypto.randomUUID());
                        onAdded();
                    },
                    onError: (errors) => {
                        // Only a definitive failed addition permits a corrected new attempt. UNKNOWN keeps its UUID.
                        if (
                            errors.form ===
                            'The cardholder could not be added. Check the details and try again.'
                        ) {
                            form.setData('request_id', crypto.randomUUID());
                        }
                    },
                });
            }}
        >
            <fieldset className="min-w-0" disabled={form.processing}>
                <legend className="mb-5 flex items-center gap-2 text-base font-semibold">
                    <UserRound className="size-5" />
                    {t('Card user')}
                </legend>
                <div className="grid gap-5 sm:grid-cols-2">
                    {input('legal_last_name', 'Last name')}
                    {input('legal_first_name', 'First name')}
                    {input('email', 'Email', 'email')}
                    <FormField
                        id="holder-mobile"
                        label={t('Mobile number')}
                        error={errorMessage(errors.mobile || errors.mobile_country_code)}
                    >
                        <div
                            data-invalid={!!(errors.mobile || errors.mobile_country_code)}
                            className={`flex min-w-0 items-stretch rounded-lg border bg-white ${errors.mobile || errors.mobile_country_code ? 'border-danger' : ''}`}
                        >
                            <div className="w-24 shrink-0">
                                <SearchSelect
                                    id="holder-mobile-country"
                                    label={t('Calling code')}
                                    compact
                                    value={form.data.mobile_country_code}
                                    options={countryOptions(countries.data ?? [], locale, true)}
                                    placeholder={t('Code')}
                                    searchLabel={t('Search options')}
                                    emptyLabel={t('No matching options')}
                                    disabled={form.processing || !countries.data}
                                    onValueChange={(value) => {
                                        form.setData('mobile_country_code', value);
                                        form.clearErrors('mobile_country_code');
                                        if (form.data.mobile.trim())
                                            validate('mobile', {
                                                ...form.data,
                                                mobile_country_code: value,
                                            });
                                    }}
                                />
                            </div>
                            <Input
                                id="holder-mobile"
                                type="tel"
                                inputMode="tel"
                                className="min-w-0 border-0"
                                autoComplete="off"
                                value={form.data.mobile}
                                aria-invalid={
                                    !!(errors.mobile || errors.mobile_country_code) || undefined
                                }
                                aria-describedby={
                                    errors.mobile || errors.mobile_country_code
                                        ? 'holder-mobile-error'
                                        : undefined
                                }
                                onBlur={() => validate('mobile')}
                                onChange={(event) => {
                                    form.setData('mobile', event.target.value);
                                    if (errors.mobile)
                                        validate('mobile', {
                                            ...form.data,
                                            mobile: event.target.value,
                                        });
                                }}
                            />
                        </div>
                    </FormField>
                    {choice('nationality_country_code', 'Nationality', countryItems)}
                    {input('date_of_birth', 'Date of birth', 'date')}
                    <div className="sm:col-span-2">
                        <FormField
                            id="holder-document-type"
                            label={t('Document type')}
                            error={errorMessage(errors.document_type)}
                        >
                            <Select
                                value={form.data.document_type}
                                disabled={form.processing}
                                onValueChange={(value) => {
                                    form.setData('document_type', value);
                                    form.clearErrors('document_type');
                                    if (errors.back)
                                        validate('back', { ...form.data, document_type: value });
                                }}
                            >
                                <SelectTrigger
                                    id="holder-document-type"
                                    aria-invalid={!!errors.document_type || undefined}
                                    aria-describedby={
                                        errors.document_type
                                            ? 'holder-document-type-error'
                                            : undefined
                                    }
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="id_card">
                                        {t('National identity card')}
                                    </SelectItem>
                                    <SelectItem value="passport">{t('Passport')}</SelectItem>
                                    <SelectItem value="resident_permit">
                                        {t('Residence permit')}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </FormField>
                    </div>
                    {(['front', 'back'] as const).map((side) => (
                        <FormField
                            key={side}
                            id={'holder-' + side}
                            label={
                                side === 'front'
                                    ? t('Document front / passport photo page')
                                    : t('Document back (optional for passports)')
                            }
                            error={errorMessage(errors[side])}
                        >
                            <Input
                                id={'holder-' + side}
                                type="file"
                                className="peer sr-only"
                                accept="image/png,image/jpeg"
                                aria-invalid={!!errors[side] || undefined}
                                aria-describedby={errors[side] ? `holder-${side}-error` : undefined}
                                disabled={form.processing}
                                required={
                                    !form.data[side] &&
                                    (side === 'front' || form.data.document_type !== 'passport')
                                }
                                onChange={(event) => {
                                    const file = event.target.files?.[0] ?? null;
                                    form.setData(side, file);
                                    validate(side, { ...form.data, [side]: file });
                                }}
                            />
                            <label
                                htmlFor={'holder-' + side}
                                data-invalid={!!errors[side]}
                                className={`flex min-h-11 cursor-pointer items-center rounded-lg border bg-white px-3 py-2 text-sm peer-focus-visible:ring-2 peer-focus-visible:ring-ring peer-disabled:opacity-50 ${errors[side] ? 'border-danger' : ''}`}
                            >
                                {form.data[side] ? t('Document selected') : t('Choose document')}
                            </label>
                        </FormField>
                    ))}
                    <p className="text-xs leading-5 text-muted-foreground sm:col-span-2">
                        {t(
                            'PNG or JPEG, up to 6 MB per document. Only submit documents you are authorized to use.',
                        )}
                    </p>
                </div>
            </fieldset>
            <div className="border-t pt-6">
                <fieldset className="min-w-0" disabled={form.processing}>
                    <legend className="mb-5 flex items-center gap-2 text-base font-semibold">
                        <MapPin className="size-5" />
                        {t('Billing address')}
                    </legend>
                    <div className="grid gap-5">
                        {choice(
                            'residential_country_code',
                            'Country / region',
                            countryItems,
                            (value) =>
                                form.setData((data) => ({
                                    ...data,
                                    residential_country_code: value,
                                    residential_state: '',
                                    residential_city: '',
                                })),
                        )}
                        <div className="grid gap-5 sm:grid-cols-2">
                            {choice('residential_state', 'State / province', stateItems, (value) =>
                                form.setData((data) => ({
                                    ...data,
                                    residential_state: value,
                                    residential_city: '',
                                })),
                            )}
                            {choice('residential_city', 'City', cityItems)}
                        </div>
                        {form.data.residential_country_code && !regions.data && !regions.failed && (
                            <p role="status" className="text-sm text-muted-foreground">
                                {t('Loading regions…')}
                            </p>
                        )}
                        {regions.data &&
                            (!stateItems.length ||
                                (form.data.residential_state && !cityItems.length)) && (
                                <p role="status" className="text-sm text-muted-foreground">
                                    {t(
                                        'Location data is incomplete here. Enter the actual state or city name.',
                                    )}
                                </p>
                            )}
                        {input('residential_address', 'Detailed address')}
                        {input('residential_postal_code', 'Postal code')}
                    </div>
                </fieldset>
            </div>
            {(countries.failed || regions.failed) && (
                <div role="alert" className="text-sm text-destructive">
                    {t('Location options could not be loaded.')}{' '}
                    <button
                        type="button"
                        className="underline"
                        onClick={() => {
                            countries.retry();
                            regions.retry();
                        }}
                    >
                        {t('Try again')}
                    </button>
                </div>
            )}
            {errors.form && (
                <p role="alert" className="text-sm text-destructive">
                    {errorMessage(errors.form)}
                </p>
            )}
            {import.meta.env.DEV && (
                <div className="flex flex-wrap gap-3">
                    <Button
                        type="button"
                        variant="secondary"
                        disabled={form.processing}
                        onClick={() => {
                            form.transform((data) => ({
                                ...data,
                                request_id: updateRequestId ?? data.request_id,
                            }));
                            form.post('/cards/cardholder/test-materials/save', {
                                forceFormData: true,
                                preserveState: true,
                                preserveScroll: true,
                            });
                        }}
                    >
                        {t('Save test materials')}
                    </Button>
                    <Button
                        type="button"
                        variant="secondary"
                        disabled={form.processing}
                        onClick={() => {
                            void (async () => {
                                try {
                                    const token = document.cookie
                                        .split('; ')
                                        .find((value) => value.startsWith('XSRF-TOKEN='))
                                        ?.slice(11);
                                    const response = await fetch(
                                        `/cards/cardholder/test-materials/${form.data.card_product_id}`,
                                        {
                                            method: 'POST',
                                            credentials: 'same-origin',
                                            cache: 'no-store',
                                            headers: {
                                                Accept: 'application/json',
                                                'X-XSRF-TOKEN': decodeURIComponent(token ?? ''),
                                            },
                                        },
                                    );
                                    if (!response.ok)
                                        throw new Error(
                                            'Saved test materials could not be loaded.',
                                        );
                                    const saved = (await response.json()) as {
                                        fields: Omit<typeof form.data, 'front' | 'back'>;
                                        documents: Partial<
                                            Record<
                                                'front' | 'back',
                                                { content: string; mime: string }
                                            >
                                        >;
                                    };
                                    const files: { front: File | null; back: File | null } = {
                                        front: null,
                                        back: null,
                                    };
                                    for (const side of ['front', 'back'] as const) {
                                        const document = saved.documents?.[side];
                                        if (document) {
                                            const bytes = Uint8Array.from(
                                                atob(document.content),
                                                (character) => character.charCodeAt(0),
                                            );
                                            files[side] = new File(
                                                [bytes],
                                                `identity-${side}.${document.mime === 'image/png' ? 'png' : 'jpg'}`,
                                                { type: document.mime },
                                            );
                                        }
                                    }
                                    form.setData({ ...form.data, ...saved.fields, ...files });
                                    form.clearErrors();
                                } catch {
                                    form.setError(
                                        'form' as keyof typeof form.data,
                                        'Saved test materials could not be loaded.',
                                    );
                                }
                            })();
                        }}
                    >
                        {t('Load saved test materials')}
                    </Button>
                </div>
            )}
            <Button
                className="w-full"
                disabled={form.processing || !countries.data || !regions.data || !!regions.failed}
                type="submit"
            >
                {form.processing
                    ? t('Submitting…')
                    : updateRequestId
                      ? t('Resubmit details')
                      : t('Submit cardholder materials')}
            </Button>
        </form>
    );
}
