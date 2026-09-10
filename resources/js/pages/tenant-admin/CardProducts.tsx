import { Head, useForm } from '@inertiajs/react';
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
import { TenantAdminLayout } from '@/layouts/TenantAdminLayout';

type Product = {
    id: string;
    provider: string;
    providerProductRef: string;
    name: string;
    cardCurrency: string;
    cardType: string;
    minimumInitialLoad: string;
    minimumReload: string;
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
    return (
        <TenantAdminLayout>
            <Head title="Card products" />
            <div className="space-y-6">
                <PageHeader
                    eyebrow="Card catalog"
                    title="Card products"
                    description="Choose which platform products your tenant offers and configure future pricing."
                />
                <Alert>
                    <AlertTitle>Configuration only</AlertTitle>
                    <AlertDescription>
                        Opening fee changes future pricing only. No user is charged and no Wallet or
                        Ledger entry is changed here.
                    </AlertDescription>
                </Alert>
                <div className="grid gap-5">
                    {products.map((product) => (
                        <TenantProductEditor key={product.id} product={product} />
                    ))}
                </div>
            </div>
        </TenantAdminLayout>
    );
}

function TenantProductEditor({ product }: { product: Product }) {
    const form = useForm({
        display_name: product.config?.displayName ?? product.name,
        opening_fee: product.config?.openingFee ?? '0.00000000',
        max_cards_per_user: product.config?.maxCardsPerUser ?? 3,
        status: product.config?.status ?? 'INACTIVE',
        sort_order: product.config?.sortOrder ?? 10,
    });
    return (
        <Card>
            <CardHeader className="flex-row items-start justify-between gap-4">
                <div>
                    <CardTitle>{product.config?.displayName || product.name}</CardTitle>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {product.provider} · CardBin {product.providerProductRef}
                    </p>
                </div>
                <StatusBadge
                    status={product.status === 'ACTIVE' ? 'SUCCESS' : 'NEUTRAL'}
                    label={`Platform ${product.status}`}
                />
            </CardHeader>
            <CardContent className="space-y-5">
                <dl className="grid gap-4 rounded-lg bg-muted/60 p-4 text-sm sm:grid-cols-3">
                    <div>
                        <dt className="text-muted-foreground">Card identity</dt>
                        <dd className="mt-1 font-medium">
                            {product.cardType} · {product.cardCurrency}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-muted-foreground">Minimum initial load</dt>
                        <dd className="mt-1 font-medium">{product.minimumInitialLoad} USD</dd>
                    </div>
                    <div>
                        <dt className="text-muted-foreground">Minimum reload</dt>
                        <dd className="mt-1 font-medium">{product.minimumReload} USD</dd>
                    </div>
                </dl>
                <form
                    className="grid gap-4 md:grid-cols-2 xl:grid-cols-5"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.put(`/admin/card-products/${product.id}`);
                    }}
                >
                    <FormField
                        id={`${product.id}-display`}
                        label="Customer display name"
                        error={form.errors.display_name}
                    >
                        <Input
                            id={`${product.id}-display`}
                            value={form.data.display_name}
                            onChange={(event) => form.setData('display_name', event.target.value)}
                        />
                    </FormField>
                    <FormField
                        id={`${product.id}-fee`}
                        label="Opening fee (USDT)"
                        error={form.errors.opening_fee}
                    >
                        <Input
                            id={`${product.id}-fee`}
                            inputMode="decimal"
                            value={form.data.opening_fee}
                            onChange={(event) => form.setData('opening_fee', event.target.value)}
                        />
                    </FormField>
                    <FormField
                        id={`${product.id}-limit`}
                        label="Max cards per user"
                        error={form.errors.max_cards_per_user}
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
                        label="Sort order"
                        error={form.errors.sort_order}
                    >
                        <Input
                            id={`${product.id}-sort`}
                            type="number"
                            min={0}
                            value={form.data.sort_order}
                            onChange={(event) =>
                                form.setData('sort_order', Number(event.target.value))
                            }
                        />
                    </FormField>
                    <FormField
                        id={`${product.id}-status`}
                        label="Tenant offering"
                        error={form.errors.status}
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
                                <SelectItem value="ACTIVE">Active</SelectItem>
                                <SelectItem value="INACTIVE">Inactive</SelectItem>
                            </SelectContent>
                        </Select>
                    </FormField>
                    <div className="md:col-span-2 xl:col-span-5 xl:text-right">
                        <Button
                            disabled={
                                form.processing ||
                                (product.status !== 'ACTIVE' && form.data.status === 'ACTIVE')
                            }
                        >
                            Save tenant offering
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}
