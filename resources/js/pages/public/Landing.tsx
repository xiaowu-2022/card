import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight, CheckCircle2, LockKeyhole, ShieldCheck, WalletCards } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { PublicLayout } from '@/layouts/PublicLayout';
import type { SharedProps } from '@/types/global';

export default function Landing() {
    const { tenant } = usePage<SharedProps>().props;
    return (
        <PublicLayout>
            <Head title="Welcome" />
            <section className="mx-auto grid max-w-7xl gap-12 px-4 py-16 sm:px-6 sm:py-24 lg:grid-cols-[1.05fr_.95fr] lg:items-center lg:px-8 lg:py-28">
                <div>
                    <p className="text-sm font-semibold text-primary">
                        Secure virtual card workspace
                    </p>
                    <h1 className="mt-4 max-w-3xl text-4xl font-semibold tracking-tight text-slate-950 sm:text-5xl lg:text-6xl">
                        Spend online with clarity and control.
                    </h1>
                    <p className="mt-6 max-w-xl text-lg leading-8 text-muted-foreground">
                        A responsive account experience for managing balances and virtual cards.
                        This Phase 0 preview contains demo data only.
                    </p>
                    <div className="mt-8 flex flex-col gap-3 sm:flex-row">
                        <Button asChild size="lg">
                            <Link href="/demo">
                                Open demo account <ArrowRight className="size-4" />
                            </Link>
                        </Button>
                        <Button asChild size="lg" variant="secondary">
                            <Link href="/login">Log in</Link>
                        </Button>
                    </div>
                    <div className="mt-9 flex flex-wrap gap-x-6 gap-y-3 text-sm text-muted-foreground">
                        {['Tenant-aware', 'Provider-safe', 'Responsive web'].map((item) => (
                            <span key={item} className="flex items-center gap-2">
                                <CheckCircle2 className="size-4 text-success" />
                                {item}
                            </span>
                        ))}
                    </div>
                </div>
                <Card className="overflow-hidden border-slate-200 shadow-xl shadow-slate-200/60">
                    <div className="border-b bg-slate-950 p-6 text-white">
                        <div className="flex items-center justify-between">
                            <span className="text-sm font-semibold">
                                {tenant?.branding.brandName ?? 'Aperture Cards'}
                            </span>
                            <span className="rounded-full border border-white/20 px-2 py-1 text-xs">
                                MOCK
                            </span>
                        </div>
                        <div className="mt-14 text-3xl font-semibold tracking-[.16em]">
                            •••• 1234
                        </div>
                        <div className="mt-8 flex justify-between text-sm text-slate-300">
                            <span>TEST CARD</span>
                            <span>08/29</span>
                        </div>
                    </div>
                    <CardContent className="grid gap-4 p-6 sm:grid-cols-3 lg:grid-cols-1 xl:grid-cols-3">
                        {[
                            { icon: WalletCards, label: 'Balance', value: '$12,840.25' },
                            { icon: ShieldCheck, label: 'Status', value: 'Protected' },
                            { icon: LockKeyhole, label: 'Card', value: 'Frozen' },
                        ].map((item) => (
                            <div key={item.label}>
                                <item.icon className="size-5 text-primary" />
                                <p className="mt-3 text-xs text-muted-foreground">{item.label}</p>
                                <p className="mt-1 font-semibold">{item.value}</p>
                            </div>
                        ))}
                    </CardContent>
                </Card>
            </section>
        </PublicLayout>
    );
}
