import { useEffect, useId, useState } from 'react';
import { SearchSelect } from '@/components/ui/search-select';
import { t } from '@/i18n/admin';

export type ReferrerCandidate = {
    id: string;
    account_id: string;
    email: string | null;
    display_name?: string | null;
};
export const referrerCandidateLabel = (item: ReferrerCandidate) =>
    [item.display_name, item.account_id, item.email].filter(Boolean).join(' · ');
export function UserReferrerSelect({
    url,
    value,
    onChange,
    onBrowse,
    disabled = false,
}: {
    url: string;
    value: ReferrerCandidate | null;
    onChange: (value: ReferrerCandidate) => void;
    onBrowse: () => void;
    disabled?: boolean;
}) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const [items, setItems] = useState<ReferrerCandidate[]>([]);
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
            () => {
                void fetch(
                    `${url}/candidates?${new URLSearchParams({ search, page: String(page) })}`,
                    {
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        credentials: 'same-origin',
                        cache: 'no-store',
                        signal: controller.signal,
                    },
                )
                    .then(async (response) => {
                        if (!response.ok || response.redirected) throw new Error();
                        const data = (await response.json()) as {
                            items: ReferrerCandidate[];
                            hasMore: boolean;
                        };
                        if (!Array.isArray(data.items)) throw new Error();
                        if (!controller.signal.aborted) {
                            setItems((previous) =>
                                page === 1
                                    ? data.items
                                    : [
                                          ...new Map(
                                              [...previous, ...data.items].map((item) => [
                                                  item.id,
                                                  item,
                                              ]),
                                          ).values(),
                                      ],
                            );
                            setHasMore(data.hasMore);
                        }
                    })
                    .catch(() => {
                        if (!controller.signal.aborted) setFailed(true);
                    })
                    .finally(() => {
                        if (!controller.signal.aborted) setLoading(false);
                    });
            },
            search ? 250 : 0,
        );
        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [url, open, search, page, retry]);
    const options = [
        ...(value && !items.some((item) => item.id === value.id) && !open ? [value] : []),
        ...items,
    ];
    return (
        <SearchSelect
            id={id}
            label={t('New referrer')}
            value={value?.id ?? ''}
            options={options.map((item) => ({
                value: item.id,
                label: referrerCandidateLabel(item),
            }))}
            placeholder={value ? referrerCandidateLabel(value) : t('Select a matching account')}
            searchLabel={t('Search name, account ID or email')}
            emptyLabel={t(
                loading
                    ? 'Loading members…'
                    : failed
                      ? 'Unable to load members.'
                      : 'No matching members',
            )}
            disabled={disabled}
            searchValue={search}
            onSearchChange={(text) => {
                setSearch(text);
                setPage(1);
                setItems([]);
                setHasMore(false);
                setFailed(false);
                setLoading(true);
                onBrowse();
            }}
            onOpenChange={(next) => {
                setOpen(next);
                if (next) {
                    setSearch('');
                    setPage(1);
                    setItems([]);
                    setHasMore(false);
                    setFailed(false);
                    setLoading(true);
                    onBrowse();
                }
            }}
            onValueChange={(selected) => {
                const item = items.find((candidate) => candidate.id === selected);
                if (item) onChange(item);
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
                        className="partner-select-more"
                        disabled={loading}
                        onClick={() => setPage((n) => n + 1)}
                    >
                        {t(loading ? 'Loading members…' : 'Load more members')}
                    </button>
                ) : null
            }
        />
    );
}
