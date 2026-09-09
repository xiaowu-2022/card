import type { LucideIcon } from 'lucide-react';
import { Link } from '@inertiajs/react';

export type UserQuickAction = { label: string; href: string; icon: LucideIcon };

export function UserQuickActions({ actions }: { actions: UserQuickAction[] }) {
    if (actions.length === 0) return null;
    return (
        <nav className="grid grid-cols-2 gap-3 sm:grid-cols-4" aria-label="Quick actions">
            {actions.map(({ label, href, icon: Icon }) => (
                <Link
                    key={label}
                    href={href}
                    className="flex min-h-20 flex-col items-center justify-center gap-2 rounded-[var(--user-radius-md)] border bg-surface px-3 text-center text-sm font-medium hover:bg-muted"
                >
                    <span className="grid size-10 place-items-center rounded-full bg-[var(--user-primary-soft)] text-primary">
                        <Icon className="size-5" />
                    </span>
                    {label}
                </Link>
            ))}
        </nav>
    );
}
