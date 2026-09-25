import { useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types/global';
import { teamHref, type ReportFilters } from '@/lib/promotion-report';

const listKeys = ['account_id', 'rank', 'funding', 'sort', 'page'] as const;
type TeamVisits = Record<string, ReportFilters>;

// Only presentation filters are retained, scoped to this tab, company and viewer.
// Returning links always use our own route; cached data never determines permission.
export function useTeamReturnHref(filters?: ReportFilters) {
    const { props, url } = usePage<SharedProps>();
    const storageKey =
        props.tenant?.id && props.auth.user?.id
            ? `team-list-visits:${props.tenant.id}:${props.auth.user.id}`
            : null;
    const serialized = JSON.stringify(filters ?? null);
    const [saved, setSaved] = useState<{ key: string | null; visits: TeamVisits }>({
        key: null,
        visits: {},
    });

    useEffect(() => {
        if (!storageKey) return;
        let visits: TeamVisits = {};
        try {
            const value: unknown = JSON.parse(sessionStorage.getItem(storageKey) ?? '{}');
            if (value && typeof value === 'object' && !Array.isArray(value))
                visits = value as TeamVisits;
        } catch {
            /* Unavailable storage falls back to ordinary navigation. */
        }
        const current = JSON.parse(serialized) as ReportFilters | null;
        if (current && url.split('?')[0] === '/promotion/direct') {
            const node = String(current.subject ?? 'root');
            const fields: ReportFilters = {};
            for (const key of listKeys) {
                const value = current[key];
                if (typeof value === 'string' || typeof value === 'number') fields[key] = value;
            }
            delete visits[node];
            visits[node] = fields;
            visits = Object.fromEntries(Object.entries(visits).slice(-100));
            try {
                sessionStorage.setItem(storageKey, JSON.stringify(visits));
            } catch {
                /* Browsing remains available. */
            }
        }
        setSaved({ key: storageKey, visits });
    }, [storageKey, serialized, url]);

    return (subject?: string | null) => {
        const base = teamHref(subject);
        const values = saved.key === storageKey ? saved.visits[subject ?? 'root'] : null;
        if (!values || typeof values !== 'object') return base;
        const query = new URLSearchParams(base.split('?')[1] ?? '');
        for (const key of listKeys) {
            const value = values[key];
            if (
                (typeof value === 'string' || typeof value === 'number') &&
                value !== '' &&
                value !== 'all'
            ) {
                query.set(key, String(value));
            }
        }
        return `/promotion/direct${query.size ? `?${query}` : ''}`;
    };
}
