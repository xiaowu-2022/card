import { useEffect, useState } from 'react';
import { SearchSelect } from '@/components/ui/search-select';
import { t } from '@/i18n/admin';
type Candidate = { account_id: string; display_name: string | null };
export function PartnerUserSelect({
    companyId,
    value,
    onChange,
}: {
    companyId: string;
    value: string;
    onChange: (value: string) => void;
}) {
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const [items, setItems] = useState<Candidate[]>([]);
    const [selected, setSelected] = useState<Candidate | null>(null);
    const [hasMore, setHasMore] = useState(false);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    const [retry, setRetry] = useState(0);
    useEffect(() => {
        if (!open) return;
        const controller = new AbortController();
        setLoading(true);
        setFailed(false);
        const timer = setTimeout(
            async () => {
                try {
                    const params = new URLSearchParams({ search, page: String(page) });
                    const response = await fetch(
                        `/platform/tenants/${companyId}/partner-candidates?${params}`,
                        {
                            headers: { Accept: 'application/json' },
                            signal: controller.signal,
                            cache: 'no-store',
                        },
                    );
                    if (!response.ok) throw new Error('Lookup failed');
                    const data = (await response.json()) as {
                        items: Candidate[];
                        hasMore: boolean;
                    };
                    if (!controller.signal.aborted) {
                        setItems((previous) =>
                            page === 1 ? data.items : [...previous, ...data.items],
                        );
                        setHasMore(data.hasMore);
                    }
                } catch {
                    if (!controller.signal.aborted) setFailed(true);
                } finally {
                    if (!controller.signal.aborted) setLoading(false);
                }
            },
            search ? 250 : 0,
        );
        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [open, companyId, search, page, retry]);
    const options = [
        ...(selected && !items.some((i) => i.account_id === selected.account_id) && !open
            ? [selected]
            : []),
        ...items,
    ];
    return (
        <SearchSelect
            id="partner-user"
            label={t('Select member')}
            value={value}
            options={options.map((item) => ({
                value: item.account_id,
                label: `${item.account_id}${item.display_name ? ` · ${item.display_name}` : ''}`,
            }))}
            placeholder={
                selected?.account_id === value
                    ? `${value}${selected.display_name ? ` · ${selected.display_name}` : ''}`
                    : t('Select member')
            }
            searchLabel={t('Search account ID, nickname or email')}
            emptyLabel={t(
                loading
                    ? 'Loading members…'
                    : failed
                      ? 'Unable to load members.'
                      : 'No matching members',
            )}
            searchValue={search}
            onSearchChange={(text) => {
                setSearch(text);
                setPage(1);
                setItems([]);
                setHasMore(false);
            }}
            onOpenChange={(next) => {
                setOpen(next);
                if (next) {
                    setSearch('');
                    setPage(1);
                    setItems([]);
                    setHasMore(false);
                }
            }}
            onValueChange={(id) => {
                setSelected(items.find((i) => i.account_id === id) ?? null);
                onChange(id);
                setOpen(false);
            }}
            footer={
                failed ? (
                    <button
                        type="button"
                        className="partner-select-more"
                        onClick={() => setRetry((n) => n + 1)}
                    >
                        {t('Retry')}
                    </button>
                ) : hasMore ? (
                    <button
                        type="button"
                        disabled={loading}
                        className="partner-select-more"
                        onClick={() => setPage((n) => n + 1)}
                    >
                        {t(loading ? 'Loading members…' : 'Load more members')}
                    </button>
                ) : null
            }
        />
    );
}
