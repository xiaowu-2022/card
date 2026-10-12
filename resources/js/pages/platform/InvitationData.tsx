import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { AdminPagination } from '@/components/admin/AdminPagination';
import { PartnerInvitationsReport } from '@/components/admin/PartnerInvitationsReport';
import type { Selection } from '@/components/admin/partner-invitations-state';
import { RecordUserCell, type UserInfo } from '@/components/admin/UserInfoCell';
import { DetailDrawerContent } from '@/components/admin/DetailDrawer';
import { Dialog, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import { exactAmount } from '@/lib/exact-amount';
import { t, useAdminTranslation } from '@/i18n/admin';
import '../../../css/partner-stock.css';

type People = { direct: number; indirect: number };
type Account = {
    userId: string;
    tenant_id: string;
    company_name: string;
    account_id: string;
    display_name: string | null;
    partner_id: string | null;
    userInfo?: UserInfo;
    commission: string;
    summary: {
        registeredMembers: People;
        teamByLevel: (People & { rank: number })[];
        directPeople: number;
        indirectPeople: number;
        totals: { ANNUAL: string; ACTIVATION: string };
    };
};
type Props = {
    companies: { id: string; name: string }[];
    companyId: string | null;
    category: 'all' | 'users' | 'partners';
    search: string;
    ranks: number[];
    selected: {
        id: string;
        tenant_id: string;
        account_id: string;
        display_name: string | null;
    } | null;
    accounts: { data: Account[]; current_page: number; last_page: number; total: number };
};

export default function InvitationData({
    companies,
    companyId,
    category,
    search,
    ranks,
    selected,
    accounts,
}: Props) {
    useAdminTranslation();
    const [query, setQuery] = useState(search);
    const filters = {
        ...(companyId ? { company: companyId } : {}),
        category,
        ...(search ? { search } : {}),
    };
    const visit = (changes: Record<string, string | number>) =>
        router.get(
            '/platform/invitation-data',
            { ...filters, ...changes },
            { preserveScroll: true },
        );
    const close = () => visit({ page: accounts.current_page });
    const onSelection = (selection: Selection) => {
        const url = new URL(location.href);
        ['invitation_kind', 'invitation_rank', 'invitation_page'].forEach((key) =>
            url.searchParams.delete(key),
        );
        if (selection) {
            url.searchParams.set('invitation_kind', selection.kind);
            url.searchParams.set('invitation_rank', String(selection.rank));
            url.searchParams.set('invitation_page', String(selection.page));
        }
        history.replaceState(history.state, '', url);
    };
    const people = (row: Account, rank: number): People =>
        rank < 0
            ? row.summary.registeredMembers
            : (row.summary.teamByLevel.find((level) => level.rank === rank) ?? {
                  direct: 0,
                  indirect: 0,
              });
    const levels = [-1, ...ranks];
    return (
        <PlatformLayout title={t('Invitation data')}>
            <Head title={t('Invitation data')} />
            <div className="partner-admin partner-admin-platform space-y-4">
                <form
                    className="partner-form partner-summary-filters"
                    onSubmit={(event) => {
                        event.preventDefault();
                        visit({ search: query, page: 1 });
                    }}
                >
                    <label>
                        {t('Company')}
                        <select
                            aria-label={t('Company')}
                            value={companyId ?? ''}
                            onChange={(event) => visit({ company: event.target.value, page: 1 })}
                        >
                            <option value="">{t('All companies')}</option>
                            {companies.map((company) => (
                                <option key={company.id} value={company.id}>
                                    {company.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label>
                        {t('Account category')}
                        <select
                            aria-label={t('Account category')}
                            value={category}
                            onChange={(event) => visit({ category: event.target.value, page: 1 })}
                        >
                            <option value="all">{t('All')}</option>
                            <option value="users">{t('Users')}</option>
                            <option value="partners">{t('Partners')}</option>
                        </select>
                    </label>
                    <label>
                        {t('Search')}
                        <Input
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder={t('Account / name / email')}
                        />
                    </label>
                    <Button type="submit" variant="secondary">
                        {t('Search')}
                    </Button>
                </form>
                <p className="text-sm text-muted-foreground">{t('Invitation directory basis')}</p>
                <div className="overflow-x-auto rounded-lg border bg-background">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b">
                                <th
                                    rowSpan={2}
                                    className="p-3 text-left bg-background sm:sticky sm:left-0 z-10"
                                >
                                    {t('Company / User')}
                                </th>
                                <th rowSpan={2} className="p-3 whitespace-nowrap">
                                    {t('Account category')}
                                </th>
                                {levels.map((rank) => (
                                    <th
                                        key={rank}
                                        colSpan={2}
                                        className="p-3 whitespace-nowrap text-center"
                                    >
                                        {rank < 0
                                            ? t('Registered member (not activated)')
                                            : rank === 0
                                              ? t('Ordinary member')
                                              : t('Mastercard level {{rank}}', { rank })}
                                    </th>
                                ))}
                                <th colSpan={2} className="p-3 text-center">
                                    {t('Team members')}
                                </th>
                                {[
                                    'Annual fee commission',
                                    'Activation commission',
                                    'Cumulative commission',
                                    'Actions',
                                ].map((label) => (
                                    <th
                                        key={label}
                                        rowSpan={2}
                                        className={`p-3 whitespace-nowrap ${label === 'Actions' ? 'sticky right-0 z-10 bg-background' : ''}`}
                                    >
                                        {t(label)}
                                        {label !== 'Actions' && ' · USDT'}
                                    </th>
                                ))}
                            </tr>
                            <tr className="border-b">
                                {[...levels, -2].flatMap((rank) =>
                                    ['Direct', 'Indirect'].map((relation) => (
                                        <th
                                            key={`${rank}-${relation}`}
                                            className="p-3 whitespace-nowrap text-right"
                                        >
                                            {t(relation)}
                                        </th>
                                    )),
                                )}
                            </tr>
                        </thead>
                        <tbody>
                            {accounts.data.map((row) => (
                                <tr key={row.userId} className="border-b">
                                    <td className="p-3 min-w-48 bg-background sm:sticky sm:left-0 z-10">
                                        <RecordUserCell row={row} />
                                    </td>
                                    <td className="p-3 whitespace-nowrap">
                                        {t(row.partner_id ? 'Partners' : 'Users')}
                                    </td>
                                    {levels.flatMap((rank) =>
                                        (['direct', 'indirect'] as const).map((relation) => (
                                            <td
                                                key={`${rank}-${relation}`}
                                                className="p-3 text-right tabular-nums"
                                            >
                                                {people(row, rank)[relation]}
                                            </td>
                                        )),
                                    )}
                                    <td className="p-3 text-right">{row.summary.directPeople}</td>
                                    <td className="p-3 text-right">{row.summary.indirectPeople}</td>
                                    {[
                                        row.summary.totals.ANNUAL,
                                        row.summary.totals.ACTIVATION,
                                        row.commission,
                                    ].map((amount, index) => (
                                        <td
                                            key={index}
                                            className="p-3 text-right whitespace-nowrap tabular-nums"
                                        >
                                            {exactAmount(amount)}
                                        </td>
                                    ))}
                                    <td className="p-3 whitespace-nowrap sticky right-0 z-10 bg-background">
                                        <Button
                                            variant="ghost"
                                            onClick={() =>
                                                visit({
                                                    page: accounts.current_page,
                                                    subject: row.userId,
                                                    subject_company: row.tenant_id,
                                                })
                                            }
                                        >
                                            {t('Details')}
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                            {!accounts.data.length && (
                                <tr>
                                    <td colSpan={8 + levels.length * 2} className="p-6 text-center">
                                        {t('No matching records.')}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
                <AdminPagination
                    page={accounts.current_page}
                    pages={accounts.last_page}
                    total={accounts.total}
                    onPage={(page) => visit({ page })}
                />
                <Dialog
                    open={!!selected}
                    onOpenChange={(open) => {
                        if (!open) close();
                    }}
                >
                    <DetailDrawerContent>
                        <DialogHeader className="px-4 pt-4">
                            <DialogTitle>
                                {t('Invitation data')} ·{' '}
                                {selected?.display_name || selected?.account_id}
                            </DialogTitle>
                            <DialogDescription>
                                {t('Invitation report detail description')}
                            </DialogDescription>
                        </DialogHeader>
                        {selected && (
                            <PartnerInvitationsReport
                                key={`${selected.tenant_id}:${selected.id}`}
                                partner={selected.id}
                                company={null}
                                endpoint={`/platform/invitation-data/${selected.tenant_id}/${selected.id}/report`}
                                onSelection={onSelection}
                            />
                        )}
                    </DetailDrawerContent>
                </Dialog>
            </div>
        </PlatformLayout>
    );
}
