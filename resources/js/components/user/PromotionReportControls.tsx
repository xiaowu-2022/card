import { useEffect, useState } from 'react';
import { Link } from '@inertiajs/react';
import { CalendarDays, SlidersHorizontal, X } from 'lucide-react';
import type { ReactNode } from 'react';
import {
    Dialog,
    DialogContent,
    DialogTitle,
    DialogDescription,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    incomeLabels,
    reportMoney,
    fullMoney,
    type IncomeTotals,
    type ReportPeriod,
} from '@/lib/promotion-report';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectTrigger,
    SelectValue,
    SelectContent,
    SelectItem,
} from '@/components/ui/select';
import { t } from '@/i18n';

export function ReportSelect({
    label,
    value,
    options,
    onChange,
}: {
    label: string;
    value: string;
    options: [string, string][];
    onChange: (value: string) => void;
}) {
    return (
        <label className="min-w-0 space-y-1 text-xs text-muted-foreground">
            <span>{label}</span>
            <Select value={value} onValueChange={onChange}>
                <SelectTrigger className="w-full min-w-0 bg-white">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {options.map(([key, text]) => (
                        <SelectItem key={key} value={key}>
                            {text}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </label>
    );
}

export function ReportAccountSearch({
    value,
    onSearch,
}: {
    value: string;
    onSearch: (value: string) => void;
}) {
    const [account, setAccount] = useState(value);
    useEffect(() => setAccount(value), [value]);
    return (
        <form
            className="flex min-w-0 gap-2"
            onSubmit={(event) => {
                event.preventDefault();
                onSearch(account);
            }}
        >
            <Input
                className="min-w-0 flex-1"
                aria-label={t('Source account ID')}
                placeholder={t('Search account ID')}
                inputMode="numeric"
                maxLength={24}
                value={account}
                onChange={(event) => setAccount(event.target.value.replace(/\D/g, ''))}
            />
            <Button type="submit">{t('Search')}</Button>
        </form>
    );
}

export function ReportDateRange({
    period,
    allowAll = false,
    onChange,
}: {
    period: ReportPeriod;
    allowAll?: boolean;
    onChange: (from: string | null, to: string | null) => void;
}) {
    const [from, setFrom] = useState(period.dateFrom ?? period.today);
    const [to, setTo] = useState(period.dateTo ?? period.today);
    const [custom, setCustom] = useState(false);
    useEffect(() => {
        setFrom(period.dateFrom ?? period.today);
        setTo(period.dateTo ?? period.today);
    }, [period.dateFrom, period.dateTo, period.today]);
    return (
        <div className="space-y-3">
            <div className="flex flex-wrap gap-2" role="group" aria-label={t('Date range')}>
                {allowAll && (
                    <Button
                        size="sm"
                        variant={!period.dateFrom ? 'default' : 'secondary'}
                        aria-pressed={!period.dateFrom}
                        onClick={() => {
                            setCustom(false);
                            onChange(null, null);
                        }}
                    >
                        {t('All dates')}
                    </Button>
                )}
                {(
                    [
                        ['1', 'Today'],
                        ['7', 'Last 7 days'],
                        ['30', 'Last 30 days'],
                    ] as const
                ).map(([days, label]) => {
                    const active =
                        period.dateFrom === period.presets[days] && period.dateTo === period.today;
                    return (
                        <Button
                            key={days}
                            size="sm"
                            variant={active ? 'default' : 'secondary'}
                            aria-pressed={active}
                            onClick={() => {
                                setCustom(false);
                                onChange(period.presets[days]!, period.today);
                            }}
                        >
                            {t(label)}
                        </Button>
                    );
                })}
                <Button
                    size="sm"
                    variant="ghost"
                    aria-expanded={custom}
                    onClick={() => setCustom(!custom)}
                >
                    {t('Custom dates')}
                </Button>
            </div>
            {custom && (
                <form
                    className="grid min-w-0 grid-cols-2 gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        if (from && to && from <= to) {
                            onChange(from, to);
                            setCustom(false);
                        }
                    }}
                >
                    <label className="min-w-0 text-xs">
                        {t('Start date')}
                        <Input
                            className="mt-1 w-full min-w-0"
                            type="date"
                            required
                            value={from}
                            max={to}
                            onInput={(e) => setFrom(e.currentTarget.value)}
                            onChange={(e) => setFrom(e.target.value)}
                        />
                    </label>
                    <label className="min-w-0 text-xs">
                        {t('End date')}
                        <Input
                            className="mt-1 w-full min-w-0"
                            type="date"
                            required
                            value={to}
                            min={from}
                            onInput={(e) => setTo(e.currentTarget.value)}
                            onChange={(e) => setTo(e.target.value)}
                        />
                    </label>
                    <Button
                        className="col-span-2"
                        type="submit"
                        disabled={!from || !to || from > to}
                    >
                        {t('Apply dates')}
                    </Button>
                </form>
            )}
            <p className="text-xs text-muted-foreground">
                {t('Reporting period')}:{' '}
                {period.dateFrom ? `${period.dateFrom} — ${period.dateTo}` : t('All dates')}
            </p>
        </div>
    );
}

