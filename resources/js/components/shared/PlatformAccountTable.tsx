import { usePlatformUi } from '@/components/admin/platform-ui-context';
import { useId } from 'react';
import { adminAssetLabel } from '@/lib/admin-asset-label';
import { useEditorRouter } from '@/components/admin/useEditorRouter';
import { Link } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { t } from '@/i18n/admin';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

export type AccountPage<T> = {
    data: T[];
    total: number;
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export function PlatformAccountTable<T extends { id: string }>({
    page,
    columns,
    url,
    filters,
    statuses,
    searchLabel,
    companies,
    extraQuery = {},
    showFilters = true,
    selectFilters = [],
    rowKey = (row) => row.id,
}: {
    page: AccountPage<T>;
    rowKey?: (row: T) => string;
    columns: {
        label: string;
        header?: ReactNode;
        className?: string;
        align?: 'left' | 'right';
        pin?: 'left' | 'right';
        render: (row: T) => ReactNode;
    }[];
    url: string;
    filters: {
        search?: string;
        status?: string;
        company?: string;
        [key: string]: string | undefined;
    };
    companies?: { id: string; name: string }[];
    selectFilters?: {
        key: string;
        advanced?: boolean;
        label: string;
        allLabel: string;
        values: string[];
        valueLabels?: Record<string, string>;
    }[];
    extraQuery?: Record<string, string>;
    showFilters?: boolean;
    statuses?: string[];
    searchLabel: string;
}) {
    const router = useEditorRouter();
    const platform = usePlatformUi();
    const filterId = useId();
    const [moreFilters, setMoreFilters] = useState(false);
    const hasAdvanced = platform && selectFilters.some((item) => item.advanced);
    const amountLabels = new Set([
        'Exact amount',
        'Fee',
        'Amounts',
        'Balance limit',
        'Current balance',
        'Overflow balance',
        'Provider balance',
        'Available balance',
        'Actual deposits',
        'Cumulative advances',
        'Cumulative commission',
        'Withdrawal amount',
        'Held amount',
        'Security deposit',
        'Amount',
        'Total inflow',
        'Total outflow',
        'Actual inflow',
        'Advance amount',
    ]);
    const applied = [
        filters.company && companies?.find((item) => item.id === filters.company)?.name,
        filters.search,
        filters.status && t(filters.status),
        ...selectFilters.map((item) =>
            filters[item.key] && filters[item.key] !== 'ALL'
                ? `${t(item.label)}: ${t(item.valueLabels?.[filters[item.key]!] ?? adminAssetLabel(filters[item.key]!))}`
                : undefined,
        ),
    ].filter(Boolean);

    const [additional, setAdditional] = useState<Record<string, string>>(() =>
        Object.fromEntries(selectFilters.map((item) => [item.key, filters[item.key] ?? 'ALL'])),
    );
    const [search, setSearch] = useState(filters.search ?? '');
    const [status, setStatus] = useState(filters.status ?? 'ALL');
    const [company, setCompany] = useState(filters.company ?? 'ALL');
    const pageUrl = (url: string) => {
        const parsed = new URL(url, window.location.origin);
        for (const [key, value] of Object.entries(extraQuery)) parsed.searchParams.set(key, value);
        return parsed.pathname + parsed.search;
    };
    return (
        <div className="min-w-0 max-w-full overflow-hidden rounded-xl border bg-surface">
            {showFilters && (
                <form
                    id={filterId}
                    className="flex flex-wrap gap-3 border-b p-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.get(
                            url,
                            {
                                ...extraQuery,
                                ...Object.fromEntries(
                                    Object.entries(additional).map(([key, value]) => [
                                        key,
                                        value === 'ALL' ? undefined : value,
                                    ]),
                                ),
                                company: companies && company !== 'ALL' ? company : undefined,
                                search: search || undefined,
                                status: statuses && status !== 'ALL' ? status : undefined,
                            },
                            { preserveState: true, replace: true },
                        );
                    }}
                >
                    {companies && (
                        <Select value={company} onValueChange={setCompany}>
                            <SelectTrigger
                                className="platform-filter-field w-48"
                                aria-label={t('Filter by company')}
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="ALL">{t('All companies')}</SelectItem>
                                {companies.map((item) => (
                                    <SelectItem key={item.id} value={item.id}>
                                        {item.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    )}
                    <Input
                        className="min-w-0 flex-1 basis-48"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        aria-label={searchLabel}
                        placeholder={searchLabel}
                    />
                    {statuses && (
                        <Select value={status} onValueChange={setStatus}>
                            <SelectTrigger
                                className="platform-filter-field w-48"
                                aria-label={t('Status')}
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="ALL">{t('All statuses')}</SelectItem>
                                {statuses.map((value) => (
                                    <SelectItem key={value} value={value}>
                                        {t(value)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    )}
                    {selectFilters
                        .filter((item) => !platform || !item.advanced || moreFilters)
                        .map((item) => (
                            <Select
                                key={item.key}
                                value={additional[item.key]}
                                onValueChange={(value) =>
                                    setAdditional((current) => ({ ...current, [item.key]: value }))
                                }
                            >
                                <SelectTrigger
                                    className="platform-filter-field w-40"
                                    aria-label={t(item.label)}
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="ALL">{t(item.allLabel)}</SelectItem>
                                    {item.values.map((value) => (
                                        <SelectItem key={value} value={value}>
                                            {item.valueLabels
                                                ? t(item.valueLabels[value] ?? value)
                                                : adminAssetLabel(value)}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        ))}
                    {hasAdvanced && (
                        <Button
                            type="button"
                            variant="ghost"
                            aria-expanded={moreFilters}
                            aria-controls={filterId}
                            onClick={() => setMoreFilters(!moreFilters)}
                        >
                            {t('More filters')}
                        </Button>
                    )}
                    <Button type="submit" variant="secondary">
                        {t('Apply')}
                    </Button>
                </form>
            )}
            {platform && showFilters && applied.length > 0 && (
                <div
                    aria-label={t('Applied filters')}
                    className="platform-filter-summary flex flex-wrap gap-2 text-xs text-muted-foreground"
                >
                    <span className="sr-only">{t('Applied filters')}</span>
                    {applied.map((value, index) => (
                        <span
                            key={index}
                            className="max-w-full break-words rounded-md bg-muted px-2 py-1"
                        >
                            {value}
                        </span>
                    ))}
                    <button
                        type="button"
                        className="shrink-0 px-2 py-1 text-primary hover:underline"
                        onClick={() => {
                            setSearch('');
                            setStatus('ALL');
                            setCompany('ALL');
                            setAdditional(
                                Object.fromEntries(selectFilters.map((item) => [item.key, 'ALL'])),
                            );
                            router.get(url, extraQuery, { preserveState: true, replace: true });
                        }}
                    >
                        {t('Reset filters')}
                    </button>
                </div>
            )}
            <Table>
                <TableHeader>
                    <TableRow>
                        {columns.map((column, index) => (
                            <TableHead
                                data-column-align={
                                    platform
                                        ? (column.align ??
                                          (amountLabels.has(column.label) ? 'right' : undefined))
                                        : undefined
                                }
                                data-column-pin={platform ? column.pin : undefined}
                                className={cn(
                                    'whitespace-nowrap',
                                    column.label === 'Actions' &&
                                        'sticky right-0 z-10 w-px bg-surface shadow-[-1px_0_0_var(--color-border)]',
                                    column.className,
                                )}
                                key={`${column.label}:${index}`}
                            >
                                {column.header ?? t(column.label)}
                            </TableHead>
                        ))}
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {page.data.length
                        ? page.data.map((row) => (
                              <TableRow key={rowKey(row)}>
                                  {columns.map((column, index) => (
                                      <TableCell
                                          data-column-align={
                                              platform
                                                  ? (column.align ??
                                                    (amountLabels.has(column.label)
                                                        ? 'right'
                                                        : undefined))
                                                  : undefined
                                          }
                                          data-column-pin={platform ? column.pin : undefined}
                                          className={cn(
                                              'whitespace-nowrap',
                                              column.label === 'Actions' &&
                                                  'sticky right-0 z-10 w-px bg-surface shadow-[-1px_0_0_var(--color-border)]',
                                              column.className,
                                          )}
                                          key={`${column.label}:${index}`}
                                      >
                                          {renderCell(
                                              column.render(row),
                                              platform &&
                                                  [
                                                      'Created',
                                                      'Last login',
                                                      'Operation time',
                                                      'Arrival time',
                                                  ].includes(column.label),
                                          )}
                                      </TableCell>
                                  ))}
                              </TableRow>
                          ))
                        : null}
                </TableBody>
            </Table>
            {page.data.length === 0 && (
                <p className="px-4 py-12 text-center text-sm text-muted-foreground">
                    {t('No matching records.')}
                </p>
            )}
            <div className="flex flex-wrap items-center justify-between gap-3 border-t p-4 text-sm">
                <span className="text-muted-foreground">
                    {t('Page {{page}} of {{pages}} · {{total}} records', {
                        page: page.current_page,
                        pages: page.last_page,
                        total: page.total,
                    })}
                </span>
                <div className="flex gap-2">
                    {page.prev_page_url ? (
                        <Button asChild variant="secondary" size="sm">
                            <Link preserveScroll href={pageUrl(page.prev_page_url)}>
                                {t('Previous')}
                            </Link>
                        </Button>
                    ) : (
                        <Button variant="secondary" size="sm" disabled>
                            {t('Previous')}
                        </Button>
                    )}
                    {page.next_page_url ? (
                        <Button asChild variant="secondary" size="sm">
                            <Link preserveScroll href={pageUrl(page.next_page_url)}>
                                {t('Next')}
                            </Link>
                        </Button>
                    ) : (
                        <Button variant="secondary" size="sm" disabled>
                            {t('Next')}
                        </Button>
                    )}
                </div>
            </div>
        </div>
    );
}

function renderCell(value: ReactNode, date = false) {
    if (typeof value !== 'string') return value;
    const parts = date ? value.match(/^(.*?)[ ,]+(\d{1,2}:\d{2}.*)$/) : null;
    if (parts)
        return (
            <span className="block whitespace-nowrap text-xs tabular-nums" title={value}>
                {parts[1]}
                <span className="block text-muted-foreground">{parts[2]}</span>
            </span>
        );
    return (
        <span className="block max-w-52 truncate" title={value}>
            {value}
        </span>
    );
}
