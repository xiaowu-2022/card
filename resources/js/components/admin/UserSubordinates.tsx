import { useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types/global';
import { UserInfoCell, type UserInfo } from './UserInfoCell';
import { AdminPagination } from './AdminPagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from '@/components/ui/dialog';
import { t } from '@/i18n/admin';

type Member = UserInfo & { descendantCount: number };
type Page = { data: Member[]; current_page: number; last_page: number; total: number };

export function UserSubordinates({ user, count }: { user: UserInfo; count: number }) {
    const allowed = usePage<SharedProps>().props.auth.admin?.permissions.includes('users.read');
    const [open, setOpen] = useState(false);
    return allowed && user.id ? (
        <>
            <button
                type="button"
                className="shrink-0 text-sm text-foreground hover:underline"
                onClick={() => setOpen(true)}
                aria-label={t('Subordinates ({{count}})', { count })}
            >
                ({count})
            </button>
            {open && <TeamDialog user={user} onClose={() => setOpen(false)} />}
        </>
    ) : null;
}

function TeamDialog({ user, onClose }: { user: UserInfo; onClose: () => void }) {
    const [path, setPath] = useState<UserInfo[]>([user]);
    const current = path[path.length - 1]!;
    const [scope, setScope] = useState('all');
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const [data, setData] = useState<Page | null>(null);
    const [failed, setFailed] = useState(false);
    const [retry, setRetry] = useState(0);
    useEffect(() => {
        const controller = new AbortController();
        setData(null);
        setFailed(false);
        const timer = setTimeout(() => {
            const params = new URLSearchParams({ scope, search, page: String(page) });
            void fetch(
                `/platform/tenants/${encodeURIComponent(current.companyId)}/users/${encodeURIComponent(current.id)}/subordinates?${params}`,
                {
                    signal: controller.signal,
                    cache: 'no-store',
                    headers: { Accept: 'application/json' },
                },
            )
                .then(async (response) => {
                    if (!response.ok) throw new Error();
                    const result = (await response.json()) as Page;
                    if (!controller.signal.aborted) setData(result);
                })
                .catch(() => {
                    if (!controller.signal.aborted) setFailed(true);
                });
        }, 200);
        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [current.companyId, current.id, scope, search, page, retry]);
    const reset = () => {
        setSearch('');
        setPage(1);
        setScope('all');
    };
    return (
        <Dialog
            open
            onOpenChange={(value) => {
                if (!value) onClose();
            }}
        >
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        {current.displayName || current.accountId} ·{' '}
                        {t('Subordinates ({{count}})', { count: data?.total ?? '…' })}
                    </DialogTitle>
                    <DialogDescription>
                        {current.companyName} · {current.accountId}
                    </DialogDescription>
                </DialogHeader>
                {path.length > 1 && (
                    <Button
                        variant="secondary"
                        onClick={() => {
                            setPath(path.slice(0, -1));
                            reset();
                        }}
                    >
                        {t('Back')}
                    </Button>
                )}
                <div className="flex gap-2">
                    <select
                        aria-label={t('Team scope')}
                        className="rounded-md border px-3"
                        value={scope}
                        onChange={(event) => {
                            setScope(event.target.value);
                            setPage(1);
                        }}
                    >
                        <option value="all">{t('All')}</option>
                        <option value="direct">{t('Direct referrals')}</option>
                    </select>
                    <Input
                        aria-label={t('Search name, account ID or email')}
                        placeholder={t('Search name, account ID or email')}
                        value={search}
                        onChange={(event) => {
                            setSearch(event.target.value);
                            setPage(1);
                        }}
                    />
                </div>
                {failed ? (
                    <div role="alert">
                        {t('Unable to load. Please retry.')}{' '}
                        <Button onClick={() => setRetry(retry + 1)}>{t('Retry')}</Button>
                    </div>
                ) : !data ? (
                    <p role="status">{t('Loading…')}</p>
                ) : (
                    <>
                        {data.data.length ? (
                            data.data.map((member) => (
                                <div
                                    key={member.id}
                                    className="flex items-center justify-between gap-3 border-b py-3"
                                >
                                    <UserInfoCell user={member} />
                                    <Button
                                        variant="secondary"
                                        onClick={() => {
                                            setPath([...path, member]);
                                            reset();
                                        }}
                                    >
                                        {t('Subordinates ({{count}})', {
                                            count: member.descendantCount,
                                        })}
                                    </Button>
                                </div>
                            ))
                        ) : (
                            <p>{t('No records')}</p>
                        )}
                        <AdminPagination
                            page={data.current_page}
                            pages={data.last_page}
                            total={data.total}
                            onPage={setPage}
                        />
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}
