import { useClientTranslation } from '@/i18n';
import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export function UserListRow({
    icon: Icon,
    title,
    description,
    value,
    href,
    destructive = false,
    onClick,
}: {
    icon?: LucideIcon;
    title: string;
    description?: string;
    value?: ReactNode;
    href?: string;
    destructive?: boolean;
    onClick?: () => void;
}) {
    useClientTranslation();
    const classes = cn(
        'flex min-h-14 w-full min-w-0 items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-muted/70',
        destructive && 'text-danger',
    );
    const content = (
        <>
            {Icon ? (
                <span className="grid size-9 shrink-0 place-items-center rounded-full bg-muted">
                    <Icon className="size-[1.125rem]" aria-hidden="true" />
                </span>
            ) : null}
            <span className="min-w-0 flex-1">
                <span className="block break-words text-sm font-medium">{title}</span>
                {description ? (
                    <span className="mt-0.5 block break-words text-xs text-muted-foreground">
                        {description}
                    </span>
                ) : null}
            </span>
            {value ? <span className="shrink-0 text-sm text-muted-foreground">{value}</span> : null}
            {href ? <ChevronRight className="size-4 shrink-0 text-muted-foreground" /> : null}
        </>
    );

    if (href)
        return (
            <Link href={href} className={classes}>
                {content}
            </Link>
        );
    if (onClick)
        return (
            <button type="button" onClick={onClick} className={classes}>
                {content}
            </button>
        );
    return <div className={classes}>{content}</div>;
}
