import { CardLoadOrders, type CardLoadOrder } from '@/components/admin/CardLoadOrders';
import type { AccountPage } from '@/components/shared/PlatformAccountTable';
import { displayMoney } from '@/lib/exact-amount';
import { useAdminTranslation, t, dateTime, errorMessage } from '@/i18n/admin';
import { Head } from '@inertiajs/react';
import { PageHeader } from '@/components/shared/PageHeader';
import { StatusBadge, type StatusTone } from '@/components/shared/StatusBadge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { TenantAdminLayout } from '@/layouts/TenantAdminLayout';

type Holder = {
    id: string;
    userEmail: string;
    status: string;
    safeReason: string | null;
    updatedAt: string;
};
type Order = {
    id: string;
    userEmail: string;
    productName: string;
    openingFee: string;
    initialLoadAmount: string;
    status: string;
    requestedAt: string;
};
type UserCard = {
    id: string;
    userEmail: string;
    productName: string;
    maskedPan: string;
    formFactor: string;
    produceStatus: string | null;
    trackingNumber: string | null;
    currency: string;
    balance: string | null;
    providerStatus: string;
};

const tone = (status: string): StatusTone =>
    status === 'READY' || status === 'SUCCEEDED' || status === 'normal'
        ? 'SUCCESS'
        : status === 'FAILED' || status === 'REJECTED' || status === 'DISABLED'
          ? 'DANGER'
          : status === 'UNKNOWN' || status === 'ACTION_REQUIRED'
            ? 'WARNING'
            : 'INFO';

export default function Cards({
    loads,
    cardholders,
    orders,
    cards,
}: {
    loads: AccountPage<CardLoadOrder>;
    cardholders: Holder[];
    orders: Order[];
    cards: UserCard[];
}) {
    useAdminTranslation();
    return (
        <TenantAdminLayout>
            <Head title={t('Card operations')} />
            <div className="space-y-6">
                <PageHeader
                    eyebrow={t('Operations')}
                    title={t('Cards')}
                    description={t(
                        'Read-only PhotonPay Cardholder, issue-order, and safe card visibility. Financial overrides are not available.',
                    )}
                />
                <Tabs defaultValue="orders">
                    <TabsList>
                        <TabsTrigger value="orders">{t('Issue orders')}</TabsTrigger>
                        <TabsTrigger value="loads">{t('Card reload orders')}</TabsTrigger>
                        <TabsTrigger value="cards">{t('Cards')}</TabsTrigger>
                        <TabsTrigger value="cardholders">{t('Cardholders')}</TabsTrigger>
                    </TabsList>
                    <TabsContent value="orders">
                        <Card>
                            <CardHeader>
                                <CardTitle>{t('Issue orders')}</CardTitle>
                            </CardHeader>
                            <CardContent className="p-0">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>{t('User')}</TableHead>
                                            <TableHead>{t('Product')}</TableHead>
                                            <TableHead>{t('Opening fee')}</TableHead>
                                            <TableHead>{t('Initial load')}</TableHead>
                                            <TableHead>{t('Status')}</TableHead>
                                            <TableHead>{t('Requested')}</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {orders.map((order) => (
                                            <TableRow key={order.id}>
                                                <TableCell>{order.userEmail}</TableCell>
                                                <TableCell>{order.productName}</TableCell>
                                                <TableCell>
                                                    {displayMoney(order.openingFee)} USDT
                                                </TableCell>
                                                <TableCell>
                                                    {displayMoney(order.initialLoadAmount)} USDT
                                                </TableCell>
                                                <TableCell>
                                                    <StatusBadge
                                                        status={tone(order.status)}
                                                        label={t(order.status)}
                                                    />
                                                </TableCell>
                                                <TableCell>{dateTime(order.requestedAt)}</TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </CardContent>
                        </Card>
                    </TabsContent>
                    <TabsContent value="loads">
                        <CardLoadOrders page={loads} />
                    </TabsContent>
                    <TabsContent value="cards">
                        <Card>
                            <CardHeader>
                                <CardTitle>{t('Safe card details')}</CardTitle>
                            </CardHeader>
                            <CardContent className="p-0">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>{t('User')}</TableHead>
                                            <TableHead>{t('Product')}</TableHead>
                                            <TableHead>{t('Card')}</TableHead>
                                            <TableHead>{t('Provider balance')}</TableHead>
                                            <TableHead>{t('Status')}</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {cards.map((card) => (
                                            <TableRow key={card.id}>
                                                <TableCell>{card.userEmail}</TableCell>
                                                <TableCell>{card.productName}</TableCell>
                                                <TableCell className="font-mono">
                                                    {card.maskedPan}
                                                    <span className="block text-xs font-sans">
                                                        {t(
                                                            card.formFactor === 'physical_card'
                                                                ? 'Physical card'
                                                                : 'Virtual card',
                                                        )}
                                                    </span>
                                                    {card.produceStatus && (
                                                        <span className="block text-xs">
                                                            {t(
                                                                card.produceStatus === 'produced'
                                                                    ? 'Card produced'
                                                                    : 'Card production pending',
                                                            )}
                                                        </span>
                                                    )}
                                                    {card.trackingNumber && (
                                                        <span className="block text-xs">
                                                            {t('Tracking number')}:{' '}
                                                            {card.trackingNumber}
                                                        </span>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    {card.balance === null
                                                        ? t('Not synced')
                                                        : t('{{value1}} {{value2}}', {
                                                              value1: displayMoney(card.balance),
                                                              value2: card.currency,
                                                          })}
                                                </TableCell>
                                                <TableCell>
                                                    <StatusBadge
                                                        status={tone(card.providerStatus)}
                                                        label={t(card.providerStatus)}
                                                    />
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </CardContent>
                        </Card>
                    </TabsContent>
                    <TabsContent value="cardholders">
                        <Card>
                            <CardHeader>
                                <CardTitle>{t('Provider Cardholders')}</CardTitle>
                            </CardHeader>
                            <CardContent className="p-0">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>{t('User')}</TableHead>
                                            <TableHead>{t('Status')}</TableHead>
                                            <TableHead>{t('Safe reason')}</TableHead>
                                            <TableHead>{t('Updated')}</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {cardholders.map((holder) => (
                                            <TableRow key={holder.id}>
                                                <TableCell>{holder.userEmail}</TableCell>
                                                <TableCell>
                                                    <StatusBadge
                                                        status={tone(holder.status)}
                                                        label={t(holder.status)}
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    {errorMessage(holder.safeReason ?? undefined) ??
                                                        '—'}
                                                </TableCell>
                                                <TableCell>{dateTime(holder.updatedAt)}</TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </CardContent>
                        </Card>
                    </TabsContent>
                </Tabs>
            </div>
        </TenantAdminLayout>
    );
}
