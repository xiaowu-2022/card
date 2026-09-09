import { Inbox } from 'lucide-react';
import type { ReactNode } from 'react';

export function EmptyState({
    title,
    description,
    primaryAction,
    secondaryAction,
}: {
    title: string;
    description: string;
    primaryAction?: ReactNode;
    secondaryAction?: ReactNode;
}) {
    return (
        <div className="rounded-xl border border-dashed bg-surface px-6 py-12 text-center">
            <Inbox className="mx-auto size-8 text-muted-foreground" />
            <h3 className="mt-4 font-semibold">{title}</h3>
            <p className="mx-auto mt-1 max-w-md text-sm text-muted-foreground">{description}</p>
            <div className="mt-5 flex justify-center gap-2">
                {primaryAction}
                {secondaryAction}
            </div>
        </div>
    );
}
