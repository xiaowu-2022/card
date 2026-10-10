import { useEffect, useRef, useState, type ComponentProps } from 'react';
import { router, usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types/global';
import { t } from '@/i18n/admin';
import { Dialog, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { DetailDrawerContent } from './DetailDrawer';
import { Button } from '@/components/ui/button';
import { OperationFeedback } from './OperationFeedback';
import { readEditorResponse } from './editor-response';
import UserManagement, { type PlatformUser } from './UserManagement';

export type UserInfo = {
    id: string;
    companyId: string;
    companyName: string;
    accountId: string;
    displayName?: string | null;
    email?: string | null;
    remark?: string | null;
    supportAgent?: boolean;
};
export function UserInfoCell({
    user,
    interactive = true,
    action = false,
}: {
    user: UserInfo;
    interactive?: boolean;
    action?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const trigger = useRef<HTMLButtonElement | null>(null);
    const canRead = usePage<SharedProps>().props.auth.admin?.permissions.includes('users.read');
    const content = (
        <>
            <span
                className="flex min-w-0 items-center gap-1 text-sm"
                title={`${user.companyName} · ${user.displayName || '—'}`}
            >
                <span className="max-w-20 truncate text-xs text-muted-foreground">
                    {user.companyName}
                </span>
                <span aria-hidden="true" className="text-muted-foreground">
                    ·
                </span>
                <span className="min-w-0 truncate font-semibold text-foreground">
                    {user.displayName || '—'}
                </span>
                {user.supportAgent && (
                    <span className="shrink-0 text-xs text-emerald-700">{t('Support agent')}</span>
                )}
            </span>
            {user.remark && (
                <span className="block truncate text-xs text-emerald-700" title={user.remark}>
                    {t('Customer remark')}: {user.remark}
                </span>
            )}
            <span
                className="block truncate text-xs text-muted-foreground"
                title={user.email ?? undefined}
            >
                {user.email || '—'}
            </span>
            <span
                className="block truncate text-xs tabular-nums text-muted-foreground"
                title={user.accountId}
            >
                {user.accountId || '—'}
            </span>
        </>
    );
    return (
        <>
            {action && canRead ? (
                <Button ref={trigger} variant="secondary" size="sm" onClick={() => setOpen(true)}>
                    {t('Details')}
                </Button>
            ) : interactive && canRead && user.id ? (
                <button
                    ref={trigger}
                    type="button"
                    className="block w-44 max-w-full space-y-1.5 rounded-md text-left hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                    aria-label={`${t('User details')} · ${user.displayName || user.email || user.accountId}`}
                    onClick={() => setOpen(true)}
                >
                    {content}
                </button>
            ) : (
                <div className="w-44 max-w-full space-y-1.5">{content}</div>
            )}
            {open && <UserDetails user={user} onClose={() => setOpen(false)} trigger={trigger} />}
        </>
    );
}

type Detail = Omit<ComponentProps<typeof UserManagement>, 'users' | 'companies' | 'filters'> & {
    user: PlatformUser;
};
function UserDetails({
    user,
    onClose,
    trigger,
}: {
    user: UserInfo;
    onClose: () => void;
    trigger: React.RefObject<HTMLButtonElement | null>;
}) {
    const [data, setData] = useState<Detail | null>(null);
    const [error, setError] = useState(false);
    const [revision, setRevision] = useState(0);
    useEffect(() => router.on('finish', () => setRevision((n) => n + 1)), []);
    useEffect(() => {
        const controller = new AbortController();
        setError(false);
        void fetch(
            `/platform/tenants/${encodeURIComponent(user.companyId)}/users/${encodeURIComponent(user.id)}/details`,
            {
                credentials: 'same-origin',
                cache: 'no-store',
                signal: controller.signal,
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            },
        )
            .then(async (response) => {
                const result = await readEditorResponse(response);
                if (!response.ok || !result.user) throw new Error();
                if (!controller.signal.aborted) setData(result as Detail);
            })
            .catch(() => {
                if (!controller.signal.aborted) setError(true);
            });
        return () => controller.abort();
    }, [user.companyId, user.id, revision]);
    return (
        <Dialog
            open
            onOpenChange={(value) => {
                if (!value) onClose();
            }}
        >
            <DetailDrawerContent
                closeLabel={t('Close')}
                className="p-0 sm:w-[min(92vw,48rem)]"
                onCloseAutoFocus={(event) => {
                    if (trigger.current?.isConnected) {
                        event.preventDefault();
                        trigger.current.focus();
                    }
                }}
            >
                <DialogHeader className="border-b p-5 pr-12">
                    <DialogTitle>
                        {(data?.user ?? user).displayName ||
                            (data?.user ?? user).email ||
                            user.accountId}
                    </DialogTitle>
                    <DialogDescription>
                        {user.companyName} · {user.accountId}
                    </DialogDescription>
                </DialogHeader>
                <div className="flex min-h-0 flex-1 flex-col">
                    {error ? (
                        <>
                            <OperationFeedback role="alert">
                                {t('Unable to load. Please retry.')}
                            </OperationFeedback>
                            <Button onClick={() => setRevision((n) => n + 1)}>{t('Retry')}</Button>
                        </>
                    ) : data ? (
                        <>
                            <UserManagement
                                {...data}
                                detail
                                companies={[]}
                                filters={{}}
                                users={{
                                    data: [data.user],
                                    total: 1,
                                    current_page: 1,
                                    last_page: 1,
                                    prev_page_url: null,
                                    next_page_url: null,
                                }}
                            />
                        </>
                    ) : (
                        <p role="status">{t('Loading…')}</p>
                    )}
                </div>
            </DetailDrawerContent>
        </Dialog>
    );
}

/** Existing limited list identities remain visible for readers without users.read. */
export function RecordUserCell({
    row,
}: {
    row: {
        userInfo?: UserInfo | null;
        userId?: string;
        companyId?: string;
        tenantId?: string;
        tenant_id?: string;
        companyName?: string;
        company_name?: string;
        accountId?: string;
        account_id?: string;
        display_name?: string | null;
        email?: string | null;
        userEmail?: string | null;
        user?: { displayName: string | null; contact: string | null };
    };
}) {
    return (
        <UserInfoCell
            user={
                row.userInfo ?? {
                    id: row.userId ?? '',
                    companyId: row.companyId ?? row.tenantId ?? row.tenant_id ?? '',
                    companyName: row.companyName ?? row.company_name ?? '',
                    accountId: row.accountId ?? row.account_id ?? row.user?.displayName ?? '',
                    displayName: row.display_name,
                    email: row.userEmail ?? row.email ?? row.user?.contact,
                }
            }
        />
    );
}
