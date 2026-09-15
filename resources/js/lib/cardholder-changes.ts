export const holderEditFields = [
    'email',
    'date_of_birth',
    'mobile',
    'mobile_country_code',
    'nationality_country_code',
    'residential_country_code',
    'residential_state',
    'residential_city',
    'residential_address',
    'residential_postal_code',
] as const;

export function cardholderChanges(
    values: Record<string, string>,
    original: Record<string, string>,
) {
    const changed = holderEditFields.filter(
        (key) => (values[key] ?? '').trim() !== (original[key] ?? ''),
    );
    const keys = new Set<string>(changed);
    // Validation requires complete dependent groups, even when only one member changed.
    if (changed.some((key) => key.startsWith('residential_'))) {
        holderEditFields
            .filter((key) => key.startsWith('residential_'))
            .forEach((key) => keys.add(key));
    }
    if (keys.has('mobile') || keys.has('mobile_country_code')) {
        keys.add('mobile');
        keys.add('mobile_country_code');
    }
    return Object.fromEntries([...keys].map((key) => [key, (values[key] ?? '').trim()]));
}
