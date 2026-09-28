import { Link, usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types/global';
import { t } from '@/i18n/admin';
import { cn } from '@/lib/utils';
export function PlatformSupportTabs({ agents = false }: { agents?: boolean }) {
    const permissions = usePage<SharedProps>().props.auth.admin?.permissions ?? [];
    return (
        <nav aria-label={t('Customer support')} className="flex gap-5 border-b">
            <Link
                href="/platform/support"
                className={cn(
                    'border-b-2 py-3',
                    agents ? 'border-transparent' : 'border-primary text-primary',
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
        </nav>
    );
}
