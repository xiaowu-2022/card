import { Head } from '@inertiajs/react';
import { CheckCircle2, CreditCard, LockKeyhole } from 'lucide-react';
import { MoneyDisplay } from '@/components/shared/MoneyDisplay';
import { UserEmptyState } from '@/components/user/UserEmptyState';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { UserLayout } from '@/layouts/UserLayout';

type Product = {
    id: string;
    name: string;
    cardType: string;
    cardCurrency: string;
    openingFee: string;
    minimumInitialLoad: string;
    minimumReload: string;
    minimumRequiredBalance: string;
    maxCardsPerUser: number;
    readyForSetup: boolean;
    guidance: string;
};

export default function Cards({ products }: { products: Product[] }) {
    return (
        <UserLayout>
            <Head title="Cards" />
            <div className="space-y-6">
                <UserPageHeader title="Cards" backHref="/dashboard" />
                <p className="max-w-xl text-sm leading-6 text-muted-foreground">
                    Explore the virtual card available from your provider. Card setup is coming
                    next—nothing is issued from this page.
                </p>
                {products.length === 0 ? (
                    <UserEmptyState
                        title="No card products available"
                        description="Your card program is not currently accepting new setup requests."
                    />
                ) : (
                    <div className="grid gap-5 xl:grid-cols-2">
                        {products.map((product) => (
                            <Card key={product.id} className="overflow-hidden border-0 shadow-sm">
                                <CardContent className="p-0">
                                    <div className="bg-slate-950 p-6 text-white sm:p-7">
                                        <div className="flex items-start justify-between gap-4">
                                            <div>
                                                <p className="text-xs font-semibold uppercase tracking-[0.18em] text-white/60">
                                                    {product.cardType}
                                                </p>
                                                <h2 className="mt-2 text-2xl font-semibold">
                                                    {product.name}
                                                </h2>
                                            </div>
                                            <CreditCard className="size-7 text-white/75" />
                                        </div>
                                        <p className="mt-10 text-sm text-white/70">
                                            Card balance in {product.cardCurrency}
                                        </p>
                                    </div>
                                    <div className="space-y-5 p-5 sm:p-6">
                                        <dl className="grid gap-4 sm:grid-cols-2">
                                            <div>
                                                <dt className="text-xs text-muted-foreground">
                                                    Opening fee
                                                </dt>
                                                <dd className="mt-1 font-semibold">
                                                    <MoneyDisplay
                                                        amount={product.openingFee}
                                                        asset="USDT"
                                                        compact
                                                    />
                                                </dd>
                                            </div>
                                            <div>
                                                <dt className="text-xs text-muted-foreground">
                                                    Minimum initial load
                                                </dt>
                                                <dd className="mt-1 font-semibold">
                                                    <MoneyDisplay
                                                        amount={product.minimumInitialLoad}
                                                        asset="USDT"
                                                        compact
                                                    />
                                                </dd>
                                            </div>
                                            <div>
                                                <dt className="text-xs text-muted-foreground">
                                                    Balance guidance
                                                </dt>
                                                <dd className="mt-1 font-semibold">
                                                    <MoneyDisplay
                                                        amount={product.minimumRequiredBalance}
                                                        asset="USDT"
                                                        compact
                                                    />
                                                </dd>
                                            </div>
                                            <div>
                                                <dt className="text-xs text-muted-foreground">
                                                    Product limit
                                                </dt>
                                                <dd className="mt-1 font-semibold">
                                                    Up to {product.maxCardsPerUser} cards
                                                </dd>
                                            </div>
                                        </dl>
                                        <div
                                            className={`flex gap-3 rounded-xl p-3 text-sm ${product.readyForSetup ? 'bg-emerald-50 text-emerald-900' : 'bg-muted text-muted-foreground'}`}
                                        >
                                            {product.readyForSetup ? (
                                                <CheckCircle2 className="mt-0.5 size-4 shrink-0" />
                                            ) : (
                                                <LockKeyhole className="mt-0.5 size-4 shrink-0" />
                                            )}
                                            <span>{product.guidance}</span>
                                        </div>
                                        <Button className="w-full" disabled>
                                            Get card · Coming next
                                        </Button>
                                        <p className="text-center text-xs text-muted-foreground">
                                            No charge is made and no card is created in this
                                            preview.
                                        </p>
                                    </div>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}
            </div>
        </UserLayout>
    );
}
