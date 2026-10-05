import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import { SupportThread, type SupportChat } from '@/components/support/SupportThread';
import { SupportProfile } from '@/components/support/SupportProfile';
import { PlatformSupportTabs } from '@/components/support/PlatformSupportTabs';
import { useSupportPolling } from '@/components/support/useSupportPolling';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from '@/components/ui/dialog';
import { t, dateTime, useAdminTranslation } from '@/i18n/admin';
import type { SharedProps } from '@/types/global';
import type { AccountPage } from '@/components/shared/PlatformAccountTable';
import '../../../css/support.css';

type Row = {
    id: string;
    tenantId: string;
    userId: string;
    company: string;
    accountId: string;
    email: string;
    awaitingReply: boolean;
    updatedAt: string;
};
type Chat = SupportChat & { tenantId: string; userId: string; company: string; email: string };
type Props = {
    inbox: AccountPage<Row>;
    chat: Chat | null;
    companies: { id: string; name: string }[];
    filters: { company?: string; search?: string; status?: string };
    supportName: string | null;
};
const chatUrl = (tenant: string, user: string) =>
    `/platform/tenants/${tenant}/support/users/${user}`;
export default function Support({ inbox, chat, companies, filters, supportName }: Props) {
    useAdminTranslation();
    const permissions = usePage<SharedProps>().props.auth.admin?.permissions ?? [];
    const canSend = permissions.includes('support.send');
    const disconnected = useSupportPolling('inbox', !chat);
    const [query, setQuery] = useState(filters);
    const [start, setStart] = useState(false);
    return (
        <PlatformLayout
            title={t('Customer support')}
            actions={
                canSend && (
                    <div className="flex gap-3">
                        <SupportProfile
                            key={supportName}
                            name={supportName}
                            url="/platform/support/profile"
                        />
                        <Button onClick={() => setStart(true)}>{t('Start conversation')}</Button>
                    </div>
                )
            }
        >
            <Head title={t('Customer support')} />
            <div className="space-y-4">
                <PlatformSupportTabs />
                <form
                    className="flex flex-wrap gap-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.get('/platform/support', query);
                    }}
                >
                    <select
                        aria-label={t('Filter by company')}
                        className="h-9 w-48 min-w-0 rounded-md border bg-surface px-3"
                        value={query.company ?? ''}
                        onChange={(e) => setQuery({ ...query, company: e.target.value })}
                    >
                        <option value="">{t('All companies')}</option>
                        {companies.map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.name}
                            </option>
                        ))}
                    </select>
                    <Input
                        className="min-w-0 flex-1 basis-48"
                        placeholder={t('Search account ID or email')}
                        aria-label={t('Search account ID or email')}
                        value={query.search ?? ''}
                        onChange={(e) => setQuery({ ...query, search: e.target.value })}
                    />
                    <select
                        aria-label={t('Status')}
                        className="h-9 w-48 min-w-0 rounded-md border bg-surface px-3"
                        value={query.status ?? ''}
                        onChange={(e) => setQuery({ ...query, status: e.target.value })}
                    >
                        <option value="">{t('All statuses')}</option>
                        <option value="awaiting">{t('Awaiting reply')}</option>
                        <option value="replied">{t('Replied')}</option>
                    </select>
                    <Button variant="secondary">{t('Apply')}</Button>
                </form>
                {disconnected && (
                    <p role="status">
                        {t(
                            'Connection interrupted. Reconnecting… Your draft is saved on this page.',
                        )}
                    </p>
                )}
                <div className="grid min-w-[900px] grid-cols-[320px_minmax(0,1fr)] overflow-hidden rounded-xl border bg-surface">
                    <aside className="border-r">
                        <div className="max-h-[65vh] overflow-y-auto">
                            {inbox.data.map((row) => (
                                <Link
                                    key={row.id}
                                    href={chatUrl(row.tenantId, row.userId)}
                                    data={filters}
                                    className={`block border-b p-4 ${chat?.userId === row.userId && chat.tenantId === row.tenantId ? 'bg-muted' : 'hover:bg-muted/50'}`}
                                >
                                    <div className="flex justify-between gap-2">
                                        <span className="truncate text-sm font-medium">
                                            {row.company}
                                        </span>
                                        <span className="shrink-0 text-xs text-muted-foreground">
                                            {t(row.awaitingReply ? 'Awaiting reply' : 'Replied')}
                                        </span>
                                    </div>
                                    <p className="mt-2 text-sm">{row.accountId}</p>
                                    <p
                                        className="w-64 truncate text-xs text-muted-foreground"
                                        title={row.email}
                                    >
                                        {row.email}
                                    </p>
                                    <p className="mt-2 text-xs text-muted-foreground">
                                        {dateTime(row.updatedAt)}
                                    </p>
                                </Link>
                            ))}
                            {!inbox.data.length && (
                                <p className="p-8 text-sm text-muted-foreground">
                                    {t('No customer conversations yet.')}
                                </p>
                            )}
                        </div>
                        <div className="flex items-center justify-between p-3 text-sm">
                            <span>
                                {inbox.current_page} / {inbox.last_page}
                            </span>
                            {inbox.prev_page_url && (
                                <Link href={inbox.prev_page_url}>{t('Previous')}</Link>
                            )}
                            {inbox.next_page_url && (
                                <Link href={inbox.next_page_url}>{t('Next')}</Link>
                            )}
                        </div>
                    </aside>
                    <div className="min-w-0 p-4">
                        {chat ? (
                            <>
                                <h2 className="font-semibold">
                                    {chat.company} · {chat.accountId}
                                </h2>
                                <p
                                    className="truncate text-sm text-muted-foreground"
                                    title={chat.email}
                                >
                                    {chat.email}
                                </p>
                                <SupportThread
                                    key={chat.tenantId + ':' + chat.userId}
                                    chat={chat}
                                    admin
                                    workspace
                                    canSend={canSend}
                                    baseUrl={chatUrl(chat.tenantId, chat.userId)}
                                    t={t}
                                />
                            </>
                        ) : (
                            <p className="py-24 text-center text-muted-foreground">
                                {t('Select a conversation or contact a customer.')}
                            </p>
                        )}
                    </div>
                </div>
                <Dialog open={start} onOpenChange={setStart}>
                    <DialogContent closeLabel={t('Close')}>
                        <DialogHeader>
                            <DialogTitle>{t('Start conversation')}</DialogTitle>
                            <DialogDescription>
                                {t('Select a company, then search for a customer.')}
                            </DialogDescription>
                        </DialogHeader>
                        {start && <StartConversation companies={companies} />}
                    </DialogContent>
                </Dialog>
            </div>
        </PlatformLayout>
    );
}
function StartConversation({ companies }: Pick<Props, 'companies'>) {
    const [company, setCompany] = useState(''),
        [search, setSearch] = useState(''),
        [busy, setBusy] = useState(false),
        [failed, setFailed] = useState(false);
    const [users, setUsers] = useState<{ id: string; account_id: string; email: string }[] | null>(
        null,
    );
    const searchCustomers = async () => {
        if (!company || !search.trim() || busy) return;
        setBusy(true);
        setFailed(false);
        setUsers(null);
        try {
            const response = await fetch(
                `/platform/tenants/${company}/support/candidates?search=${encodeURIComponent(search.trim())}`,
                { headers: { Accept: 'application/json' }, credentials: 'same-origin' },
            );
            if (!response.ok) throw Error();
            const data: unknown = await response.json();
            if (
                !data ||
                typeof data !== 'object' ||
                !('users' in data) ||
                !Array.isArray(data.users) ||
                !data.users.every(isCandidate)
            )
                throw Error();
            setUsers(data.users);
        } catch {
            setFailed(true);
        } finally {
            setBusy(false);
        }
    };
    return (
        <form
            className="space-y-4"
            onSubmit={(e) => {
                e.preventDefault();
                void searchCustomers();
            }}
        >
            <select
                disabled={busy}
                aria-label={t('Company')}
                className="h-10 w-full rounded-md border bg-surface px-3"
                value={company}
                onChange={(e) => {
                    setCompany(e.target.value);
                    setUsers(null);
                }}
            >
                <option value="">{t('Select company')}</option>
                {companies.map((c) => (
                    <option key={c.id} value={c.id}>
                        {c.name}
                    </option>
                ))}
            </select>
            <Input
                disabled={busy}
                maxLength={120}
                aria-label={t('Search account ID or email')}
                placeholder={t('Search account ID or email')}
                value={search}
                onChange={(e) => {
                    setSearch(e.target.value);
                    setUsers(null);
                }}
            />
            <Button disabled={!company || !search.trim() || busy}>{t('Search')}</Button>
            {failed && <p role="alert">{t('Unable to complete this request.')}</p>}
            {users && (
                <div className="max-h-72 overflow-y-auto">
                    {users.map((u) => (
                        <Link
                            className="block border-b p-3 hover:bg-muted"
                            key={u.id}
                            href={chatUrl(company, u.id)}
                        >
                            <p>{u.account_id}</p>
                            <p className="truncate text-sm text-muted-foreground">{u.email}</p>
                        </Link>
                    ))}
                    {!users.length && <p>{t('No matching records.')}</p>}
                </div>
            )}
        </form>
    );
}

function isCandidate(value: unknown): value is { id: string; account_id: string; email: string } {
    return (
        !!value &&
        typeof value === 'object' &&
        'id' in value &&
        typeof value.id === 'string' &&
        'account_id' in value &&
        typeof value.account_id === 'string' &&
        'email' in value &&
        typeof value.email === 'string'
    );
}
