import { useEditor } from '@/components/admin/editor-context';
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
    const editor = useEditor();
    if (editor) return null;
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
            {permissions.includes('support.read') &&
                (permissions.includes('support.hours.manage') ||
                    permissions.includes('support.replies.manage')) && (
                    <Link href="/platform/tenants" className="border-b-2 border-transparent py-3">
                        {t('Company configuration')}
                    </Link>
                )}
        </nav>
    );
}
