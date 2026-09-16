import { exactAmount } from '@/lib/exact-amount';
import { useEffect, useState } from 'react';
import { Head, Link, useForm, usePage, router } from '@inertiajs/react';
import { ArrowLeft, ChevronDown, Check } from 'lucide-react';
import { QRCodeSVG } from 'qrcode.react';
import { UserLayout } from '@/layouts/UserLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Sheet, SheetContent, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import { AssetIcon, type AssetOverview } from '@/components/user/AssetCenter';
import { t, dateTime, errorMessage, useClientTranslation } from '@/i18n';

type Result = {
    id: string;
    asset: string;
    amount: string;
    state: string;
    network?: string;
    address?: string;
    fee?: string;
    receive?: string;
    rate?: string;
    expiresAt?: string;
    canConfirm?: boolean;
    canCancel?: boolean;
};
export default function AssetFlow({
    overview,
    mode,
    selectedAsset,
    result,
}: {
    overview: AssetOverview;
    mode: 'deposit' | 'withdrawal' | 'exchange';
    selectedAsset: string;
    result: Result | null;
}) {
    useClientTranslation();
    const serverErrors = usePage().props.errors;
    const [clock, setClock] = useState(() => Date.now());
    useEffect(() => {
        const timer = window.setInterval(() => setClock(Date.now()), 1000);
        return () => window.clearInterval(timer);
    }, []);
    const seconds = result?.expiresAt
        ? Math.max(0, Math.ceil((new Date(result.expiresAt).getTime() - clock) / 1000))
        : 0;
    const [picker, setPicker] = useState<'asset' | 'network' | null>(null);
    const [review, setReview] = useState(false);
    const [busy, setBusy] = useState(false);
    const form = useForm({
        mode,
        asset: selectedAsset,
        rail: '',
        amount: '',
        address: '',
        expected_fee: '',
        confirmed: false,
        request_id: crypto.randomUUID(),
    });
    const account = overview.assets.find((a) => a.asset === form.data.asset)!;
    const rails = account.rails.filter((r) => (mode === 'deposit' ? r.deposit : r.withdrawal));
    const rail = rails.find((r) => r.code === form.data.rail);
    const title =
        mode === 'deposit' ? 'Top up' : mode === 'withdrawal' ? 'Withdraw' : 'Exchange to USDT';
    const update = (field: 'amount' | 'address', value: string) => {
        form.setData({
            ...form.data,
            [field]: value,
            request_id: crypto.randomUUID(),
            confirmed: false,
        });
        setReview(false);
    };
    const choose = (asset: string, code = '') => {
        router.get('/assets/operate', { mode, asset }, { preserveScroll: true });
        form.setData({
            ...form.data,
            asset,
            rail: code,
            amount: '',
            address: '',
            expected_fee: '',
            confirmed: false,
            request_id: crypto.randomUUID(),
        });
        setPicker(null);
        setReview(false);
    };
    const submit = () => {
        if (rail?.code === 'USDT_TRON') {
            router.visit(mode === 'deposit' ? '/wallet/top-up' : '/wallet/withdraw');
            return;
        }
        form.post('/assets/orders', { preserveScroll: true });
    };
    const operation = (url: string) => {
        setBusy(true);
        router.post(
            url,
            { confirmed: true },
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };
    return (
        <UserLayout>
            <Head title={t(title)} />
            <div className="mx-auto max-w-lg space-y-6">
                <div className="flex items-center gap-3">
                    <Link
                        href="/dashboard"
                        aria-label={t('Back')}
                        className="flex size-11 items-center justify-center rounded-full bg-muted"
                    >
                        <ArrowLeft size={20} />
                    </Link>
                    <h1 className="text-xl font-semibold">{t(title)}</h1>
                </div>
                {result ? (
                    <section className="space-y-5 rounded-2xl bg-surface p-5">
                        <div className="flex items-center gap-3">
                            <AssetIcon asset={result.asset} />
                            <div>
                                <h2 className="font-semibold">{t(result.state)}</h2>
                                <p className="text-sm text-muted-foreground">
                                    {result.asset}
                                    {result.network ? ` · ${result.network}` : ''}
                                </p>
                            </div>
                        </div>
                        <p className="break-all text-3xl font-semibold">
                            {exactAmount(result.amount)}{' '}
                            <span className="text-base">{result.asset}</span>
                        </p>
                        {mode === 'deposit' &&
                            result.expiresAt &&
                            seconds === 0 &&
                            result.state !== 'Completed' && (
                                <p role="alert" className="text-sm text-destructive">
                                    {t(
                                        'This deposit order has expired. Create a new order before sending funds.',
                                    )}
                                </p>
                            )}
                        {mode === 'deposit' &&
                            result.address &&
                            seconds > 0 &&
                            result.state !== 'Completed' && (
                                <>
                                    <p className="text-sm">
                                        {t('Send the exact amount using this network only.')}
                                    </p>
                                    <div className="mx-auto w-fit rounded-xl bg-white p-3">
                                        <QRCodeSVG value={result.address} size={176} />
                                    </div>
                                    <p className="break-all rounded-xl bg-muted p-3 font-mono text-sm">
                                        {result.address}
                                    </p>
                                </>
                            )}
                        {mode === 'withdrawal' && (
                            <p className="break-all text-sm">
                                {t('Destination address')}: {result.address}
                            </p>
                        )}
                        {result.rate && (
                            <p className="break-all text-sm">
                                {t('Exchange rate')}: 1 {result.asset} {'≈'}{' '}
                                {exactAmount(result.rate)} USDT
                            </p>
                        )}
                        {result.fee !== undefined && (
                            <p className="break-all text-sm">
                                {t('Platform fee')}: {exactAmount(result.fee)}{' '}
                                {mode === 'exchange' ? 'USDT' : result.asset}
                            </p>
                        )}
                        {result.receive && (
                            <div className="border-t pt-4">
                                <p className="text-sm text-muted-foreground">{t('You receive')}</p>
                                <p className="mt-1 break-all text-2xl font-semibold">
                                    {exactAmount(result.receive)}{' '}
                                    {mode === 'exchange' ? 'USDT' : result.asset}
                                </p>
                            </div>
                        )}
                        {result.expiresAt && (
                            <p className="text-xs text-muted-foreground">
                                {t('Valid until {{time}}', { time: dateTime(result.expiresAt) })}
                            </p>
                        )}
                        {mode === 'exchange' && result.canConfirm && (
                            <>
                                <p className="text-sm text-muted-foreground">
                                    {t(
                                        'This exchanges your available balance internally. Confirm only after reviewing the final amounts.',
                                    )}
                                </p>
                                <Button
                                    className="min-h-12 w-full rounded-full"
                                    disabled={busy || seconds === 0}
                                    onClick={() =>
                                        operation(`/assets/exchanges/${result.id}/confirm`)
                                    }
                                >
                                    {t('Confirm exchange')} ·{' '}
                                    {t('{{seconds}} seconds', { seconds })}
                                </Button>
                            </>
                        )}
                        {result.canCancel && (
                            <Button
                                variant="secondary"
                                className="min-h-12 w-full"
                                disabled={busy}
                                onClick={() => operation(`/assets/withdrawals/${result.id}/cancel`)}
                            >
                                {t('Cancel withdrawal')}
                            </Button>
                        )}
                        <Link
                            href={`/assets/operate?mode=${mode}&asset=${result.asset}`}
                            className="block py-3 text-center text-sm underline"
                        >
                            {t('Start a new request')}
                        </Link>
                    </section>
                ) : (
                    <section className="space-y-5 rounded-2xl bg-surface p-5">
                        <button
                            onClick={() => setPicker('asset')}
                            className="flex min-h-14 w-full items-center gap-3 rounded-xl bg-muted p-3"
                        >
                            <AssetIcon asset={account.asset} />
                            <span className="flex-1 text-left font-medium">{account.asset}</span>
                            <ChevronDown size={18} />
                        </button>
                        <p className="break-all text-xs text-muted-foreground">
                            {t('Available balance')}: {exactAmount(account.available)}{' '}
                            {account.asset}
                        </p>
                        {mode !== 'exchange' && (
                            <button
                                onClick={() => setPicker('network')}
                                className="flex min-h-12 w-full items-center justify-between rounded-xl border px-4 text-sm"
                            >
                                {rail?.network ?? t('Select network')}
                                <ChevronDown size={18} />
                            </button>
                        )}
                        {rail?.code === 'USDT_TRON' ? (
                            <Button className="min-h-12 w-full rounded-full" onClick={submit}>
                                {t('Continue')}
                            </Button>
                        ) : (
                            <>
                                <label className="block space-y-2 text-sm">
                                    <span>
                                        {t('Amount')} · {account.asset}
                                    </span>
                                    <Input
                                        inputMode="decimal"
                                        value={form.data.amount}
                                        onChange={(e) => update('amount', e.target.value)}
                                        className="min-h-12"
                                    />
                                </label>
                                {mode === 'withdrawal' && (
                                    <label className="block space-y-2 text-sm">
                                        <span>{t('Destination address')}</span>
                                        <Input
                                            value={form.data.address}
                                            onChange={(e) => update('address', e.target.value)}
                                            className="min-h-12"
                                            autoComplete="off"
                                            spellCheck={false}
                                        />
                                    </label>
                                )}
                                {rail?.minimum && mode === 'deposit' && (
                                    <p className="text-xs text-muted-foreground">
                                        {t('Minimum deposit')}: {exactAmount(rail.minimum)}{' '}
                                        {account.asset}
                                    </p>
                                )}
                                {rail?.fee !== null && mode === 'withdrawal' && rail && (
                                    <p className="text-sm">
                                        {t('Platform fee')}: {exactAmount(rail.fee)} {account.asset}
                                    </p>
                                )}
                                {mode === 'withdrawal' && review && (
                                    <div className="space-y-3 rounded-xl bg-muted p-4 text-sm">
                                        <p className="break-all">
                                            {rail?.network} · {form.data.address}
                                        </p>
                                        <p>
                                            {t('Amount')}: {form.data.amount} {account.asset}
                                        </p>
                                        <p>
                                            {t('You receive')}:{' '}
                                            {subtract(form.data.amount, rail?.fee ?? '0')}{' '}
                                            {account.asset}
                                        </p>
                                        <label className="flex min-h-11 items-center gap-3">
                                            <input
                                                type="checkbox"
                                                checked={form.data.confirmed}
                                                onChange={(e) =>
                                                    form.setData('confirmed', e.target.checked)
                                                }
                                            />
                                            {t('I checked the network, address and final amount.')}
                                        </label>
                                    </div>
                                )}
                                {mode === 'exchange' && (
                                    <p className="text-xs text-muted-foreground">
                                        {t(
                                            'The next step shows the final rate, fee and quote expiry.',
                                        )}
                                    </p>
                                )}
                                <Button
                                    className="min-h-12 w-full rounded-full"
                                    disabled={
                                        form.processing ||
                                        !form.data.amount ||
                                        (mode === 'exchange' ? !account.exchange : !rail) ||
                                        (review && !form.data.confirmed)
                                    }
                                    onClick={() => {
                                        if (mode === 'withdrawal' && !review) {
                                            setReview(true);
                                            form.setData('expected_fee', rail?.fee ?? '');
                                        } else submit();
                                    }}
                                >
                                    {t(
                                        mode === 'exchange'
                                            ? 'Get quote'
                                            : mode === 'withdrawal'
                                              ? review
                                                  ? 'Confirm withdrawal'
                                                  : 'Review withdrawal'
                                              : 'Create deposit order',
                                    )}
                                </Button>
                            </>
                        )}
                    </section>
                )}
                {Object.values({ ...serverErrors, ...form.errors }).map((message, index) => (
                    <p key={index} role="alert" className="text-sm text-destructive">
                        {errorMessage(message)}
                    </p>
                ))}
                <Sheet
                    open={picker !== null}
                    onOpenChange={(open) => {
                        if (!open) setPicker(null);
                    }}
                >
                    <SheetContent
                        closeLabel={t('Close')}
                        className="inset-x-0 top-auto bottom-0 mx-auto max-h-[80vh] w-full max-w-lg overflow-y-auto rounded-t-3xl border-0 p-6 pb-8"
                    >
                        <SheetTitle className="block pb-5 text-center font-semibold">
                            {t(picker === 'asset' ? 'Select currency' : 'Select network')}
                        </SheetTitle>
                        <SheetDescription className="sr-only">
                            {t('Only enabled currency and network combinations are available.')}
                        </SheetDescription>
                        {picker === 'asset'
                            ? overview.assets
                                  .filter((a) =>
                                      mode === 'exchange'
                                          ? a.exchange
                                          : a.rails.some((r) =>
                                                mode === 'deposit' ? r.deposit : r.withdrawal,
                                            ),
                                  )
                                  .map((a) => (
                                      <button
                                          key={a.asset}
                                          onClick={() => choose(a.asset)}
                                          className="flex min-h-16 w-full items-center gap-3 border-t"
                                      >
                                          <AssetIcon asset={a.asset} />
                                          <span className="flex-1 text-left">{a.asset}</span>
                                          {a.asset === account.asset && <Check size={20} />}
                                      </button>
                                  ))
                            : rails.map((r) => (
                                  <button
                                      key={r.code}
                                      onClick={() => {
                                          form.setData({
                                              ...form.data,
                                              rail: r.code,
                                              amount: '',
                                              address: '',
                                              confirmed: false,
                                              request_id: crypto.randomUUID(),
                                          });
                                          setPicker(null);
                                          setReview(false);
                                      }}
                                      className="flex min-h-16 w-full items-center justify-between border-t"
                                  >
                                      <span>
                                          {r.network === 'ETHEREUM'
                                              ? account.asset === 'ETH'
                                                  ? 'Ethereum'
                                                  : 'Ethereum (ERC20)'
                                              : r.network === 'TRON'
                                                ? 'TRON (TRC20)'
                                                : 'Bitcoin'}
                                      </span>
                                      {r.code === rail?.code && <Check size={20} />}
                                  </button>
                              ))}
                    </SheetContent>
                </Sheet>
            </div>
        </UserLayout>
    );
}
// Exact display preview only; the server independently computes and validates the payout.
function subtract(a: string, b: string): string {
    try {
        const units = (v: string) => {
            const [i = '', f = ''] = v.split('.');
            if (!/^\d+$/.test(i) || !/^\d{0,18}$/.test(f)) throw Error();
            return BigInt(i + f.padEnd(18, '0'));
        };
        const n = units(a) - units(b);
        if (n <= 0n) return '—';
        const s = n.toString().padStart(19, '0');
        return exactAmount(s.slice(0, -18) + '.' + s.slice(-18));
    } catch {
        return '—';
    }
}
