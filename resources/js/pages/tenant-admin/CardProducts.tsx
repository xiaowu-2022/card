import { useCompanyConfigurationUrl } from '@/hooks/useCompanyConfigurationUrl';
import { ConfigurationForm } from '@/components/admin/CompanyConfiguration';
import { useAdminTranslation, t, errorMessage } from '@/i18n/admin';
import { useState } from 'react';
import type { SharedProps } from '@/types/global';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from '@/components/ui/dialog';
import {
    Table,
    TableHeader,
    TableBody,
    TableRow,
    TableHead,
    TableCell,
} from '@/components/ui/table';
import { Head, useForm, usePage } from '@inertiajs/react';
import { CompanyConfigurationHeader as PageHeader } from '@/components/admin/CompanyConfiguration';
import { StatusBadge } from '@/components/shared/StatusBadge';
import { displayMoney } from '@/lib/exact-amount';
import { Button } from '@/components/ui/button';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { CompanyConfigurationLayout as TenantAdminLayout } from '@/components/admin/CompanyConfiguration';

type Product = {
    id: string;
    provider: string;
    providerProductRef: string;
    name: string;
    cardCurrency: string;
    cardType: string;
    minimumInitialLoad: string;
    minimumReload: string;
    openingFee: string | null;
    status: 'DRAFT' | 'ACTIVE' | 'INACTIVE';
    config: null | {
        displayName: string | null;
        openingFee: string;
        maxCardsPerUser: number;
        status: 'ACTIVE' | 'INACTIVE';
        sortOrder: number;
    };
};

