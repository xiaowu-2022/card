import { Inbox } from 'lucide-react';

export function UserEmptyState({ title, description }: { title: string; description?: string }) {
    return (
        <div className="rounded-[var(--user-radius-md)] border border-dashed bg-surface px-5 py-9 text-center">
            <span className="mx-auto grid size-11 place-items-center rounded-full bg-muted text-muted-foreground">
                <Inbox className="size-5" aria-hidden="true" />
            </span>
            <p className="mt-3 text-sm font-semibold">{title}</p>
            {description ? (
                <p className="mt-1 text-sm text-muted-foreground">{description}</p>
            ) : null}
        </div>
    );
}
