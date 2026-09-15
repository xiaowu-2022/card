import type { Region } from '@/hooks/useCardGeography';

export function validManualLocality(value: string): boolean {
    return (
        value === value.trim() &&
        [...value].length <= 50 &&
        /[\p{L}\p{N}]/u.test(value) &&
        !/[\r\n]/u.test(value) &&
        /^[\p{L}\p{M}\p{N} .,'’()·-]+$/u.test(value)
    );
}

// Undefined means still loading or failed, not permission to use free text.
export function localityMode(
    regions: Region[] | undefined,
    state: string,
    field: 'residential_state' | 'residential_city',
) {
    if (!regions) return 'disabled';
    if (field === 'residential_state') return regions.length ? 'select' : 'manual';
    if (!regions.length) return validManualLocality(state) ? 'manual' : 'disabled';
    const region = regions.find((row) => row.value === state);
    if (!region) return 'disabled';
    return region.cities.length ? 'select' : 'manual';
}
