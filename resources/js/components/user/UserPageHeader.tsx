import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import type { ReactNode } from 'react';

export function UserPageHeader({
    title,
    description,
    backHref,
    action,
}: {
    title: string;
    description?: string;
    backHref?: string;
    action?: ReactNode;
}) {
    return (
        <div className="flex min-w-0 items-start justify-between gap-4">
            <div className="min-w-0">
                {backHref ? (
                    <Link
                        href={backHref}
                        className="mb-3 inline-flex min-h-11 items-center gap-2 text-sm font-semibold text-muted-foreground hover:text-foreground"
                        aria-label={`Back from ${title}`}
                    >
                        <ArrowLeft className="size-4" />
                        Back
                    </Link>
                ) : null}
                <h1 className="break-words text-2xl font-semibold tracking-tight sm:text-3xl">
                    {title}
                </h1>
                {description ? (
                    <p className="mt-2 max-w-2xl text-sm leading-6 text-muted-foreground sm:text-base">
                        {description}
                    </p>
                ) : null}
            </div>
            {action ? <div className="shrink-0">{action}</div> : null}
        </div>
    );
}
