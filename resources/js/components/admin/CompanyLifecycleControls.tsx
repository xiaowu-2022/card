import { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { t } from '@/i18n/admin';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/shared/StatusBadge';
import {
    AlertDialog,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { SharedProps } from '@/types/global';

export function CompanyLifecycleControls({
    company,
}: {
    company: { id: string; name: string; status?: string };
}) {
    const canManage =
        usePage<SharedProps>().props.auth.admin?.permissions.includes('tenant.manage');
    const [action, setAction] = useState<'suspend' | 'reactivate' | null>(null);
    const [processing, setProcessing] = useState(false);
    if (!company.status) return null;
    return (
        <>
            <StatusBadge
                status={
                    company.status === 'ACTIVE'
                        ? 'SUCCESS'
                        : company.status === 'SUSPENDED'
                          ? 'WARNING'
                          : 'NEUTRAL'
                }
                label={t(company.status)}
            />
            {canManage && ['DRAFT', 'ACTIVE', 'SUSPENDED'].includes(company.status) && (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button variant="secondary" size="sm">
                            {t('Company status')}
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        {company.status === 'DRAFT' && (
                            <DropdownMenuItem asChild>
                                <Link
                                    href={`/platform/tenants/${company.id}/configuration/onboarding`}
                                >
                                    {t('Onboarding')}
                                </Link>
                            </DropdownMenuItem>
                        )}
                        {company.status === 'ACTIVE' && (
                            <DropdownMenuItem onSelect={() => setAction('suspend')}>
                                {t('Suspend tenant')}
                            </DropdownMenuItem>
                        )}
                        {company.status === 'SUSPENDED' && (
                            <DropdownMenuItem onSelect={() => setAction('reactivate')}>
                                {t('Reactivate tenant')}
                            </DropdownMenuItem>
                        )}
                    </DropdownMenuContent>
                </DropdownMenu>
            )}
            <AlertDialog
                open={action !== null}
                onOpenChange={(open) => {
                    if (!open && !processing) setAction(null);
                }}
            >
                <AlertDialogContent>
                    <AlertDialogTitle>
                        {t(action === 'suspend' ? 'Suspend tenant' : 'Reactivate tenant')} ·{' '}
                        {company.name}
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        {action === 'suspend'
                            ? t(
                                  'Current status: ACTIVE. Registration and business access will be blocked. Historical users, balances, cards, and Ledger records are never deleted or modified by this lifecycle action.',
                              )
                            : t(
                                  'Reactivate this company to restore access under its existing configuration.',
                              )}
                    </AlertDialogDescription>
                    <div className="mt-5 flex justify-end gap-2">
                        <Button
                            variant="secondary"
                            disabled={processing}
                            onClick={() => setAction(null)}
                        >
                            {t('Cancel')}
                        </Button>
                        <Button
                            variant={action === 'suspend' ? 'destructive' : 'default'}
                            disabled={processing}
                            onClick={() => {
                                if (!action || processing) return;
                                setProcessing(true);
                                router.post(
                                    `/platform/tenants/${company.id}/${action}`,
                                    {},
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => setAction(null),
                                        onFinish: () => setProcessing(false),
                                    },
                                );
                            }}
                        >
                            {t('Confirm')}
                        </Button>
                    </div>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
