import { useState } from 'react';
import { Head, useForm } from '@inertiajs/react';
import { Building2, ChevronDown } from 'lucide-react';
import { useAdminTranslation, t } from '@/i18n/admin';
import { DailyFundsChart } from '@/components/admin/DailyFundsChart';
import { AdminDateInput } from '@/components/admin/AdminDateInput';
import { fundsLabels, type FundsDay, type FundsKey } from '@/lib/funds-chart';
import { PageHeader } from '@/components/shared/PageHeader';
import { MoneyDisplay } from '@/components/shared/MoneyDisplay';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { PlatformLayout } from '@/layouts/PlatformLayout';

type ReportKey = FundsKey | 'overflow';
const reportLabels = { ...fundsLabels, overflow: 'Card reload overflow' };
type Filters = { start: string; end: string; scope: 'all' | 'selected'; companies: string[] };
type Company = { id: string; name: string };
export default function Dashboard({
    filters,
    companies,
    days,
    totals,
    financialAccess,
}: {
    filters: Filters;
    companies: Company[];
    days: (FundsDay & { overflow?: string })[];
    totals: Partial<Record<ReportKey, string>>;
    financialAccess: { inflow: boolean; outflow: boolean; overflow: boolean };
}) {
    useAdminTranslation();
    const form = useForm<Filters>(filters);
    const [companyDialog, setCompanyDialog] = useState(false);
    const [search, setSearch] = useState('');
    const series = (['inflow', 'outflow'] as const).filter((key) => financialAccess[key]);
    const displayedKeys: ReportKey[] = [
        ...series,
        ...(financialAccess.overflow ? ['overflow' as const] : []),
        ...(financialAccess.inflow && financialAccess.outflow ? ['net' as const] : []),
    ];
    const hasNet = financialAccess.inflow && financialAccess.outflow;
    const chartKey = JSON.stringify(filters);
    const toggleCompany = (id: string, checked: boolean) => {
        const selected =
            form.data.scope === 'all'
                ? companies.map((company) => company.id)
                : form.data.companies;
        form.setData({
            ...form.data,
            scope: 'selected',
            companies: checked
                ? [...new Set([...selected, id])]
                : selected.filter((value) => value !== id),
        });
    };
    const companyLabel =
        form.data.scope === 'all'
            ? t('All companies')
            : t('Selected companies: {{count}}', { count: form.data.companies.length });
    return (
        <PlatformLayout>
            <Head title={t('Platform overview')} />
            <div className="min-w-0 space-y-6">
                <PageHeader eyebrow={t('Platform scope')} title={t('Funds overview')} />
                <Card>
                    <CardContent className="pt-6">
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.get('/platform/demo', {
                                    preserveState: true,
                                    preserveScroll: true,
                                });
                            }}
                            className="grid items-end gap-4 sm:grid-cols-2 xl:grid-cols-[1fr_1fr_1.3fr_auto]"
                        >
                            <FormField
                                id="funds-start"
                                label={t('Start date')}
                                error={form.errors.start && t(form.errors.start)}
                            >
                                <AdminDateInput
                                    id="funds-start"
                                    label={t('Start date')}
                                    value={form.data.start}
                                    onChange={(value) => form.setData('start', value)}
                                />
                            </FormField>
                            <FormField
                                id="funds-end"
                                label={t('End date')}
                                error={form.errors.end && t(form.errors.end)}
                            >
                                <AdminDateInput
                                    id="funds-end"
                                    label={t('End date')}
                                    value={form.data.end}
                                    onChange={(value) => form.setData('end', value)}
                                />
                            </FormField>
                            <FormField
                                id="funds-companies"
                                label={t('Company')}
                                error={form.errors.companies && t(form.errors.companies)}
                            >
                                <Button
                                    id="funds-companies"
                                    type="button"
                                    variant="secondary"
                                    className="w-full justify-between"
                                    onClick={() => setCompanyDialog(true)}
                                >
                                    <span className="flex min-w-0 items-center gap-2">
                                        <Building2 className="size-4 shrink-0" />
                                        <span className="truncate">{companyLabel}</span>
                                    </span>
                                    <ChevronDown className="size-4" />
                                </Button>
                            </FormField>
                            <Button
                                type="submit"
                                disabled={
                                    form.processing ||
                                    (form.data.scope === 'selected' &&
                                        form.data.companies.length === 0)
                                }
                            >
                                {t('Apply')}
                            </Button>
                        </form>
                        <p className="mt-4 text-xs text-muted-foreground">
                            {t('Daily totals use UTC+8. Select up to 366 days.')}
                        </p>
                    </CardContent>
                </Card>
                {displayedKeys.length === 0 ? (
                    <p className="rounded-lg border bg-surface p-6 text-muted-foreground">
                        {t(
                            'Financial reporting requires top-up, withdrawal or card read permission.',
                        )}
                    </p>
                ) : (
                    <>
                        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                            {displayedKeys.map((key) => (
                                <Card key={key}>
                                    <CardContent className="space-y-3 pt-6">
                                        <p className="text-sm text-muted-foreground">
                                            {t(
                                                key === 'inflow'
                                                    ? 'Total inflow'
                                                    : key === 'outflow'
                                                      ? 'Total outflow'
                                                      : key === 'overflow'
                                                        ? 'Card reload overflow'
                                                        : 'Period retained funds',
                                            )}
                                        </p>
                                        <div className="break-words text-2xl font-semibold">
                                            <MoneyDisplay
                                                amount={totals[key] ?? '0'}
                                                asset={key === 'overflow' ? 'USD' : 'USDT'}
                                            />
                                        </div>
                                    </CardContent>
                                </Card>
                            ))}
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {t(
                                'Completed top-ups and successful withdrawals only. Retained funds = daily inflow minus outflow; not wallet balance.',
                            )}
                        </p>
                        {financialAccess.overflow && (
                            <p className="text-sm text-muted-foreground">
                                {t(
                                    'Overflow counts successful card reloads by settlement date; it remains in wallets and is excluded from retained funds.',
                                )}
                            </p>
                        )}
                        {series.length > 0 && (
                            <DailyFundsChart
                                key={'flows-' + chartKey}
                                days={days}
                                series={series}
                            />
                        )}
                        {hasNet && (
                            <DailyFundsChart
                                key={'net-' + chartKey}
                                days={days}
                                series={['net']}
                                bars
                            />
                        )}
                        <details className="rounded-xl border bg-surface p-5">
                            <summary className="cursor-pointer text-sm font-semibold">
                                {t('Daily details')}
                            </summary>
                            <div className="mt-4 overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b text-left text-muted-foreground">
                                            <th className="p-3">{t('Date')}</th>
                                            {displayedKeys.map((key) => (
                                                <th
                                                    key={key}
                                                    className="whitespace-nowrap p-3 text-right"
                                                >
                                                    {t(reportLabels[key])}{' '}
                                                    {key === 'overflow' ? '(USD)' : '(USDT)'}
                                                </th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {days.map((day) => (
                                            <tr key={day.date} className="border-b last:border-0">
                                                <td className="whitespace-nowrap p-3">
                                                    {day.date}
                                                </td>
                                                {displayedKeys.map((key) => (
                                                    <td key={key} className="p-3 text-right">
                                                        <MoneyDisplay
                                                            amount={day[key] ?? '0'}
                                                            asset={
                                                                key === 'overflow' ? 'USD' : 'USDT'
                                                            }
                                                            hideSymbol
                                                        />
                                                    </td>
                                                ))}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    </>
                )}
            </div>
            <Dialog open={companyDialog} onOpenChange={setCompanyDialog}>
                <DialogContent aria-describedby={undefined}>
                    <DialogHeader>
                        <DialogTitle>{t('Select companies')}</DialogTitle>
                    </DialogHeader>
                    <Input
                        aria-label={t('Search companies')}
                        placeholder={t('Search companies')}
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                    />
                    <label className="flex cursor-pointer items-center gap-3 rounded-lg border p-3">
                        <Checkbox
                            checked={form.data.scope === 'all'}
                            onCheckedChange={(checked) =>
                                form.setData({
                                    ...form.data,
                                    scope: checked ? 'all' : 'selected',
                                    companies: [],
                                })
                            }
                        />
                        {t('All companies')}
                    </label>
                    <div className="max-h-72 space-y-1 overflow-y-auto">
                        {companies
                            .filter((company) =>
                                company.name
                                    .toLocaleLowerCase()
                                    .includes(search.toLocaleLowerCase()),
                            )
                            .map((company) => (
                                <label
                                    key={company.id}
                                    className="flex cursor-pointer items-center gap-3 rounded-lg p-3 hover:bg-muted"
                                >
                                    <Checkbox
                                        checked={
                                            form.data.scope === 'all' ||
                                            form.data.companies.includes(company.id)
                                        }
                                        onCheckedChange={(checked) =>
                                            toggleCompany(company.id, checked === true)
                                        }
                                    />
                                    <span className="break-all">{company.name}</span>
                                </label>
                            ))}
                    </div>
                    {form.data.scope === 'selected' && form.data.companies.length === 0 && (
                        <p className="text-sm text-danger">{t('Select at least one company.')}</p>
                    )}
                    <div className="flex justify-end">
                        <Button type="button" onClick={() => setCompanyDialog(false)}>
                            {t('Done')}
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>
        </PlatformLayout>
    );
}