export function ReportSummary({
    totals,
    title = t('My commission income'),
}: {
    totals: IncomeTotals;
    title?: string;
}) {
    return (
        <section className="report-income" aria-label={title}>
            <p className="report-eyebrow">
                {title} <span>USDT</span>
            </p>
            <details className="report-total">
                <summary aria-label={t('Exact amounts')}>
                    {reportMoney(totals.total).replace(' USDT', '')}
                </summary>
                <p className="report-exact">{fullMoney(totals.total)}</p>
            </details>
            <dl className="report-income-split">
                {Object.entries(incomeLabels).map(([kind, label]) => (
                    <div key={kind} className="min-w-0">
                        <dt className="text-xs text-muted-foreground">{t(label)}</dt>
                        <dd className="mt-1 break-words text-sm font-medium tabular-nums">
                            <details>
                                <summary aria-label={t('Exact amounts')}>
                                    {reportMoney(totals[kind as keyof IncomeTotals]).replace(
                                        ' USDT',
                                        '',
                                    )}
                                </summary>
                                <span className="report-exact">
                                    {fullMoney(totals[kind as keyof IncomeTotals])}
                                </span>
                            </details>
                        </dd>
                    </div>
                ))}
            </dl>
        </section>
    );
}

export function ReportDateButton({
    period,
    allowAll = false,
    onChange,
}: {
    period: ReportPeriod;
    allowAll?: boolean;
    onChange: (from: string | null, to: string | null) => void;
}) {
    const [open, setOpen] = useState(false);
    const preset =
        period.dateTo === period.today
            ? Object.entries(period.presets).find(([, value]) => value === period.dateFrom)?.[0]
            : null;
    const label = !period.dateFrom
        ? t('All dates')
        : preset === '1'
          ? t('Today')
          : preset === '7'
            ? t('Last 7 days')
            : preset === '30'
              ? t('Last 30 days')
              : `${period.dateFrom} — ${period.dateTo}`;
    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <button type="button" className="report-tool">
                    <CalendarDays size={16} aria-hidden="true" />
                    <span>{label}</span>
                </button>
            </DialogTrigger>
            <DialogContent className="report-filter-dialog" closeLabel={t('Close')}>
                <DialogTitle>{t('Date range')}</DialogTitle>
                <DialogDescription>
                    {t('Dates and times follow the company timezone.')}
                </DialogDescription>
                <ReportDateRange
                    period={period}
                    allowAll={allowAll}
                    onChange={(from, to) => {
                        onChange(from, to);
                        setOpen(false);
                    }}
                />
            </DialogContent>
        </Dialog>
    );
}

export function ReportFilterPanel({
    count,
    children,
    onApply,
    onReset,
}: {
    count: number;
    children: ReactNode;
    onApply: () => void;
    onReset: () => void;
}) {
    const [open, setOpen] = useState(false);
    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <button type="button" className="report-tool">
                    <SlidersHorizontal size={16} aria-hidden="true" />
                    <span>{t('Filters')}</span>
                    {count > 0 && <span className="report-filter-count">{count}</span>}
                </button>
            </DialogTrigger>
            <DialogContent className="report-filter-dialog" closeLabel={t('Close')}>
                <DialogTitle>{t('Filters')}</DialogTitle>
                <DialogDescription>{t('Choose the records you want to see.')}</DialogDescription>
                <div className="report-filter-fields">{children}</div>
                <div className="report-filter-actions">
                    <Button
                        variant="secondary"
                        onClick={() => {
                            onReset();
                            setOpen(false);
                        }}
                    >
                        {t('Reset filters')}
                    </Button>
                    <Button
                        onClick={() => {
                            onApply();
                            setOpen(false);
                        }}
                    >
                        {t('Apply filters')}
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}

export function ReportFilterChip({ label, onClear }: { label: string; onClear: () => void }) {
    return (
        <button className="report-filter-chip" onClick={onClear}>
            {label}
            <X size={13} aria-hidden="true" />
            <span className="sr-only">{t('Clear filter')}</span>
        </button>
    );
}

export function ReportPagination({
    page,
    hasMore,
    onPage,
}: {
    page: number;
    hasMore: boolean;
    onPage: (page: number) => void;
}) {
    if (page === 1 && !hasMore) return null;
    return (
        <nav className="flex items-center justify-between gap-2 py-4" aria-label={t('Pagination')}>
            <Button variant="ghost" disabled={page === 1} onClick={() => onPage(page - 1)}>
                {t('Previous')}
            </Button>
            <span className="text-xs text-muted-foreground">{t('Page {{page}}', { page })}</span>
            <Button variant="ghost" disabled={!hasMore} onClick={() => onPage(page + 1)}>
                {t('Next')}
            </Button>
        </nav>
    );
}

export function ReportReset({ href }: { href: string }) {
    return (
        <Link className="inline-flex min-h-11 items-center text-xs underline" href={href}>
            {t('Reset filters')}
        </Link>
    );
}
