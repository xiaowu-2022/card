import { Link, router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { t } from '@/i18n/admin';
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
}: {
    page: AccountPage<T>;
    columns: { label: string; render: (row: T) => ReactNode }[];
    url: string;
    filters: { search?: string; status?: string; company?: string };
    companies?: { id: string; name: string }[];
    extraQuery?: Record<string, string>;
    statuses?: string[];
    searchLabel: string;
}) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [status, setStatus] = useState(filters.status ?? 'ALL');
    const [company, setCompany] = useState(filters.company ?? 'ALL');
    const pageUrl = (url: string) => {
        const parsed = new URL(url, window.location.origin);
        for (const [key, value] of Object.entries(extraQuery)) parsed.searchParams.set(key, value);
        return parsed.pathname + parsed.search;
    };
    return (
        <div className="overflow-hidden rounded-xl border bg-surface">
            <form
                className="flex flex-wrap gap-3 border-b p-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    router.get(
                        url,
                        {
                            ...extraQuery,
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
                        <SelectTrigger className="w-48" aria-label={t('Filter by company')}>
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
                        <SelectTrigger className="w-48" aria-label={t('Status')}>
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
                <Button type="submit" variant="secondary">
                    {t('Apply')}
                </Button>
            </form>
            <div className="overflow-x-auto">
                <Table>
                    <TableHeader>
                        <TableRow>
                            {columns.map((column) => (
                                <TableHead className="whitespace-nowrap" key={column.label}>
                                    {t(column.label)}
                                </TableHead>
                            ))}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {page.data.length ? (
                            page.data.map((row) => (
                                <TableRow key={row.id}>
                                    {columns.map((column) => (
                                        <TableCell className="whitespace-nowrap" key={column.label}>
                                            {column.render(row)}
                                        </TableCell>
                                    ))}
                                </TableRow>
                            ))
                        ) : (
                            <TableRow>
                                <TableCell
                                    colSpan={columns.length}
                                    className="py-12 text-center text-muted-foreground"
                                >
                                    {t('No matching records.')}
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>
            </div>
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
                            <Link href={pageUrl(page.prev_page_url)}>{t('Previous')}</Link>
                        </Button>
                    ) : (
                        <Button variant="secondary" size="sm" disabled>
                            {t('Previous')}
                        </Button>
                    )}
                    {page.next_page_url ? (
                        <Button asChild variant="secondary" size="sm">
                            <Link href={pageUrl(page.next_page_url)}>{t('Next')}</Link>
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
