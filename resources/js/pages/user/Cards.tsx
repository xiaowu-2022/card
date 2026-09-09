import { Head } from '@inertiajs/react';
import { Eye, LockKeyhole, Snowflake, WalletCards } from 'lucide-react';
import { PageHeader } from '@/components/shared/PageHeader';
import { StatusBadge } from '@/components/shared/StatusBadge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { UserLayout } from '@/layouts/UserLayout';

export default function Cards() {
    return (
        <UserLayout>
            <Head title="Cards" />
            <div className="space-y-6">
                <PageHeader
                    eyebrow="Demo cards"
                    title="Your cards"
                    description="Card details and controls are placeholders wired to no production operation."
                />
                <div className="grid gap-6 lg:grid-cols-[minmax(0,25rem)_1fr]">
                    <div className="rounded-2xl bg-slate-950 p-6 text-white shadow-xl">
                        <div className="flex justify-between">
                            <span className="font-semibold">TEST / MOCK</span>
                            <span className="text-xl font-bold italic">VISA</span>
                        </div>
                        <p className="mt-16 text-2xl tracking-[.18em]">•••• 1234</p>
                        <div className="mt-8 flex items-end justify-between">
                            <div>
                                <p className="text-xs text-slate-400">CARDHOLDER</p>
                                <p className="mt-1 text-sm">DEMO USER</p>
                            </div>
                            <div>
                                <p className="text-xs text-slate-400">EXPIRES</p>
                                <p className="mt-1 text-sm">08/29</p>
                            </div>
                        </div>
                    </div>
                    <div className="space-y-4">
                        <Card>
                            <CardHeader>
                                <div className="flex justify-between">
                                    <CardTitle>Virtual Visa · USD</CardTitle>
                                    <StatusBadge status="SUCCESS" label="Active · Mock" />
                                </div>
                            </CardHeader>
                            <CardContent className="grid grid-cols-3 gap-2">
                                <Button variant="secondary" disabled className="flex-col py-6">
                                    <Eye className="size-5" />
                                    Reveal
                                </Button>
                                <Button variant="secondary" disabled className="flex-col py-6">
                                    <WalletCards className="size-5" />
                                    Load
                                </Button>
                                <Button variant="secondary" disabled className="flex-col py-6">
                                    <Snowflake className="size-5" />
                                    Freeze
                                </Button>
                            </CardContent>
                        </Card>
                        <Alert>
                            <AlertTitle>Safe mock representation</AlertTitle>
                            <AlertDescription>
                                This card has no real PAN or CVV. Reveal would use short-lived
                                provider data in a future phase.
                            </AlertDescription>
                        </Alert>
                        <Card>
                            <CardContent className="flex items-center gap-3 p-5">
                                <LockKeyhole className="size-5 text-success" />
                                <div>
                                    <p className="font-medium">
                                        Sensitive data stays with the provider
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        Only masked identifiers are retained by the application.
                                    </p>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>
        </UserLayout>
    );
}
