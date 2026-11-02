import { useEffect, useState } from 'react';
import { t } from '@/i18n/admin';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type { AccountPage } from '@/components/shared/PlatformAccountTable';
import { AdminPagination } from './AdminPagination';
import { OperationFeedback } from './OperationFeedback';
import { platformCardColumns, type UserCard } from './PlatformCardColumns';
import { PlatformCardTransactions } from './PlatformCardTransactions';
import { readEditorResponse } from './editor-response';
import type { UserInfo } from './UserInfoCell';

type CardsResult = {
    tenantId: string;
    userId: string;
    cards: AccountPage<UserCard>;
};

export function UserCardsPanel({ user }: { user: UserInfo }) {
    const [page, setPage] = useState(1);
    const [result, setResult] = useState<CardsResult | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(false);
    const [attempt, setAttempt] = useState(0);
    const [selectedCard, setSelectedCard] = useState<UserCard | null>(null);

    useEffect(() => {
        const controller = new AbortController();
        setLoading(true);
        setError(false);
        setResult(null);
        setSelectedCard(null);
        void (async () => {
            try {
                const response = await fetch(
                    `/platform/tenants/${encodeURIComponent(user.companyId)}/users/${encodeURIComponent(user.id)}/cards?page=${page}`,
                    {
                        credentials: 'same-origin',
                        cache: 'no-store',
                        signal: AbortSignal.any([controller.signal, AbortSignal.timeout(15000)]),
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    },
                );
                const data = (await readEditorResponse(response)) as unknown as CardsResult;
                if (
                    !response.ok ||
                    data.tenantId !== user.companyId ||
                    data.userId !== user.id ||
                    !data.cards ||
                    !Array.isArray(data.cards.data) ||
                    data.cards.current_page !== page ||
                    !Number.isInteger(data.cards.last_page) ||
                    data.cards.last_page < 1 ||
                    !Number.isInteger(data.cards.total) ||
                    data.cards.total < 0 ||
                    !data.cards.data.every(
                        (card) => card.tenantId === user.companyId && card.userId === user.id,
                    )
                )
                    throw new Error('Invalid card list');
                if (!controller.signal.aborted) setResult(data);
            } catch {
                if (!controller.signal.aborted) setError(true);
            } finally {
                if (!controller.signal.aborted) setLoading(false);
            }
        })();
        return () => controller.abort();
    }, [user.companyId, user.id, page, attempt]);

    return (
        <>
            <div
                className="min-h-0 min-w-0 flex-1 overflow-auto p-5"
                data-detail-body
                aria-busy={loading}
            >
                {error ? (
                    <div className="space-y-4">
                        <OperationFeedback role="alert">
                            {t('Unable to load. Please retry.')}
                        </OperationFeedback>
                        <Button
                            variant="secondary"
                            onClick={() => setAttempt((value) => value + 1)}
                        >
                            {t('Retry')}
                        </Button>
                    </div>
                ) : loading ? (
                    <p role="status">{t('Loading…')}</p>
                ) : (
                    <div className="rounded-lg border">
                        <Table aria-label={t('Cards')}>
                            <TableHeader>
                                <TableRow>
                                    {platformCardColumns.map((column) => (
                                        <TableHead key={column.label} className="whitespace-nowrap">
                                            {t(column.label)}
                                        </TableHead>
                                    ))}
                                    <TableHead className="whitespace-nowrap">
                                        {t('Actions')}
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {result?.cards.data.map((card) => (
                                    <TableRow key={card.id}>
                                        {platformCardColumns.map((column) => (
                                            <TableCell key={column.label}>
                                                {column.render(card)}
                                            </TableCell>
                                        ))}
                                        <TableCell>
                                            <Button
                                                variant="secondary"
                                                size="sm"
                                                onClick={() => setSelectedCard(card)}
                                            >
                                                {t('View transactions')}
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                                {result?.cards.data.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={platformCardColumns.length + 1}
                                            className="p-5 text-center text-muted-foreground"
                                        >
                                            {t('No matching records.')}
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </div>
            {result && (
                <AdminPagination
                    className="shrink-0 border-t px-5 py-3"
                    page={result.cards.current_page}
                    pages={result.cards.last_page}
                    total={result.cards.total}
                    disabled={loading}
                    onPage={setPage}
                />
            )}
            {selectedCard && (
                <PlatformCardTransactions
                    key={`${selectedCard.tenantId}:${selectedCard.id}`}
                    card={selectedCard}
                    onClose={() => setSelectedCard(null)}
                />
            )}
        </>
    );
}
