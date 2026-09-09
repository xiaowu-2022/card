import { ArrowDownLeft, ArrowUpRight, CircleDollarSign } from 'lucide-react';
import type { MoneyAmount } from '@/types/global';
import { MoneyDisplay } from '@/components/shared/MoneyDisplay';
import { UserEmptyState } from './UserEmptyState';

export type UserActivityItem = {
    id: string;
    title: string;
    postedAt: string;
    amount?: MoneyAmount;
    asset: string;
    direction?: 'CREDIT' | 'DEBIT' | 'NEUTRAL';
};

export function UserActivityRow({ item }: { item: UserActivityItem }) {
    const Icon =
        item.direction === 'CREDIT'
            ? ArrowDownLeft
            : item.direction === 'DEBIT'
              ? ArrowUpRight
              : CircleDollarSign;
    return (
        <div className="flex min-w-0 items-center gap-3 px-1 py-4">
            <span className="grid size-10 shrink-0 place-items-center rounded-full bg-muted">
                <Icon className="size-5" aria-hidden="true" />
            </span>
            <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium">{item.title}</p>
                <p className="mt-0.5 text-xs text-muted-foreground">
                    {new Date(item.postedAt).toLocaleString()}
                </p>
            </div>
            <div className="shrink-0 text-right text-sm font-semibold">
                {item.amount ? (
                    <MoneyDisplay amount={item.amount} asset={item.asset} compact />
                ) : (
                    item.asset
                )}
            </div>
        </div>
    );
}

export function UserActivityList({ items }: { items: UserActivityItem[] }) {
    if (items.length === 0) {
        return (
            <UserEmptyState
                title="No activity yet"
                description="Completed activity will appear here."
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
