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
import { PlatformLayout } from '@/layouts/PlatformLayout';

type Order = {
    id: string;
    tenantId: string;
    userEmail: string;
    productName: string;
    openingFee: string;
    initialLoadAmount: string;
    status: string;
    requestedAt: string;
};
type UserCard = {
    id: string;
    tenantId: string;
    userEmail: string;
    productName: string;
    maskedPan: string;
    currency: string;
    balance: string | null;
    providerStatus: string;
};
const tone = (status: string): StatusTone =>
    status === 'SUCCEEDED' || status === 'normal'
        ? 'SUCCESS'
        : status === 'FAILED'
          ? 'DANGER'
          : status === 'UNKNOWN'
            ? 'WARNING'
            : 'INFO';

export default function Cards({ orders, cards }: { orders: Order[]; cards: UserCard[] }) {
    return (
        <PlatformLayout>
            <Head title="Card operations" />
            <div className="space-y-6">
                <PageHeader
                    eyebrow="Operations"
                    title="Cards"
                    description="Read-only cross-tenant operational visibility. No issue, settlement, release, or balance controls are exposed."
                />
                <Tabs defaultValue="orders">
                    <TabsList>
                        <TabsTrigger value="orders">Issue orders</TabsTrigger>
                        <TabsTrigger value="cards">Cards</TabsTrigger>
                    </TabsList>
                    <TabsContent value="orders">
                        <Card>
                            <CardHeader>
                                <CardTitle>Recent issue orders</CardTitle>
                            </CardHeader>
                            <CardContent className="p-0">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Tenant</TableHead>
                                            <TableHead>User</TableHead>
                                            <TableHead>Product</TableHead>
                                            <TableHead>Amounts</TableHead>
                                            <TableHead>Status</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {orders.map((order) => (
                                            <TableRow key={order.id}>
                                                <TableCell className="font-mono text-xs">
                                                    {order.tenantId}
                                                </TableCell>
                                                <TableCell>{order.userEmail}</TableCell>
                                                <TableCell>{order.productName}</TableCell>
                                                <TableCell>
                                                    {order.openingFee} + {order.initialLoadAmount}{' '}
                                                    USDT
                                                </TableCell>
                                                <TableCell>
                                                    <StatusBadge
                                                        status={tone(order.status)}
                                                        label={order.status}
                                                    />
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
                                            <TableHead>Tenant</TableHead>
                                            <TableHead>User</TableHead>
                                            <TableHead>Product</TableHead>
                                            <TableHead>Card</TableHead>
                                            <TableHead>Status</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {cards.map((card) => (
                                            <TableRow key={card.id}>
                                                <TableCell className="font-mono text-xs">
                                                    {card.tenantId}
                                                </TableCell>
                                                <TableCell>{card.userEmail}</TableCell>
                                                <TableCell>{card.productName}</TableCell>
                                                <TableCell className="font-mono">
                                                    {card.maskedPan}
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
                </Tabs>
            </div>
        </PlatformLayout>
    );
}
