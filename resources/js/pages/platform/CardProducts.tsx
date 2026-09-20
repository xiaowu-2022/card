import { useAdminTranslation, t, errorMessage } from '@/i18n/admin';
import { Head, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { InertiaFormProps } from '@inertiajs/react';
import { PageHeader } from '@/components/shared/PageHeader';
import { StatusBadge } from '@/components/shared/StatusBadge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import {
    Table,
    TableHeader,
    TableBody,
    TableRow,
    TableHead,
    TableCell,
} from '@/components/ui/table';
import { MoneyDisplay } from '@/components/shared/MoneyDisplay';
import type { SharedProps } from '@/types/global';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import { MoneyInput } from '@/components/shared/MoneyInput';

type ProductStatus = 'DRAFT' | 'ACTIVE' | 'INACTIVE';
type ProductFormData = {
    name: string;
    card_provider_reference_id: string;
    provider_product_ref: string;
    minimum_initial_load: string;
    minimum_reload: string;
    opening_fee: string;
    balance_limit: string;
    status: ProductStatus;
};
type Product = {
    id: string;
    provider: string;
    cardProviderReferenceId: string | null;
    cardProviderName: string | null;
    routingLocked: boolean;
    localMock: boolean;
    sandboxApiConfigured: boolean;
    providerProductRef: string;
    name: string;
    cardCurrency: string;
    cardType: string;
    minimumInitialLoad: string;
    minimumReload: string;
    balanceLimit: string | null;
    openingFee: string | null;
    status: ProductStatus;
    tenantConfigCount: number;
};
const blank: ProductFormData = {
    name: '',
    card_provider_reference_id: '',
    provider_product_ref: '',
    minimum_initial_load: '20.00000000',
    minimum_reload: '20.00000000',
    opening_fee: '',
    balance_limit: '',
    status: 'DRAFT',
};

type CardProvider = {
    id: string;
    name: string;
    apiBins: boolean;
    bins: { bin: string; scheme: string }[] | null;
    usedBins: { productId: string; bin: string }[];
};

