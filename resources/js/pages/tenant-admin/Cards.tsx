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
    cardholders,
    orders,
    cards,
}: {
    cardholders: Holder[];
    orders: Order[];
    cards: UserCard[];
}) {
    return (
        <TenantAdminLayout>
            <Head title="Card operations" />
            <div className="space-y-6">
                <PageHeader
                    eyebrow="Operations"
                    title="Cards"
                    description="Read-only PhotonPay Cardholder, issue-order, and safe card visibility. Financial overrides are not available."
                />
                <Tabs defaultValue="orders">
                    <TabsList>
                        <TabsTrigger value="orders">Issue orders</TabsTrigger>
                        <TabsTrigger value="cards">Cards</TabsTrigger>
                        <TabsTrigger value="cardholders">Cardholders</TabsTrigger>
                    </TabsList>
                    <TabsContent value="orders">
                        <Card>
                            <CardHeader>
                                <CardTitle>Issue orders</CardTitle>
                            </CardHeader>
                            <CardContent className="p-0">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>User</TableHead>
                                            <TableHead>Product</TableHead>
                                            <TableHead>Opening fee</TableHead>
                                            <TableHead>Initial load</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Requested</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {orders.map((order) => (
                                            <TableRow key={order.id}>
                                                <TableCell>{order.userEmail}</TableCell>
                                                <TableCell>{order.productName}</TableCell>
                                                <TableCell>{order.openingFee} USDT</TableCell>
                                                <TableCell>
                                                    {order.initialLoadAmount} USDT
                                                </TableCell>
                                                <TableCell>
                                                    <StatusBadge
                                                        status={tone(order.status)}
                                                        label={order.status}
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    {new Date(order.requestedAt).toLocaleString()}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </CardContent>
                        </Card>
                    </TabsContent>
                    <TabsContent value="cards">
                        <Card>
                            <CardHeader>
                                <CardTitle>Safe card details</CardTitle>
                            </CardHeader>
                            <CardContent className="p-0">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>User</TableHead>
                                            <TableHead>Product</TableHead>
                                            <TableHead>Card</TableHead>
                                            <TableHead>Provider balance</TableHead>
                                            <TableHead>Status</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {cards.map((card) => (
                                            <TableRow key={card.id}>
                                                <TableCell>{card.userEmail}</TableCell>
                                                <TableCell>{card.productName}</TableCell>
                                                <TableCell className="font-mono">
                                                    {card.maskedPan}
                                                </TableCell>
                                                <TableCell>
                                                    {card.balance === null
                                                        ? 'Not synced'
                                                        : `${card.balance} ${card.currency}`}
                                                </TableCell>
                                                <TableCell>
                                                    <StatusBadge
                                                        status={tone(card.providerStatus)}
                                                        label={card.providerStatus}
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
                                <CardTitle>Provider Cardholders</CardTitle>
                            </CardHeader>
                            <CardContent className="p-0">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>User</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Safe reason</TableHead>
                                            <TableHead>Updated</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {cardholders.map((holder) => (
                                            <TableRow key={holder.id}>
                                                <TableCell>{holder.userEmail}</TableCell>
                                                <TableCell>
                                                    <StatusBadge
                                                        status={tone(holder.status)}
                                                        label={holder.status}
                                                    />
                                                </TableCell>
                                                <TableCell>{holder.safeReason ?? '—'}</TableCell>
                                                <TableCell>
                                                    {new Date(holder.updatedAt).toLocaleString()}
                                                </TableCell>
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
