import { useForm } from '@inertiajs/react';

const materialTextFields = [
    'legal_first_name',
    'legal_last_name',
    'date_of_birth',
    'email',
    'mobile',
    'mobile_country_code',
    'nationality_country_code',
    'residential_address',
    'residential_city',
    'residential_state',
    'residential_country_code',
    'residential_postal_code',
    'document_type',
] as const;

export async function loadCardholderApplicationFields(applicationId: string) {
    const token = document.cookie
        .split('; ')
        .find((value) => value.startsWith('XSRF-TOKEN='))
        ?.slice(11);
    const response = await fetch(`/cards/cardholder/${applicationId}/details`, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(token ?? '') },
    });
    if (!response.ok)
        throw new Error('Cardholder information could not be loaded. Please close and try again.');
    const result = (await response.json()) as { fields?: Record<string, unknown> };
    return Object.fromEntries(
        materialTextFields.map((key) => {
            const value = result.fields?.[key];
            if (typeof value !== 'string')
                throw new Error(
                    'Cardholder information could not be loaded. Please close and try again.',
                );
            return [key, value];
        }),
    ) as Record<(typeof materialTextFields)[number], string>;
}

export function useCardholderMaterialsForm(productId: string) {
    return useForm({
        request_id: crypto.randomUUID(),
        card_product_id: productId,
        legal_first_name: '',
        legal_last_name: '',
        date_of_birth: '',
        email: '',
        mobile: '',
        mobile_country_code: 'CN',
        nationality_country_code: '',
        residential_address: '',
        residential_city: '',
        residential_state: '',
        residential_country_code: '',
        residential_postal_code: '',
        document_type: 'id_card',
        front: null as File | null,
        back: null as File | null,
    });
}