export default function CardProducts({
    products,
    cardProviders,
}: {
    products: Product[];
    cardProviders: CardProvider[];
}) {
    useAdminTranslation();
    const { props, url } = usePage<SharedProps>();
    const canManage = props.auth.admin?.permissions.includes('card_product.manage');
    const [editing, setEditing] = useState<Product | 'new' | null>(() => {
        const productId = new URLSearchParams(url.split('?')[1] ?? '').get('edit');
        return canManage ? (products.find((product) => product.id === productId) ?? null) : null;
    });
    return (
        <PlatformLayout>
            <Head title={t('Card products')} />
            <div className="space-y-6">
                <PageHeader
                    eyebrow={t('Product catalog')}
                    title={t('Card products')}
                    actions={
                        canManage ? (
                            <Button onClick={() => setEditing('new')}>{t('Add product')}</Button>
                        ) : undefined
                    }
                />
                <div className="overflow-x-auto rounded-xl border bg-surface">
                    <Table className="min-w-[960px]">
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t('Product name')}</TableHead>
                                <TableHead>{t('Card providers')}</TableHead>
                                <TableHead>{t('Card BIN range')}</TableHead>
                                <TableHead>{t('Card scheme')}</TableHead>
                                <TableHead>{t('Opening fee (USDT)')}</TableHead>
                                <TableHead className="text-right">
                                    {t('Minimum initial load (USD)')}
                                </TableHead>
                                <TableHead className="text-right">
                                    {t('Minimum reload (USD)')}
                                </TableHead>
                                <TableHead className="text-right">
                                    {t('Company configurations')}
                                </TableHead>
                                <TableHead>{t('Status')}</TableHead>
                                {canManage && (
                                    <TableHead className="text-right">{t('Actions')}</TableHead>
                                )}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {products.length === 0 ? (
                                <TableRow>
                                    <TableCell
                                        colSpan={canManage ? 10 : 9}
                                        className="py-12 text-center text-muted-foreground"
                                    >
                                        {t('No card products')}
                                    </TableCell>
                                </TableRow>
                            ) : (
                                products.map((product) => (
                                    <TableRow key={product.id}>
                                        <TableCell className="min-w-36 max-w-64 whitespace-normal break-words">
                                            <div className="font-medium">{product.name}</div>
                                            <div className="text-xs text-muted-foreground">
                                                {t(product.cardType)} · {product.cardCurrency}
                                            </div>
                                        </TableCell>
                                        <TableCell className="min-w-36 max-w-56 whitespace-normal break-words">
                                            {product.cardProviderName ??
                                                t(
                                                    product.provider === 'PHOTONPAY'
                                                        ? 'Legacy API (preserved)'
                                                        : 'No card provider selected',
                                                )}
                                            {product.provider !== 'PHOTONPAY' && (
                                                <div className="text-xs text-muted-foreground">
                                                    {t(
                                                        product.sandboxApiConfigured
                                                            ? 'Sandbox API configured'
                                                            : product.localMock
                                                              ? 'Mock (local only)'
                                                              : 'API not configured',
                                                    )}
                                                </div>
                                            )}
                                        </TableCell>
                                        <TableCell className="max-w-56 whitespace-normal break-all">
                                            {product.providerProductRef || '—'}
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap">
                                            {cardProviders
                                                .find(
                                                    (provider) =>
                                                        provider.id ===
                                                        product.cardProviderReferenceId,
                                                )
                                                ?.bins?.find(
                                                    (option) =>
                                                        option.bin === product.providerProductRef,
                                                )?.scheme ?? '—'}
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap">
                                            {product.openingFee === null ? (
                                                t('Not configured')
                                            ) : (
                                                <MoneyDisplay
                                                    amount={product.openingFee}
                                                    asset="USDT"
                                                />
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right whitespace-nowrap">
                                            <MoneyDisplay
                                                amount={product.minimumInitialLoad}
                                                asset="USD"
                                                hideSymbol
                                            />
                                        </TableCell>
                                        <TableCell className="text-right whitespace-nowrap">
                                            <MoneyDisplay
                                                amount={product.minimumReload}
                                                asset="USD"
                                                hideSymbol
                                            />
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {product.tenantConfigCount}
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap">
                                            <StatusBadge
                                                status={
                                                    product.status === 'ACTIVE'
                                                        ? 'SUCCESS'
                                                        : 'NEUTRAL'
                                                }
                                                label={t(product.status)}
                                            />
                                        </TableCell>
                                        {canManage && (
                                            <TableCell className="text-right">
                                                <Button
                                                    variant="secondary"
                                                    size="sm"
                                                    onClick={() => setEditing(product)}
                                                >
                                                    {t('Edit')}
                                                </Button>
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </div>
            </div>
            {editing !== null && (
                <ProductEditor
                    key={editing === 'new' ? 'new' : editing.id}
                    product={editing === 'new' ? null : editing}
                    cardProviders={cardProviders}
                    close={() => setEditing(null)}
                />
            )}
        </PlatformLayout>
    );
}

function ProductEditor({
    product,
    cardProviders,
    close,
}: {
    product: Product | null;
    cardProviders: CardProvider[];
    close: () => void;
}) {
    useAdminTranslation();
    const form = useForm<ProductFormData>(
        product
            ? {
                  name: product.name,
                  card_provider_reference_id: product.cardProviderReferenceId ?? '',
                  provider_product_ref: product.providerProductRef,
                  minimum_initial_load: product.minimumInitialLoad,
                  minimum_reload: product.minimumReload,
                  opening_fee: product.openingFee ?? '',
                  balance_limit:
                      product.balanceLimit === null
                          ? ''
                          : product.balanceLimit.replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, ''),
                  status: product.status,
              }
            : blank,
    );
    const selectedProvider = cardProviders.find(
        (provider) => provider.id === form.data.card_provider_reference_id,
    );
    const unchangedRouting =
        product &&
        product.cardProviderReferenceId === (form.data.card_provider_reference_id || null) &&
        product.providerProductRef === form.data.provider_product_ref;
    const invalidBin =
        !unchangedRouting &&
        !product?.routingLocked &&
        selectedProvider?.apiBins &&
        (!selectedProvider.bins?.some((option) => option.bin === form.data.provider_product_ref) ||
            selectedProvider.usedBins.some(
                (item) =>
                    item.bin === form.data.provider_product_ref && item.productId !== product?.id,
            ));
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open && !form.processing) close();
            }}
        >
            <DialogContent
                className="max-h-[90dvh] max-w-2xl overflow-y-auto"
                closeLabel={t('Close')}
                closeDisabled={form.processing}
                aria-describedby={undefined}
            >
                <DialogHeader>
                    <DialogTitle>{t(product ? 'Edit product' : 'Add product')}</DialogTitle>
                </DialogHeader>
                {product?.routingLocked && (
                    <p className="mb-4 text-sm text-muted-foreground">
                        {t(
                            'Card provider and BIN are locked because this product has card history.',
                        )}
                    </p>
                )}
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        if (form.processing || invalidBin) return;
                        const options = { onSuccess: close, preserveScroll: true };
                        if (product) form.put('/platform/card-products/' + product.id, options);
                        else form.post('/platform/card-products', options);
                    }}
                >
                    <fieldset
                        disabled={form.processing}
                        className="grid min-w-0 gap-4 sm:grid-cols-2"
                    >
                        <ProductFields
                            form={form}
                            prefix={product?.id ?? 'create'}
                            cardProviders={cardProviders}
                            locked={product?.routingLocked}
                            legacy={product?.provider === 'PHOTONPAY'}
                        />
                    </fieldset>
                    <div className="mt-6 flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="secondary"
                            disabled={form.processing}
                            onClick={close}
                        >
                            {t('Cancel')}
                        </Button>
                        <Button type="submit" disabled={form.processing || Boolean(invalidBin)}>
                            {t(form.processing ? 'Saving…' : product ? 'Save' : 'Create product')}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function ProductFields({
    form,
    prefix,
    cardProviders,
    locked = false,
    legacy = false,
}: {
    form: InertiaFormProps<ProductFormData>;
    prefix: string;
    cardProviders: CardProvider[];
    locked?: boolean;
    legacy?: boolean;
}) {
    useAdminTranslation();
    const selected = cardProviders.find(
        (provider) => provider.id === form.data.card_provider_reference_id,
    );
    return (
        <>
            <FormField
                id={`${prefix}-name`}
                label={t('Product name')}
                error={errorMessage(form.errors.name)}
            >
                <Input
                    id={`${prefix}-name`}
                    value={form.data.name}
                    onChange={(event) => form.setData('name', event.target.value)}
                />
            </FormField>
            <FormField
                id={`${prefix}-provider`}
                label={t('Card providers')}
                error={errorMessage(form.errors.card_provider_reference_id)}
            >
                <Select
                    disabled={locked || form.processing}
                    value={form.data.card_provider_reference_id || 'none'}
                    onValueChange={(value) => {
                        form.setData((data) => ({
                            ...data,
                            card_provider_reference_id: value === 'none' ? '' : value,
                            provider_product_ref: '',
                        }));
                        form.clearErrors('provider_product_ref');
                    }}
                >
                    <SelectTrigger id={`${prefix}-provider`}>
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="none">
                            {t(legacy ? 'Legacy API (preserved)' : 'No card provider selected')}
                        </SelectItem>
                        {cardProviders.map((provider) => (
                            <SelectItem key={provider.id} value={provider.id}>
                                {provider.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </FormField>
            <FormField
                id={`${prefix}-ref`}
                label={t('Card BIN range')}
                error={errorMessage(form.errors.provider_product_ref)}
            >
                {selected?.apiBins && !locked ? (
                    <>
                        <Select
                            value={form.data.provider_product_ref}
                            disabled={form.processing || !selected.bins?.length}
                            onValueChange={(value) => form.setData('provider_product_ref', value)}
                        >
                            <SelectTrigger id={`${prefix}-ref`}>
                                <SelectValue placeholder={t('Select a PhotonPay BIN')} />
                            </SelectTrigger>
                            <SelectContent>
                                {(selected.bins ?? []).map(({ bin, scheme }) => {
                                    const used = selected.usedBins.some(
                                        (item) => item.bin === bin && item.productId !== prefix,
                                    );
                                    return (
                                        <SelectItem key={bin} value={bin} disabled={used}>
                                            {bin} · {scheme}
                                            {used ? ` · ${t('Already used')}` : ''}
                                        </SelectItem>
                                    );
                                })}
                            </SelectContent>
                        </Select>
                        <p className="mt-1 text-xs text-muted-foreground">
                            {t(
                                selected.bins === null
                                    ? 'BIN list is unavailable. Please try again.'
                                    : selected.bins.length === 0
                                      ? 'No eligible PhotonPay BINs available.'
                                      : 'PhotonPay API · USD rechargeable Mastercard U Cards only',
                            )}
                        </p>
                    </>
                ) : (
                    <Input
                        id={`${prefix}-ref`}
                        disabled={locked}
                        value={form.data.provider_product_ref}
                        onChange={(event) =>
                            form.setData('provider_product_ref', event.target.value)
                        }
                    />
                )}
            </FormField>
            <FormField
                id={`${prefix}-opening-fee`}
                label={t('Opening fee (USDT)')}
                error={errorMessage(form.errors.opening_fee)}
            >
                <MoneyInput
                    id={`${prefix}-opening-fee`}
                    required
                    value={form.data.opening_fee}
                    onChange={(event) => form.setData('opening_fee', event.target.value)}
                />
                <p className="mt-1 text-xs text-muted-foreground">
                    {t('Set by SaaS. Applies to new card orders for all companies.')}
                </p>
            </FormField>
            <FormField
                id={`${prefix}-initial`}
                label={t('Minimum initial load (USD)')}
                error={errorMessage(form.errors.minimum_initial_load)}
            >
                <MoneyInput
                    id={`${prefix}-initial`}
                    inputMode="decimal"
                    value={form.data.minimum_initial_load}
                    onChange={(event) => form.setData('minimum_initial_load', event.target.value)}
                />
            </FormField>
            <FormField
                id={`${prefix}-reload`}
                label={t('Minimum reload (USD)')}
                error={errorMessage(form.errors.minimum_reload)}
            >
                <MoneyInput
                    id={`${prefix}-reload`}
                    inputMode="decimal"
                    value={form.data.minimum_reload}
                    onChange={(event) => form.setData('minimum_reload', event.target.value)}
                />
            </FormField>
            <FormField
                id={`${prefix}-balance-limit`}
                label={t('Default balance limit (USD)')}
                error={errorMessage(form.errors.balance_limit)}
            >
                <MoneyInput
                    id={`${prefix}-balance-limit`}
                    inputMode="decimal"
                    value={form.data.balance_limit}
                    onChange={(event) => form.setData('balance_limit', event.target.value)}
                />
                <p className="text-xs text-muted-foreground">
                    {t(
                        'Applies to existing and new cards unless individually overridden. Leave empty for no limit.',
                    )}
                </p>
            </FormField>
            <FormField
                id={`${prefix}-status`}
                label={t('Status')}
                error={errorMessage(form.errors.status)}
            >
                <Select
                    value={form.data.status}
                    disabled={form.processing}
                    onValueChange={(value) => form.setData('status', value as ProductStatus)}
                >
                    <SelectTrigger id={`${prefix}-status`}>
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="DRAFT">{t('Draft')}</SelectItem>
                        <SelectItem value="ACTIVE">{t('Active')}</SelectItem>
                        <SelectItem value="INACTIVE">{t('Inactive')}</SelectItem>
                    </SelectContent>
                </Select>
            </FormField>
        </>
    );
}
