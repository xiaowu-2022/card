export const holderEditFields = ['legal_first_name', 'legal_last_name', 'email', 'mobile', 'mobile_country_code'] as const;

export function cardholderChanges(
    values: Record<string, string>,
    original: Record<string, string>,
) {
    const changed = holderEditFields.filter(
        (key) => (values[key] ?? '').trim() !== (original[key] ?? ''),
    );
    const keys = new Set<string>(changed);
    // Send the phone number and calling region together.
    if (keys.has('mobile') || keys.has('mobile_country_code')) {
        keys.add('mobile');
        keys.add('mobile_country_code');
    }
    return Object.fromEntries([...keys].map((key) => [key, (values[key] ?? '').trim()]));
}
