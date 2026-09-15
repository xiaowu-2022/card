import { t, useClientTranslation } from '@/i18n';
import type { LucideIcon } from 'lucide-react';
import { Link } from '@inertiajs/react';

export type UserQuickAction = {
    label: string;
    href: string | null;
    icon: LucideIcon;
    unavailableLabel?: string;
    unavailable?: boolean;
};

export function UserQuickActions({ actions }: { actions: UserQuickAction[] }) {
    useClientTranslation();
    if (actions.length === 0) return null;
    return (
        <nav className="user-quick-actions" aria-label={t('Quick actions')}>
            {actions.map(({ label, href, icon: Icon, unavailableLabel, unavailable }) => {
                const content = (
                    <>
                        <span className="user-quick-action-circle">
                            <Icon strokeWidth={1.9} aria-hidden="true" />
                        </span>
                        <span className="user-quick-action-label">{label}</span>
                        {(!href || unavailable) && unavailableLabel ? (
                            <span className="user-quick-action-note">{unavailableLabel}</span>
                        ) : null}
                    </>
                );
                const className = 'user-quick-action';
                return href ? (
                    <Link key={label} href={href} className={className}>
                        {content}
                    </Link>
                ) : (
                    <span key={label} className={className} aria-disabled="true">
                        {content}
                    </span>
                );
            })}
        </nav>
    );
}
