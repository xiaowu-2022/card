import { useEffect, useState } from 'react';
import type { SearchOption } from '@/components/ui/search-select';

export type Country = { code: string; name: string; phone: string };
export type Place = { value: string; names: Record<string, string> };
export type Region = Place & { cities: Place[] };
const cache = new Map<string, unknown>();

export function useCardGeography<T>(file: string) {
    const [result, setResult] = useState<{ file: string; data?: T; failed?: boolean }>({
        file: '',
    });
    const [attempt, setAttempt] = useState(0);
    useEffect(() => {
        if (!file) return;
        const controller = new AbortController();
        if (cache.has(file)) {
            setResult({ file, data: cache.get(file) as T });
            return;
        }
        fetch(`/data/card-geography/${file}.json`, {
            signal: controller.signal,
            credentials: 'omit',
        })
            .then((response) => {
                if (!response.ok) throw new Error('Geography unavailable');
                return response.json() as Promise<T>;
            })
            .then((data) => {
                if (!controller.signal.aborted) {
                    cache.set(file, data);
                    setResult({ file, data });
                }
            })
            .catch(() => {
                if (!controller.signal.aborted) setResult({ file, failed: true });
            });
        return () => controller.abort();
    }, [file, attempt]);
    return {
        data: result.file === file ? result.data : undefined,
        failed: result.file === file && result.failed,
        retry: () => {
            setResult({ file: '' });
            setAttempt((value) => value + 1);
        },
    };
}

export function countryOptions(
    countries: Country[],
    locale: string,
    phone = false,
): SearchOption[] {
    const names = new Intl.DisplayNames([locale], { type: 'region' });
    const priority = ['CN', 'HK', 'MO', 'TW'];
    return countries
        .filter((country) => !phone || country.phone)
        .map((country) => ({
            value: country.code,
            label: `${phone ? `+${country.phone} ` : ''}${names.of(country.code) ?? country.name}`,
            keywords: [country.code, country.name, country.phone],
        }))
        .sort((a, b) => {
            const rank = (code: string) =>
                priority.includes(code) ? priority.indexOf(code) : priority.length;
            return rank(a.value) - rank(b.value) || a.label.localeCompare(b.label, locale);
        });
}

export function placeOptions(places: Place[], locale: string): SearchOption[] {
    return places
        .map((place) => ({
            value: place.value,
            label: place.names[locale] ?? place.value,
            keywords: [place.value, ...Object.values(place.names)],
        }))
        .sort((a, b) => a.label.localeCompare(b.label, locale));
}
