import { Head, useForm } from '@inertiajs/react';
import type { InertiaFormProps } from '@inertiajs/react';
import { PageHeader } from '@/components/shared/PageHeader';
import { StatusBadge } from '@/components/shared/StatusBadge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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

type ProductStatus = 'DRAFT' | 'ACTIVE' | 'INACTIVE';
type ProductFormData = {
    name: string;
    provider_product_ref: string;
    minimum_initial_load: string;
    minimum_reload: string;
    status: ProductStatus;
};
type Product = {
    id: string;
    provider: string;
    providerProductRef: string;
    name: string;
    cardCurrency: string;
    cardType: string;
    minimumInitialLoad: string;
    minimumReload: string;
    status: ProductStatus;
    tenantConfigCount: number;
};
const blank: ProductFormData = {
    name: '',
    provider_product_ref: '',
    minimum_initial_load: '20.00000000',
    minimum_reload: '20.00000000',
    status: 'DRAFT',
};

export default function CardProducts({ products }: { products: Product[] }) {
    const create = useForm<ProductFormData>(blank);
    return (
        <PlatformLayout>
            <Head title="Card products" />
            <div className="space-y-6">
                <PageHeader
                    eyebrow="Product catalog"
                    title="Card products"
                    description="Define the PhotonPay regular-card products that tenants may offer. This configuration does not call PhotonPay or move funds."
                />
                <Alert>
                    <AlertTitle>Lean demo boundary</AlertTitle>
                    <AlertDescription>
                        PhotonPay, USD and regular-card type are fixed. Shared cards, load fees,
                        issuing and provider credentials are not part of this phase.
                    </AlertDescription>
                </Alert>
                <Card>
                    <CardHeader>
                        <CardTitle>Create platform product</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form
                            className="grid gap-4 md:grid-cols-2 xl:grid-cols-5"
                            onSubmit={(event) => {
                                event.preventDefault();
                                create.post('/platform/card-products', {
                                    onSuccess: () => create.reset(),
                                });
                            }}
                        >
                            <ProductFields form={create} prefix="create" />
                            <div className="flex items-end">
                                <Button className="w-full" disabled={create.processing}>
                                    Create product
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
                <div className="grid gap-5">
                    {products.map((product) => (
                        <ProductEditor key={product.id} product={product} />
                    ))}
                </div>
            </div>
        </PlatformLayout>
    );
}

function ProductEditor({ product }: { product: Product }) {
    const form = useForm<ProductFormData>({
        name: product.name,
        provider_product_ref: product.providerProductRef,
        minimum_initial_load: product.minimumInitialLoad,
        minimum_reload: product.minimumReload,
        status: product.status,
    });
    return (
        <Card>
            <CardHeader className="flex-row items-start justify-between gap-4">
                <div>
                    <CardTitle>{product.name}</CardTitle>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {product.provider} · {product.cardType} · {product.cardCurrency} ·{' '}
                        {product.tenantConfigCount} tenant configuration(s)
                    </p>
                </div>
                <StatusBadge
                    status={product.status === 'ACTIVE' ? 'SUCCESS' : 'NEUTRAL'}
                    label={product.status}
                />
            </CardHeader>
            <CardContent>
                <form
                    className="grid gap-4 md:grid-cols-2 xl:grid-cols-5"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.put(`/platform/card-products/${product.id}`);
                    }}
                >
                    <ProductFields form={form} prefix={product.id} />
                    <div className="flex items-end">
                        <Button className="w-full" variant="secondary" disabled={form.processing}>
                            Save configuration
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}

function ProductFields({
    form,
    prefix,
}: {
    form: InertiaFormProps<ProductFormData>;
    prefix: string;
}) {
    return (
        <>
            <FormField id={`${prefix}-name`} label="Product name" error={form.errors.name}>
                <Input
                    id={`${prefix}-name`}
                    value={form.data.name}
                    onChange={(event) => form.setData('name', event.target.value)}
                />
            </FormField>
            <FormField
                id={`${prefix}-ref`}
                label="PhotonPay CardBin / ref"
                error={form.errors.provider_product_ref}
            >
                <Input
                    id={`${prefix}-ref`}
                    value={form.data.provider_product_ref}
                    onChange={(event) => form.setData('provider_product_ref', event.target.value)}
                />
            </FormField>
            <FormField
                id={`${prefix}-initial`}
                label="Minimum initial load (USD)"
                error={form.errors.minimum_initial_load}
            >
                <Input
                    id={`${prefix}-initial`}
                    inputMode="decimal"
                    value={form.data.minimum_initial_load}
                    onChange={(event) => form.setData('minimum_initial_load', event.target.value)}
                />
            </FormField>
            <FormField
                id={`${prefix}-reload`}
                label="Minimum reload (USD)"
                error={form.errors.minimum_reload}
            >
                <Input
                    id={`${prefix}-reload`}
                    inputMode="decimal"
                    value={form.data.minimum_reload}
                    onChange={(event) => form.setData('minimum_reload', event.target.value)}
                />
            </FormField>
            <FormField id={`${prefix}-status`} label="Status" error={form.errors.status}>
                <Select
                    value={form.data.status}
                    onValueChange={(value) => form.setData('status', value as ProductStatus)}
                >
                    <SelectTrigger id={`${prefix}-status`}>
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="DRAFT">Draft</SelectItem>
                        <SelectItem value="ACTIVE">Active</SelectItem>
                        <SelectItem value="INACTIVE">Inactive</SelectItem>
                    </SelectContent>
                </Select>
            </FormField>
        </>
    );
}
