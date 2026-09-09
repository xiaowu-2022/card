import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { userNavigation } from './user-navigation';

export function UserBottomNavigation({ currentUrl }: { currentUrl: string }) {
    return (
        <nav
            className="fixed inset-x-0 bottom-0 z-40 grid grid-cols-4 border-t bg-surface px-1 pt-1.5 pb-[max(.5rem,env(safe-area-inset-bottom))] md:hidden"
            aria-label="Primary navigation"
        >
            {userNavigation.map(({ label, href, icon: Icon }) => {
                const active = href !== null && currentUrl.startsWith(href);
                const classes = cn(
                    'flex min-h-14 flex-col items-center justify-center gap-1 rounded-lg px-1 text-[.6875rem] font-medium',
                    active ? 'text-primary' : 'text-muted-foreground',
                    href === null && 'cursor-not-allowed opacity-50',
                );
                const content = (
                    <>
                        <Icon className="size-5" aria-hidden="true" />
                        <span>{label}</span>
                    </>
                );
                return href ? (
                    <Link
                        key={label}
                        href={href}
                        className={classes}
                        aria-current={active ? 'page' : undefined}
                        aria-label={label}
                    >
                        {content}
                    </Link>
                ) : (
                    <span
                        key={label}
                        className={classes}
                        aria-disabled="true"
                        aria-label={`${label}, coming soon`}
                    >
                        {content}
                    </span>
                );
            })}
        </nav>
    );
}
