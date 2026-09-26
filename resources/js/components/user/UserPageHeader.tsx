import { MessageBell } from './MessageBell';
import { userNavigation } from './user-navigation';
import type { SharedProps } from '@/types/global';
import { t, useClientTranslation } from '@/i18n';
import { Link, usePage } from '@inertiajs/react';
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
    useClientTranslation();
    const { props, url } = usePage<SharedProps>();
    const path = url.split(/[?#]/)[0].replace(/\/+$/, '') || '/';
    const showBell = !!props.auth.user && !userNavigation.some((item) => item.href === path);
    return (
        <div
            className={`user-page-header flex min-w-0 items-start justify-between gap-4 ${showBell ? 'user-page-header-with-bell' : ''}`}
        >
            <div className="min-w-0">
                {backHref ? (
                    <Link
                        href={backHref}
                        className="inline-flex size-11 shrink-0 items-center justify-center rounded-full text-muted-foreground hover:bg-muted hover:text-foreground"
                        aria-label={t('Back from {{value1}}', { value1: title })}
                    >
                        <ArrowLeft className="size-5" aria-hidden="true" />
                    </Link>
                ) : null}
                <h1 className="min-w-0 flex-1 break-words text-2xl font-semibold tracking-tight sm:text-3xl">
                    {title}
                </h1>
                {showBell && (
                    <span className="user-page-header-bell">
                        <MessageBell />
                    </span>
                )}
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
