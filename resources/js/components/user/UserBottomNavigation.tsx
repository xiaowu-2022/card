import { t, useClientTranslation } from '@/i18n';
import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { userNavigation } from './user-navigation';

export function UserBottomNavigation({ currentUrl }: { currentUrl: string }) {
    useClientTranslation();
    return (
        <nav
            className="user-bottom-navigation fixed inset-x-0 bottom-0 z-40 mx-auto grid grid-cols-3 bg-surface"
            aria-label={t('Primary navigation')}
        >
            {userNavigation.map(({ label, href, icon: Icon }) => {
                const active =
                    currentUrl.startsWith(href) ||
                    (href === '/dashboard' &&
                        (currentUrl.startsWith('/wallet') ||
                            currentUrl.startsWith('/security-deposit') ||
                            currentUrl.startsWith('/demo/wallet') ||
                            currentUrl === '/demo')) ||
                    (href === '/cards' && currentUrl.startsWith('/demo/cards')) ||
                    (href === '/account' && currentUrl.startsWith('/kyc'));
                const classes = cn(
                    'user-bottom-navigation-item',
                    active ? 'text-primary' : 'text-muted-foreground',
                    href === null && 'cursor-not-allowed opacity-50',
                );
                const content = (
                    <>
                        <span className="user-bottom-navigation-icon">
                            <Icon strokeWidth={2.5} aria-hidden="true" />
                        </span>
                        <span>{t(label)}</span>
                    </>
                );
                return href ? (
                    <Link
                        key={label}
                        href={href}
                        className={classes}
                        aria-current={active ? 'page' : undefined}
                        aria-label={t(label)}
                    >
                        {content}
                    </Link>
                ) : (
                    <span
                        key={label}
                        className={classes}
                        aria-disabled="true"
                        aria-label={t('{{value1}}, coming soon', { value1: label })}
                    >
                        {content}
                    </span>
                );
            })}
        </nav>
    );
}
