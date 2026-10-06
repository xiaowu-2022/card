import { Link, usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types/global';
import { t } from '@/i18n/admin';
import { cn } from '@/lib/utils';
export function PlatformSupportTabs({
    agents = false,
    bot = false,
    hours = false,
    replies = false,
}: {
    agents?: boolean;
    bot?: boolean;
    hours?: boolean;
    replies?: boolean;
}) {
    const permissions = usePage<SharedProps>().props.auth.admin?.permissions ?? [];
    return (
        <nav aria-label={t('Customer support')} className="flex gap-5 border-b">
            <Link
                href="/platform/support"
                className={cn(
                    'border-b-2 py-3',
                    agents || bot || hours || replies
                        ? 'border-transparent'
                        : 'border-primary text-primary',
                )}
            >
                {t('Support conversations')}
            </Link>
            {permissions.includes('support.agents.manage') && (
                <Link
                    href="/platform/support/agents"
                    className={cn(
                        'border-b-2 py-3',
                        agents ? 'border-primary text-primary' : 'border-transparent',
                    )}
                >
                    {t('Support staff')}
                </Link>
            )}
            {permissions.includes('support.bot.manage') && (
                <Link
                    href="/platform/support/bot"
                    className={cn(
                        'border-b-2 py-3',
                        bot ? 'border-primary text-primary' : 'border-transparent',
                    )}
                >
                    {t('Bot and FAQ')}
                </Link>
            )}
            {permissions.includes('support.hours.manage') && (
                <Link
                    href="/platform/support/hours"
                    className={cn(
                        'border-b-2 py-3',
                        hours ? 'border-primary text-primary' : 'border-transparent',
                    )}
                >
                    {t('Service hours')}
                </Link>
            )}
            {permissions.includes('support.replies.manage') && (
                <Link
                    href="/platform/support/replies"
                    className={cn(
                        'border-b-2 py-3',
                        replies ? 'border-primary text-primary' : 'border-transparent',
                    )}
                >
                    {t('Quick replies')}
                </Link>
            )}
        </nav>
    );
}
