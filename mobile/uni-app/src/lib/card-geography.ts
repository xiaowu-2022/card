import { computed, ref, watch, type Ref } from 'vue';
import countriesData from '../generated/countries.json';
export type Country = { code: string; name: string; phone: string };
export type Place = { value: string; names: Record<string, string> };
export type Region = Place & { cities: Place[] };
export const countries = countriesData as Country[];
// Public geography is bundled for App and H5; no personal data leaves the device.
let files: Record<string, () => Promise<unknown>>;
// #ifdef H5
files = import.meta.glob('../generated/card-geography/*.json');
// #endif
// #ifndef H5
const bundled = import.meta.glob('../generated/card-geography/*.json', { eager: true });
files = Object.fromEntries(Object.entries(bundled).map(([path, data]) => [path, async () => data]));
// #endif
const cache = new Map<string, Region[]>();
export function useRegions(country: Ref<string>) {
    const data = ref<Region[]>(),
        failed = ref(false);
    let generation = 0;
    async function load() {
        const run = ++generation;
        data.value = undefined;
        failed.value = false;
        if (!country.value) return;
        const code = country.value;
        if (cache.has(code)) {
            data.value = cache.get(code);
            return;
        }
        try {
            const importer = files['../generated/card-geography/' + code + '.json'];
            if (!importer) throw new Error('Unknown country');
            const module = (await importer()) as { default: Region[] };
            if (run !== generation) return;
            if (!Array.isArray(module.default)) throw new Error('Invalid geography');
            cache.set(code, module.default);
            data.value = module.default;
        } catch {
            if (run === generation) failed.value = true;
        }
    }
    watch(country, load, { immediate: true });
    return { data, failed, retry: load };
}
export function countryOptions(locale: string, phone = false) {
    const names = new Intl.DisplayNames([locale], { type: 'region' }),
        priority = ['CN', 'HK', 'MO', 'TW'];
    const rank = (code: string) =>
        priority.includes(code) ? priority.indexOf(code) : priority.length;
    return countries
        .filter((c) => !phone || c.phone)
        .map((c) => ({
            value: c.code,
            label: (phone ? '+' + c.phone + ' ' : '') + (names.of(c.code) ?? c.name),
            keywords: [c.code, c.name, c.phone],
        }))
        .sort((a, b) => rank(a.value) - rank(b.value) || a.label.localeCompare(b.label, locale));
}
export function placeOptions(places: Place[], locale: string) {
    return places
        .map((place) => ({
            value: place.value,
            label: place.names[locale] ?? place.value,
            keywords: [place.value, ...Object.values(place.names)],
        }))
        .sort((a, b) => a.label.localeCompare(b.label, locale));
}
