import { t, useClientTranslation, dateTime } from '@/i18n';
import { Link } from '@inertiajs/react';
import { ArrowDownLeft, ArrowUpRight, CircleDollarSign } from 'lucide-react';
import type { MoneyAmount } from '@/types/global';
import { MoneyDisplay } from '@/components/user/UserMoney';
import { UserEmptyState } from './UserEmptyState';

export type UserActivityItem = {
    id: string;
    reference?: string | null;
    state?: string;
    steps?: { id: string; title: string; amount: string; postedAt: string }[];
    title: string;
    postedAt: string;
    amount?: MoneyAmount;
    asset: string;
    direction?: 'CREDIT' | 'DEBIT' | 'NEUTRAL';
    href?: string;
};

export function UserActivityRow({ item }: { item: UserActivityItem }) {
    useClientTranslation();
    const Icon =
        item.direction === 'CREDIT'
            ? ArrowDownLeft
            : item.direction === 'DEBIT'
              ? ArrowUpRight
              : CircleDollarSign;
    return (
        <div>
            <div className="user-activity-row flex min-w-0 items-center gap-3 px-1 py-4">
                <span className="grid size-10 shrink-0 place-items-center rounded-full bg-muted">
                    <Icon className="size-5" aria-hidden="true" />
                </span>
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium">
                        {item.href ? (
                            <Link href={item.href} className="underline underline-offset-4">
                                {t(item.title)}
                            </Link>
                        ) : (
                            t(item.title)
                        )}
                    </p>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {dateTime(item.postedAt)}
                    </p>
                </div>
                <div className="max-w-[48%] break-all text-right text-sm font-semibold">
                    {item.state && (
                        <p className="mb-1 whitespace-nowrap text-xs font-normal text-muted-foreground">
                            {t(item.state)}
                        </p>
                    )}
                    {item.amount ? (
                        <MoneyDisplay amount={item.amount} asset={item.asset} compact />
                    ) : (
                        <span className="text-xs font-medium text-muted-foreground">
                            {t('Completed')}
                        </span>
                    )}
                </div>
            </div>
            {item.steps && item.steps.length > 0 && (
                <details className="pb-3 pl-14 text-xs text-muted-foreground">
                    <summary className="cursor-pointer py-2">{t('Activity details')}</summary>
                    {item.reference && (
                        <p className="break-all py-1">
                            {t('Reference')}: {item.reference}
                        </p>
                    )}
                    <p className="py-1">{t('Available balance change')}</p>
                    {item.steps.map((step) => (
                        <div
                            key={step.id}
                            className="flex flex-wrap justify-between gap-2 border-t py-2"
                        >
                            <div>
                                {t(step.title)}
                                <p>{dateTime(step.postedAt)}</p>
                            </div>
                            <MoneyDisplay amount={step.amount} asset={item.asset} />
                        </div>
                    ))}
                </details>
            )}
        </div>
    );
}

export function UserActivityList({ items }: { items: UserActivityItem[] }) {
    useClientTranslation();
    if (items.length === 0) {
        return (
            <UserEmptyState
                title={t('No activity yet')}
                description={t('Completed activity will appear here.')}
            />
        );
    }
    return (
        <div className="divide-y">
            {items.map((item) => (
                <UserActivityRow key={item.id} item={item} />
            ))}
        </div>
    );
}
