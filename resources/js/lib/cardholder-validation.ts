import { isValidPhoneNumber } from 'libphonenumber-js/max';
import type { Country, Region } from '@/hooks/useCardGeography';
import { localityMode, validManualLocality } from './card-locality';

export const cardholderFields = [
    'legal_last_name',
    'legal_first_name',
    'email',
    'mobile',
    'nationality_country_code',
    'date_of_birth',
    'document_type',
    'front',
    'back',
    'residential_country_code',
    'residential_state',
    'residential_city',
    'residential_address',
    'residential_postal_code',
] as const;
export type CardholderField = (typeof cardholderFields)[number];
export type CardholderValidationData = Record<
    Exclude<CardholderField, 'front' | 'back'> | 'mobile_country_code',
    string
> & {
    front: Pick<File, 'size' | 'type'> | null;
    back: Pick<File, 'size' | 'type'> | null;
};
type Context = { countries: Country[]; regions: Region[]; today?: string };

function localToday() {
    const now = new Date();
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
}

// Basic browser feedback only. The server remains authoritative; never send PII to a validation API.
// Return catalog keys, not translated strings, so changing language also updates existing errors.
export function cardholderFieldError(
    field: CardholderField,
    data: CardholderValidationData,
    context: Context,
): string | undefined {
    const required = 'This field is required.';
    if (field === 'front' || field === 'back') {
        const file = data[field];
        if (!file)
            return field === 'front' || data.document_type !== 'passport' ? required : undefined;
        if (
            !['image/jpeg', 'image/png'].includes(file.type) ||
            file.size <= 0 ||
            file.size > 6 * 1024 * 1024
        )
            return 'Upload a PNG or JPEG document of at most 6 MB.';
        return;
    }
    const value = data[field].trim();
    if (!value) return required;
    const length = [...value].length;
    switch (field) {
        case 'legal_first_name':
        case 'legal_last_name':
            return length > 40 || !/^[\p{L} ]+$/u.test(value)
                ? 'Use letters and spaces only, up to 40 characters.'
                : undefined;
        case 'email':
            if (length > 40) return 'Email must be at most 40 characters.';
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/u.test(value)
                ? undefined
                : 'Enter a valid email address.';
        case 'mobile': {
            const prefix = context.countries.find(
                (country) => country.code === data.mobile_country_code,
            )?.phone;
            const invalid = 'Select a calling code and enter a valid mobile number.';
            if (!prefix || !/^[0-9 ()-]{4,24}$/.test(value)) return invalid;
            return isValidPhoneNumber(`+${prefix}${value.replace(/[^0-9]/g, '')}`)
                ? undefined
                : invalid;
        }
        case 'date_of_birth': {
            const date = new Date(`${value}T00:00:00Z`);
            const valid =
                /^\d{4}-\d{2}-\d{2}$/.test(value) &&
                value.slice(0, 4) !== '0000' &&
                !Number.isNaN(date.valueOf()) &&
                date.toISOString().slice(0, 10) === value;
            return valid && value < (context.today ?? localToday())
                ? undefined
                : 'Enter a valid birth date before today.';
        }
        case 'document_type':
            return ['id_card', 'passport', 'resident_permit'].includes(value)
                ? undefined
                : 'The selected value is invalid.';
        case 'nationality_country_code':
        case 'residential_country_code':
            return context.countries.some((country) => country.code === value)
                ? undefined
                : 'The selected value is invalid.';
        case 'residential_state':
            if (
                !context.countries.some((country) => country.code === data.residential_country_code)
            )
                return 'The selected value is invalid.';
            return length <= 50 &&
                (context.regions.length
                    ? context.regions.some((region) => region.value === value)
                    : validManualLocality(value))
                ? undefined
                : 'The selected value is invalid.';
        case 'residential_city':
            if (
                !context.countries.some((country) => country.code === data.residential_country_code)
            )
                return 'The selected value is invalid.';
            if (
                localityMode(context.regions, data.residential_state, 'residential_city') ===
                'manual'
            )
                return validManualLocality(value)
                    ? undefined
                    : 'Enter a valid city or select one from the available options.';
            return length <= 50 &&
                context.regions
                    .find((region) => region.value === data.residential_state)
                    ?.cities.some((city) => city.value === value)
                ? undefined
                : 'The selected value is invalid.';
        case 'residential_address':
            return length > 100 ? 'Address must be at most 100 characters.' : undefined;
        case 'residential_postal_code':
            return length > 10 || !/^[A-Za-z0-9 -]+$/.test(value)
                ? 'Use letters, digits, spaces or hyphens, up to 10 characters.'
                : undefined;
    }
}

export function cardholderErrors(
    data: CardholderValidationData,
    context: Context,
): Partial<Record<CardholderField, string>> {
    const errors: Partial<Record<CardholderField, string>> = {};
    for (const field of cardholderFields) {
        const error = cardholderFieldError(field, data, context);
        if (error) errors[field] = error;
    }
    return errors;
}
