import { t, useClientTranslation } from '@/i18n';
import { Head, Link } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';
import { MoneyDisplay } from '@/components/user/UserMoney';
import { Button } from '@/components/ui/button';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { UserLayout } from '@/layouts/UserLayout';
import type { MoneyAmount } from '@/types/global';

type Receipt = { ledgerEntryId: string; amount: MoneyAmount; asset: string };

export default function SecurityDepositSuccess({ receipt }: { receipt: Receipt }) {
    useClientTranslation();
    return (
        <UserLayout>
            <Head title={t('Security deposit complete')} />
            <UserPageHeader title={t('Security deposit complete')} backHref="/dashboard" />
            <section className="mx-auto max-w-lg py-10 text-center sm:py-16">
                <span className="mx-auto grid size-14 place-items-center rounded-full bg-emerald-50 text-success">
                    <CheckCircle2 className="size-7" />
                </span>
                <p className="mt-3 text-muted-foreground">
                    <span className="block">{t('Already deposited')}</span>
                    <MoneyDisplay amount={receipt.amount} asset={receipt.asset} compact />
                </p>
                <Button asChild className="mt-8 w-full sm:w-auto">
                    <Link href="/dashboard">{t('Back to home')}</Link>
                </Button>
            </section>
        </UserLayout>
    );
}