export default function CardProducts({ products }: { products: Product[] }) {
    useAdminTranslation();
    const { configurationBase, auth } = usePage<SharedProps & { configurationBase?: string }>()
        .props;
    const canManage =
        Boolean(configurationBase) && auth.admin?.permissions.includes('tenant.manage');
    const [editing, setEditing] = useState<Product | null>(null);
    return (
        <TenantAdminLayout>
            <Head title={t('Card products')} />
            <div className="space-y-6">
                <PageHeader eyebrow={t('Card catalog')} title={t('Card products')} />
                <div className="min-w-0 overflow-hidden rounded-xl border bg-surface">
                    <Table className="min-w-[960px]">
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t('Product name')}</TableHead>
                                <TableHead>{t('Card BIN range')}</TableHead>
                                <TableHead className="text-right">{t('Opening fee')}</TableHead>
                                <TableHead className="text-right">
                                    {t('Minimum initial load')}
                                </TableHead>
                                <TableHead className="text-right">{t('Minimum reload')}</TableHead>
                                <TableHead className="text-right">
                                    {t('Max cards per user')}
                                </TableHead>
                                <TableHead className="text-right">{t('Sort order')}</TableHead>
                                <TableHead>{t('Tenant offering')}</TableHead>
                                {canManage && (
                                    <TableHead className="text-right">{t('Actions')}</TableHead>
                                )}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {products.map((product) => (
                                <TableRow key={product.id}>
                                    <TableCell>
                                        <p className="font-medium">
                                            {product.config?.displayName || product.name}
                                        </p>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {t(product.cardType)} · {product.cardCurrency}
                                        </p>
                                        <div className="mt-1">
                                            <StatusBadge
                                                status={
                                                    product.status === 'ACTIVE'
                                                        ? 'SUCCESS'
                                                        : 'NEUTRAL'
                                                }
                                                label={t('Platform {{value1}}', {
                                                    value1: t(product.status),
                                                })}
                                            />
                                        </div>
                                    </TableCell>
                                    <TableCell className="font-mono">
                                        {product.providerProductRef || '—'}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {product.openingFee === null
                                            ? t('Not configured')
                                            : displayMoney(product.openingFee)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {displayMoney(product.minimumInitialLoad)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {displayMoney(product.minimumReload)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {product.config?.maxCardsPerUser ?? '—'}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {product.config?.sortOrder ?? '—'}
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            status={
                                                product.config?.status === 'ACTIVE'
                                                    ? 'SUCCESS'
                                                    : 'NEUTRAL'
                                            }
                                            label={
                                                product.config
                                                    ? t(product.config.status)
                                                    : t('Not configured')
                                            }
                                        />
                                    </TableCell>
                                    {canManage && (
                                        <TableCell className="text-right">
                                            <Button
                                                variant="secondary"
                                                size="sm"
                                                className="whitespace-nowrap"
                                                onClick={() => setEditing(product)}
                                            >
                                                {t('Edit')}
                                            </Button>
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))}
                            {products.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={canManage ? 9 : 8}
                                        className="py-10 text-center text-muted-foreground"
                                    >
                                        {t('No card products.')}
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </div>
            </div>
            {canManage && editing && (
                <TenantProductEditor
                    key={editing.id}
                    product={editing}
                    onClose={() => setEditing(null)}
                />
            )}
        </TenantAdminLayout>
    );
}

function TenantProductEditor({ product, onClose }: { product: Product; onClose: () => void }) {
    useAdminTranslation();
    const configurationUrl = useCompanyConfigurationUrl();
    const form = useForm({
        display_name: product.config?.displayName ?? product.name,
        max_cards_per_user: product.config?.maxCardsPerUser ?? 3,
        status: product.config?.status ?? 'INACTIVE',
        sort_order: product.config?.sortOrder ?? 10,
    });
    const close = () => {
        if (!form.processing) {
            form.reset();
            form.clearErrors();
            onClose();
        }
    };
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) close();
            }}
        >
            <DialogContent
                className="max-w-2xl max-h-[85dvh] overflow-y-auto"
                closeLabel={t('Close')}
                closeDisabled={form.processing}
            >
                <DialogHeader>
                    <DialogTitle>{t('Edit tenant offering')}</DialogTitle>
                    <DialogDescription>
                        {product.name} · {product.providerProductRef}
                    </DialogDescription>
                </DialogHeader>
                <div className="space-y-5">
                    <dl className="grid gap-4 rounded-lg bg-muted/60 p-4 text-sm sm:grid-cols-3">
                        <div>
                            <dt className="text-muted-foreground">{t('Card identity')}</dt>
                            <dd className="mt-1 font-medium">
                                {t(product.cardType)} · {product.cardCurrency}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">{t('Minimum initial load')}</dt>
                            <dd className="mt-1 font-medium">
                                {displayMoney(product.minimumInitialLoad)} USD
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">{t('Minimum reload')}</dt>
                            <dd className="mt-1 font-medium">
                                {displayMoney(product.minimumReload)} USD
                            </dd>
                        </div>
                    </dl>
                    <ConfigurationForm
                        className="grid gap-4 sm:grid-cols-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.put(configurationUrl(`/admin/card-products/${product.id}`), {
                                preserveScroll: true,
                                onSuccess: onClose,
                            });
                        }}
                    >
                        {(form.errors as Record<string, string>).form && (
                            <p role="alert" className="text-sm text-danger sm:col-span-2">
                                {errorMessage((form.errors as Record<string, string>).form)}
                            </p>
                        )}
                        <FormField
                            id={`${product.id}-display`}
                            label={t('Customer display name')}
                            error={errorMessage(form.errors.display_name)}
                        >
                            <Input
                                id={`${product.id}-display`}
                                maxLength={120}
                                value={form.data.display_name}
                                onChange={(event) =>
                                    form.setData('display_name', event.target.value)
                                }
                            />
                        </FormField>
                        <div className="space-y-2">
                            <p className="text-sm font-medium">{t('Opening fee (USDT)')}</p>
                            <p>
                                {product.openingFee === null
                                    ? t('Not configured')
                                    : `${displayMoney(product.openingFee)} USDT`}
                            </p>
                        </div>
                        <FormField
                            id={`${product.id}-limit`}
                            label={t('Max cards per user')}
                            error={errorMessage(form.errors.max_cards_per_user)}
                        >
                            <Input
                                id={`${product.id}-limit`}
                                type="number"
                                min={1}
                                max={100}
                                value={form.data.max_cards_per_user}
                                onChange={(event) =>
                                    form.setData('max_cards_per_user', Number(event.target.value))
                                }
                            />
                        </FormField>
                        <FormField
                            id={`${product.id}-sort`}
                            label={t('Sort order')}
                            error={errorMessage(form.errors.sort_order)}
                        >
                            <Input
                                id={`${product.id}-sort`}
                                type="number"
                                min={0}
                                max={4294967295}
                                value={form.data.sort_order}
                                onChange={(event) =>
                                    form.setData('sort_order', Number(event.target.value))
                                }
                            />
                        </FormField>
                        <FormField
                            id={`${product.id}-status`}
                            label={t('Tenant offering')}
                            error={errorMessage(form.errors.status)}
                        >
                            <Select
                                value={form.data.status}
                                onValueChange={(value) =>
                                    form.setData('status', value as 'ACTIVE' | 'INACTIVE')
                                }
                                disabled={product.status !== 'ACTIVE'}
                            >
                                <SelectTrigger id={`${product.id}-status`}>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="ACTIVE">{t('Active')}</SelectItem>
                                    <SelectItem value="INACTIVE">{t('Inactive')}</SelectItem>
                                </SelectContent>
                            </Select>
                        </FormField>
                        <div className="flex justify-end gap-2 sm:col-span-2">
                            <Button
                                type="button"
                                variant="secondary"
                                disabled={form.processing}
                                onClick={close}
                            >
                                {t('Cancel')}
                            </Button>
                            <Button
                                disabled={
                                    form.processing ||
                                    (product.status !== 'ACTIVE' && form.data.status === 'ACTIVE')
                                }
                            >
                                {t('Save tenant offering')}
                            </Button>
                        </div>
                    </ConfigurationForm>
                </div>
            </DialogContent>
        </Dialog>
    );
}
