import { systemMoney } from '@/lib/system-money';
import {
    DropdownMenu,
    DropdownMenuTrigger,
    DropdownMenuContent,
    DropdownMenuItem,
} from '@/components/ui/dropdown-menu';
import { cardReloadBalance } from '@/lib/exact-amount';
import { cardholderChanges, holderEditFields } from '@/lib/cardholder-changes';
import { localityMode } from '@/lib/card-locality';
import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import {
    Eye,
    List,
    UserRound,
    Plus,
    Undo2,
    X,
    Lock,
    Unlock,
    LoaderCircle,
    MoreHorizontal,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { FormField } from '@/components/ui/form-field';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { SearchSelect, type SearchOption } from '@/components/ui/search-select';
import { UserCardTransactions } from '@/components/user/UserCardTransactions';
import { MoneyDisplay } from '@/components/user/UserMoney';
import {
    countryOptions,
    placeOptions,
    useCardGeography,
    type Country,
    type Region,
} from '@/hooks/useCardGeography';
import { clientI18n, errorMessage, dateTime, t, useClientTranslation } from '@/i18n';

type Order = {
    id: string;
    kind: string;
    state: string;
    amount: string;
    debit: string | null;
    arrival: string | null;
    fee: string | null;
    expiresAt: string | null;
    createdAt: string;
};
type ManagedCard = {
    balance: string | null;
    pendingOperationCount?: number;
    state?: string;
    refundLocked?: boolean;
    id: string;
    management?: string[];
    minimumReload?: string;
    syncedAt?: string | null;
};
const labels: Record<string, string> = {
    reveal: 'View card information',
    transactions: 'Card transactions',
    holder: 'Edit cardholder',
    load: 'Reload card',
    return: 'Return card balance',
    cancel: 'Cancel card',
    freeze: 'Freeze card',
    unfreeze: 'Unfreeze card',
    history: 'Card operation history',
};
const shortLabels: Record<string, string> = {
    reveal: 'View',
    holder: 'Edit',
    load: 'Reload',
    return: 'Return',
    transactions: 'Transactions',
    cancel: 'Close card',
    unfreeze: 'Unfreeze',
};
const icons = {
    reveal: Eye,
    transactions: List,
    holder: UserRound,
    load: Plus,
    return: Undo2,
    cancel: X,
    freeze: Lock,
    unfreeze: Unlock,
};
const states: Record<string, string> = {
    quoted: 'Quote ready',
    completed: 'Completed',
    declined: 'Operation declined',
    expired: 'Quote expired',
    confirming: 'Awaiting confirmation',
};

function record(value: unknown): Record<string, unknown> {
    if (!value || typeof value !== 'object' || Array.isArray(value))
        throw new Error('Awaiting confirmation');
    return value as Record<string, unknown>;
}

function operation(value: unknown): Order {
    const row = record(value);
    for (const key of ['id', 'kind', 'state', 'amount', 'createdAt']) {
        if (typeof row[key] !== 'string') throw new Error('Awaiting confirmation');
    }
    for (const key of ['debit', 'arrival', 'fee']) {
        if (row[key] !== null && (typeof row[key] !== 'string' || !/^\d+\.\d{8}$/.test(row[key])))
            throw new Error('Awaiting confirmation');
    }
    if (row.expiresAt !== null && typeof row.expiresAt !== 'string')
        throw new Error('Awaiting confirmation');
    return {
        id: row.id as string,
        kind: row.kind as string,
        state: row.state as string,
        amount: row.amount as string,
        createdAt: row.createdAt as string,
        debit: row.debit as string | null,
        arrival: row.arrival as string | null,
        fee: row.fee as string | null,
        expiresAt: row.expiresAt,
    };
}

async function post(
    cardId: string,
    data: Record<string, unknown>,
): Promise<Record<string, unknown>> {
    const token = document.cookie
        .split('; ')
        .find((value) => value.startsWith('XSRF-TOKEN='))
        ?.slice(11);
    const response = await fetch(`/cards/${cardId}/management`, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': decodeURIComponent(token ?? ''),
        },
        body: JSON.stringify(data),
    });
    const json = record((await response.json()) as unknown);
    if (!response.ok) {
        const errors = Object.values(record(json.errors ?? {})).flat();
        const detail = record(json.error ?? {});
        const message = errors[0] ?? detail.message;
        throw new Error(
            typeof message === 'string'
                ? message
                : 'Unable to complete this request. Check your information and current status.',
        );
    }
    return json;
}

function HolderChanges({
    values,
    set,
}: {
    values: Record<string, string>;
    set: (values: Record<string, string>) => void;
}) {
    const countries = useCardGeography<Country[]>('countries');
    const regions = useCardGeography<Region[]>(values.residential_country_code ?? '');
    const locale = clientI18n.language;
    function input(name: string, label: string, type = 'text') {
        return (
            <FormField id={`edit-${name}`} label={t(label)}>
                <Input
                    id={`edit-${name}`}
                    type={type}
                    value={values[name] ?? ''}
                    onChange={(event) => set({ ...values, [name]: event.target.value })}
                />
            </FormField>
        );
    }
    function select(name: string, label: string, options: SearchOption[]) {
        const mode =
            name === 'residential_state' || name === 'residential_city'
                ? localityMode(regions.data, values.residential_state ?? '', name)
                : 'select';
        function change(value: string) {
            const next = { ...values, [name]: value };
            if (name === 'residential_country_code') {
                delete next.residential_state;
                delete next.residential_city;
            }
            if (name === 'residential_state') delete next.residential_city;
            set(next);
        }
        return (
            <FormField id={`edit-${name}`} label={t(label)}>
                {mode === 'manual' ? (
                    <Input
                        id={`edit-${name}`}
                        value={values[name] ?? ''}
                        maxLength={50}
                        required
                        placeholder={t('Enter the actual location name')}
                        onChange={(event) => change(event.target.value)}
                    />
                ) : (
                    <SearchSelect
                        id={`edit-${name}`}
                        label={t(label)}
                        value={values[name] ?? ''}
                        options={options}
                        placeholder={t('Select')}
                        searchLabel={t('Search')}
                        emptyLabel={t('No results')}
                        disabled={mode === 'disabled' || !options.length}
                        onValueChange={change}
                    />
                )}
            </FormField>
        );
    }
    return (
        <div className="space-y-5">
            <p className="text-sm text-muted-foreground">
                {t(
                    'Saved information is shown below. Approved names cannot be changed. This does not replace the cardholder.',
                )}
            </p>
            <h3 className="font-semibold">{t('Cardholder')}</h3>
            <div className="grid gap-4 sm:grid-cols-2">
                {input('email', 'Email', 'email')}
                {input('date_of_birth', 'Date of birth', 'date')}
                {select(
                    'mobile_country_code',
                    'Calling code',
                    countryOptions(countries.data ?? [], locale, true),
                )}
                {input('mobile', 'Phone number', 'tel')}
                {select(
                    'nationality_country_code',
                    'Nationality',
                    countryOptions(countries.data ?? [], locale),
                )}
            </div>
            <h3 className="border-t pt-5 font-semibold">{t('Billing address')}</h3>
            {(countries.failed || regions.failed) && (
                <div role="alert" className="text-sm text-danger">
                    {t('Location options could not be loaded.')}{' '}
                    <button
                        type="button"
                        className="underline"
                        onClick={() => {
                            countries.retry();
                            regions.retry();
                        }}
                    >
                        {t('Try again')}
                    </button>
                </div>
            )}
            {(localityMode(regions.data, values.residential_state ?? '', 'residential_state') ===
                'manual' ||
                localityMode(regions.data, values.residential_state ?? '', 'residential_city') ===
                    'manual') && (
                <p className="text-sm text-muted-foreground">
                    {t('Location data is incomplete here. Enter the actual state or city name.')}
                </p>
            )}
            <div className="grid gap-4 sm:grid-cols-2">
                {select(
                    'residential_country_code',
                    'Country / region',
                    countryOptions(countries.data ?? [], locale),
                )}
                {select(
                    'residential_state',
                    'State / province',
                    placeOptions(regions.data ?? [], locale),
                )}
                {select(
                    'residential_city',
                    'City',
                    placeOptions(
                        regions.data?.find((item) => item.value === values.residential_state)
                            ?.cities ?? [],
                        locale,
                    ),
                )}
                {input('residential_postal_code', 'Postal code')}
            </div>
            {input('residential_address', 'Address')}
        </div>
    );
}

export function CardManagementActions({
    card,
    availableBalance,
    walletAsset,
}: {
    card: ManagedCard;
    availableBalance: string | null;
    walletAsset: string | null;
}) {
    useClientTranslation();
    const [active, setActive] = useState<string | null>(null);
    const [password, setPassword] = useState('');
    const [amount, setAmount] = useState('');
    const [fields, setFields] = useState<Record<string, string>>({});
    const [originalFields, setOriginalFields] = useState<Record<string, string>>({});
    const [holderLoaded, setHolderLoaded] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [cardDetails, setCardDetails] = useState<{ pan: string; cvv: string } | null>(null);
    const [copyNotice, setCopyNotice] = useState('');
    const [order, setOrder] = useState<Order | null>(null);
    const [history, setHistory] = useState<Order[]>([]);
    const [confirmed, setConfirmed] = useState(false);
    const requestId = useRef(crypto.randomUUID());
    const generation = useRef(0);
    const visible = useRef(false);
    useEffect(() => {
        const hide = () => {
            if (document.hidden) {
                visible.current = false;
                generation.current++;
                setCardDetails(null);
                setCopyNotice('');
                setPassword('');
                setFields({});
                setOriginalFields({});
                setHolderLoaded(false);
                setActive((value) => (value === 'holder' ? null : value));
            }
        };
        const timer = cardDetails
            ? window.setTimeout(() => {
                  setCardDetails(null);
                  setCopyNotice('');
              }, 30000)
            : undefined;
        document.addEventListener('visibilitychange', hide);
        return () => {
            window.clearTimeout(timer);
            document.removeEventListener('visibilitychange', hide);
        };
    }, [cardDetails]);
    useEffect(
        () => () => {
            generation.current++;
            visible.current = false;
        },
        [],
    );
    function close() {
        generation.current++;
        visible.current = false;
        setActive(null);
        setPassword('');
        setCardDetails(null);
        setCopyNotice('');
        setFields({});
        setOriginalFields({});
        setHolderLoaded(false);
        setConfirmed(false);
        setOrder(null);
        setError('');
        router.reload();
    }
    function open(action: string) {
        generation.current++;
        visible.current = true;
        setActive(action);
        setPassword('');
        setCardDetails(null);
        setCopyNotice('');
        setOrder(null);
        setError('');
        setAmount('');
        setFields({});
        setOriginalFields({});
        setHolderLoaded(false);
        setBusy(false);
        setConfirmed(false);
        requestId.current = crypto.randomUUID();
        if (action === 'holder') void loadHolder(generation.current);
        if (action === 'history') void run('history');
    }
    async function loadHolder(current: number) {
        setBusy(true);
        try {
            const result = await post(card.id, { action: 'holder_details' });
            if (current !== generation.current || !visible.current) return;
            const saved = record(result.fields);
            const values = Object.fromEntries(
                holderEditFields.map((key) => {
                    if (saved[key] !== undefined && typeof saved[key] !== 'string')
                        throw new Error(
                            'Cardholder information could not be loaded. Please close and try again.',
                        );
                    return [key, saved[key] ?? ''];
                }),
            ) as Record<string, string>;
            setFields(values);
            setOriginalFields(values);
            setHolderLoaded(true);
        } catch {
            if (current === generation.current)
                setError(
                    t('Cardholder information could not be loaded. Please close and try again.'),
                );
        } finally {
            if (current === generation.current) setBusy(false);
        }
    }
    async function run(action: string, target?: Order) {
        if (busy) return;
        if (action === 'holder' && !holderLoaded) return;
        const changes = action === 'holder' ? cardholderChanges(fields, originalFields) : {};
        if (action === 'holder' && Object.keys(changes).length === 0) {
            setError(t('Enter at least one change.'));
            return;
        }
        const current = generation.current;
        setBusy(true);
        setError('');
        const input: Record<string, unknown> = { action };
        if (action === 'confirm' || action === 'sync') input.order_id = target?.id ?? order?.id;
        if (['quote', 'return', 'freeze', 'unfreeze', 'cancel', 'holder'].includes(action))
            input.request_id = requestId.current;
        if (action === 'quote' || action === 'return') input.amount = amount;
        if (!['quote', 'confirm', 'sync', 'refresh', 'history'].includes(action))
            input.current_password = password;
        if (['return', 'freeze', 'unfreeze', 'cancel', 'holder'].includes(action))
            input.confirmed = confirmed;
        if (action === 'holder') Object.assign(input, changes);
        try {
            let result = await post(card.id, input);
            if (current !== generation.current || !visible.current) return;
            if (action === 'quote' && result.state === 'quoted') {
                const quoted = operation(result);
                // Keep the existing order available for status-only recovery if the response is lost.
                setOrder({ ...quoted, state: 'confirming' });
                result = await post(card.id, { action: 'confirm', order_id: quoted.id });
                if (current !== generation.current || !visible.current) return;
            }
            if (action === 'reveal') {
                if (
                    typeof result.pan !== 'string' ||
                    !/^[0-9]{12,19}$/.test(result.pan) ||
                    typeof result.cvv !== 'string' ||
                    !/^[0-9]{3,4}$/.test(result.cvv)
                )
                    throw new Error('Awaiting confirmation');
                setCardDetails({ pan: result.pan, cvv: result.cvv });
            } else if (action === 'history') {
                if (!Array.isArray(result.orders)) throw new Error('Awaiting confirmation');
                setHistory(result.orders.map(operation));
            } else if (action === 'refresh') {
                router.reload();
            } else {
                if (action === 'sync' && target) {
                    setHistory((items) =>
                        items.map((item) => (item.id === target.id ? operation(result) : item)),
                    );
                } else setOrder(operation(result));
                if (result.state !== 'quoted') router.reload();
            }
        } catch (reason) {
            if (current === generation.current)
                setError(
                    errorMessage(reason instanceof Error ? reason.message : '') ??
                        t('Awaiting confirmation'),
                );
        } finally {
            setPassword('');
            setBusy(false);
        }
    }
    async function copyDetails(part: 'pan' | 'cvv' | 'all') {
        if (!cardDetails) return;
        const current = generation.current;
        setCopyNotice('');
        try {
            await navigator.clipboard.writeText(
                part === 'all'
                    ? `${t('Card number')}: ${cardDetails.pan}\nCVV: ${cardDetails.cvv}`
                    : cardDetails[part],
            );
            if (current === generation.current && visible.current)
                setCopyNotice(t('Card information copied'));
        } catch {
            if (current === generation.current && visible.current)
                setCopyNotice(t('Copy failed. Please select and copy manually.'));
        }
    }
    const capabilities = card.management ?? [];
    const projectedCardBalance = cardReloadBalance(amount, card.balance);
    const parsedAmount = /^\d+(?:\.\d{1,2})?$/.test(amount)
        ? BigInt(amount.split('.')[0]!) * 100000000n +
          BigInt((amount.split('.')[1] ?? '').padEnd(8, '0'))
        : null;
    const toMinor = (value: string) => {
        const negative = value.startsWith('-');
        const [whole = '0', fraction = ''] = (negative ? value.slice(1) : value).split('.');
        return (
            (negative ? -1n : 1n) * (BigInt(whole) * 100000000n + BigInt(fraction.padEnd(8, '0')))
        );
    };
    const amountValid =
        parsedAmount !== null &&
        parsedAmount > 0n &&
        (active !== 'load' ||
            (availableBalance !== null &&
                parsedAmount <= toMinor(availableBalance) &&
                parsedAmount >= toMinor(card.minimumReload ?? '0'))) &&
        (active !== 'return' || (card.balance !== null && parsedAmount <= toMinor(card.balance)));
    const actions = [
        'reveal',
        card.state === 'Frozen' ? 'unfreeze' : 'load',
        'return',
        'transactions',
    ];
    const moreActions = ['unfreeze', 'holder'].filter(
        (action) => capabilities.includes(action) && !actions.includes(action),
    );
    const visibleActions = card.refundLocked
        ? ['transactions']
        : actions.filter((action) => action !== 'holder');
    const terminal = order && ['completed', 'declined', 'expired'].includes(order.state);
    const needsPassword =
        active &&
        !['load', 'transactions', 'history', 'refresh'].includes(active) &&
        !(active === 'load' && !order) &&
        !terminal &&
        order?.state !== 'confirming';
    const needsConfirmation = needsPassword && active !== 'reveal';
    return (
        <div className="user-card-controls w-full space-y-2">
            <div
                className={card.refundLocked ? 'grid grid-cols-1 gap-1' : 'grid grid-cols-5 gap-1'}
                data-card-actions
            >
                {visibleActions.map((action) => {
                    const Icon = icons[action as keyof typeof icons];
                    return Icon ? (
                        <button
                            key={action}
                            type="button"
                            disabled={!capabilities.includes(action)}
                            aria-label={t(labels[action] ?? 'Card management')}
                            className="flex min-h-14 min-w-0 flex-col items-center justify-center gap-1.5 rounded-lg px-0.5 py-2 text-[11px] sm:text-xs hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-transparent"
                            onClick={() => {
                                if (capabilities.includes(action)) open(action);
                            }}
                        >
                            <Icon className="size-4 shrink-0" aria-hidden="true" />
                            <span className="w-full truncate text-center">
                                {t(shortLabels[action] ?? 'Card management')}
                            </span>
                        </button>
                    ) : null;
                })}
                {!card.refundLocked && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <button
                                type="button"
                                className="flex min-h-14 flex-col items-center justify-center gap-1.5 rounded-lg text-xs hover:bg-muted"
                                aria-label={t('More')}
                            >
                                <MoreHorizontal className="size-4" />
                                <span>{t('More')}</span>
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem onSelect={() => open('history')}>
                                {t('Card operation history')}
                            </DropdownMenuItem>
                            {moreActions.map((action) => (
                                <DropdownMenuItem
                                    key={action}
                                    onSelect={() => open(action)}
                                >
                                    {t(labels[action] ?? 'Card management')}
                                </DropdownMenuItem>
                            ))}
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
            </div>
            {!card.refundLocked && (card.pendingOperationCount ?? 0) > 0 && (
                <Button
                    type="button"
                    variant="secondary"
                    className="w-full"
                    onClick={() => open('history')}
                >
                    {t('Pending card operations: {{count}}', {
                        count: card.pendingOperationCount ?? 0,
                    })}
                </Button>
            )}
            {card.refundLocked && (
                <p className="text-center text-xs text-muted-foreground">
                    {t(
                        'Cards are locked for the security deposit refund. Only transaction history is available.',
                    )}
                </p>
            )}
            <Dialog
                open={active !== null}
                onOpenChange={(value) => {
                    if (!value) close();
                }}
            >
                <DialogContent
                    className={
                        active === 'history'
                            ? 'max-h-[88dvh] overflow-hidden sm:max-w-lg'
                            : 'max-h-[88dvh] overflow-y-auto sm:max-w-xl'
                    }
                    closeLabel={t('Close')}
                    aria-describedby={undefined}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {t(
                                active === 'history'
                                    ? 'Card operation history'
                                    : (labels[active ?? ''] ?? 'Card management'),
                            )}
                        </DialogTitle>
                    </DialogHeader>
                    {active === 'transactions' ? (
                        <UserCardTransactions cardIds={[card.id]} singleCard />
                    ) : (
                        <form
                            className={
                                active === 'history'
                                    ? 'max-h-[calc(88dvh-7rem)] overflow-y-auto pr-2'
                                    : 'space-y-5'
                            }
                            onSubmit={(event) => {
                                event.preventDefault();
                                if (
                                    active &&
                                    (order || !['load', 'return'].includes(active) || amountValid)
                                )
                                    void run(
                                        active === 'load'
                                            ? order?.state === 'quoted'
                                                ? 'confirm'
                                                : 'quote'
                                            : active,
                                    );
                            }}
                        >
                            {active === 'history' ? (
                                <>
                                    {!busy && !history.length && !error && (
                                        <p className="py-4 text-sm text-muted-foreground">
                                            {t('No records yet.')}
                                        </p>
                                    )}
                                    <div className="divide-y">
                                        {history.map((item) => (
                                            <div
                                                key={item.id}
                                                className="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-x-5 py-3.5 text-sm"
                                            >
                                                <div className="min-w-0 space-y-1.5">
                                                    <p className="font-medium">
                                                        {t(
                                                            labels[
                                                                item.kind === 'holder_update'
                                                                    ? 'holder'
                                                                    : item.kind === 'cancel_return'
                                                                      ? 'return'
                                                                      : item.kind
                                                            ] ?? 'Card management',
                                                        )}
                                                    </p>
                                                    <p className="text-xs leading-5 text-muted-foreground">
                                                        {dateTime(item.createdAt)}
                                                    </p>
                                                </div>
                                                <div className="space-y-1.5 text-right">
                                                    {item.arrival && (
                                                        <p
                                                            className="font-semibold tabular-nums"
                                                            aria-label={t('Card operation amount')}
                                                        >
                                                            {systemMoney(item.arrival)}
                                                        </p>
                                                    )}
                                                    <p
                                                        className={`text-xs leading-5 ${item.state === 'completed' ? 'text-emerald-700' : 'text-muted-foreground'}`}
                                                    >
                                                        {t(
                                                            states[item.state] ??
                                                                'Awaiting confirmation',
                                                        )}
                                                    </p>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </>
                            ) : (
                                <>
                                    {active === 'holder' &&
                                        !order &&
                                        (holderLoaded ? (
                                            <HolderChanges values={fields} set={setFields} />
                                        ) : (
                                            busy && (
                                                <p role="status">
                                                    {t('Loading cardholder information…')}
                                                </p>
                                            )
                                        ))}
                                    {active === 'load' && (
                                        <dl
                                            className="rounded-lg bg-muted px-4 py-3"
                                            data-reload-wallet-balance
                                        >
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <dt className="text-sm text-muted-foreground">
                                                    {t('Available Wallet balance')}
                                                </dt>
                                                <dd className="font-semibold">
                                                    {availableBalance !== null && walletAsset ? (
                                                        <MoneyDisplay
                                                            amount={availableBalance}
                                                            asset={walletAsset}
                                                            compact
                                                        />
                                                    ) : (
                                                        t('Unavailable')
                                                    )}
                                                </dd>
                                            </div>
                                        </dl>
                                    )}
                                    {(active === 'load' || active === 'return') && !order && (
                                        <FormField
                                            id="card-operation-amount"
                                            label={t('Card operation amount')}
                                            description={
                                                active === 'load'
                                                    ? t('Minimum reload: {{amount}}', {
                                                          amount: systemMoney(
                                                              card.minimumReload ?? '0',
                                                          ),
                                                      })
                                                    : undefined
                                            }
                                        >
                                            <Input
                                                id="card-operation-amount"
                                                inputMode="decimal"
                                                required
                                                pattern="[0-9]+(\.[0-9]{1,2})?"
                                                value={amount}
                                                onChange={(event) => setAmount(event.target.value)}
                                            />
                                        </FormField>
                                    )}
                                    {active === 'load' && !order && (
                                        <div
                                            className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-muted px-4 py-3"
                                            aria-live="polite"
                                        >
                                            <span className="text-sm text-muted-foreground">
                                                {t('Estimated card balance after reload')}
                                            </span>
                                            <span className="font-semibold tabular-nums">
                                                {projectedCardBalance === null
                                                    ? '—'
                                                    : systemMoney(projectedCardBalance)}
                                            </span>
                                        </div>
                                    )}
                                    {active === 'cancel' && !order && (
                                        <p className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900">
                                            {t(
                                                'Card cancellation is permanent. After completion, remaining funds return to your wallet minus applicable fees. Your security deposit requires a separate refund request.',
                                            )}
                                        </p>
                                    )}
                                    {order && (
                                        <div
                                            className="space-y-2 rounded-xl border p-4 text-sm"
                                            role="status"
                                        >
                                            <p className="font-semibold">
                                                {t(states[order.state] ?? 'Awaiting confirmation')}
                                            </p>
                                            {order.state === 'quoted' && (
                                                <>
                                                    <p>
                                                        {t('Wallet debit')}:{' '}
                                                        {order.debit === null
                                                            ? '—'
                                                            : systemMoney(order.debit)}
                                                    </p>
                                                    <p>
                                                        {t('Card receives')}:{' '}
                                                        {order.arrival === null
                                                            ? '—'
                                                            : systemMoney(order.arrival)}
                                                    </p>
                                                    <p>
                                                        {t('Fee')}:{' '}
                                                        {order.fee === null
                                                            ? '—'
                                                            : systemMoney(order.fee)}
                                                    </p>
                                                    <p>
                                                        {t(
                                                            'The quote expires shortly. Confirm only if you accept the displayed amounts.',
                                                        )}
                                                    </p>
                                                </>
                                            )}
                                            {order.state === 'confirming' && (
                                                <Button
                                                    type="button"
                                                    variant="secondary"
                                                    disabled={busy}
                                                    onClick={() => void run('sync')}
                                                >
                                                    {t('Check result')}
                                                </Button>
                                            )}
                                        </div>
                                    )}
                                    {cardDetails && (
                                        <div className="space-y-4 rounded-xl border p-4 sm:p-5">
                                            <div className="flex flex-wrap items-center justify-between gap-3">
                                                <div className="min-w-0">
                                                    <p className="text-sm text-muted-foreground">
                                                        {t('Card number')}
                                                    </p>
                                                    <p
                                                        className="break-all font-mono text-lg"
                                                        aria-label={t('Card number')}
                                                    >
                                                        {cardDetails.pan.replace(
                                                            /(.{4})(?=.)/g,
                                                            '$1 ',
                                                        )}
                                                    </p>
                                                </div>
                                                <Button
                                                    type="button"
                                                    variant="secondary"
                                                    onClick={() => void copyDetails('pan')}
                                                >
                                                    {t('Copy card number')}
                                                </Button>
                                            </div>
                                            <div className="flex items-center justify-between gap-3">
                                                <div>
                                                    <p className="text-sm text-muted-foreground">
                                                        CVV
                                                    </p>
                                                    <p
                                                        className="font-mono text-xl"
                                                        aria-label="CVV"
                                                    >
                                                        {cardDetails.cvv}
                                                    </p>
                                                </div>
                                                <Button
                                                    type="button"
                                                    variant="secondary"
                                                    onClick={() => void copyDetails('cvv')}
                                                >
                                                    {t('Copy CVV')}
                                                </Button>
                                            </div>
                                            <Button
                                                type="button"
                                                className="w-full"
                                                onClick={() => void copyDetails('all')}
                                            >
                                                {t('Copy all card information')}
                                            </Button>
                                            {copyNotice && (
                                                <p role="status" className="text-sm">
                                                    {copyNotice}
                                                </p>
                                            )}
                                            <p className="text-xs text-muted-foreground">
                                                {t(
                                                    'Card information hides after 30 seconds or when you leave this page. Copied information remains in your clipboard.',
                                                )}
                                            </p>
                                        </div>
                                    )}
                                    {needsPassword && !cardDetails && (
                                        <FormField
                                            id="card-current-password"
                                            label={t('Current password')}
                                        >
                                            <Input
                                                id="card-current-password"
                                                type="password"
                                                autoComplete="current-password"
                                                required
                                                value={password}
                                                onChange={(event) =>
                                                    setPassword(event.target.value)
                                                }
                                            />
                                        </FormField>
                                    )}
                                    {needsConfirmation && (
                                        <label className="flex items-start gap-3 text-sm">
                                            <input
                                                className="mt-1"
                                                type="checkbox"
                                                required
                                                checked={confirmed}
                                                onChange={(event) =>
                                                    setConfirmed(event.target.checked)
                                                }
                                            />
                                            {t('I understand and confirm this card operation.')}
                                        </label>
                                    )}
                                    {!terminal && order?.state !== 'confirming' && !cardDetails && (
                                        <Button
                                            className="w-full"
                                            disabled={
                                                busy ||
                                                (active === 'holder' && !holderLoaded) ||
                                                (!order &&
                                                    ['load', 'return'].includes(active ?? '') &&
                                                    !amountValid)
                                            }
                                        >
                                            {busy && (
                                                <LoaderCircle className="size-4 animate-spin" />
                                            )}
                                            {t(active === 'load' ? 'Reload' : 'Confirm')}
                                        </Button>
                                    )}
                                </>
                            )}
                            {error && (
                                <p role="alert" className="text-sm text-danger">
                                    {error}
                                </p>
                            )}
                        </form>
                    )}
                </DialogContent>
            </Dialog>
        </div>
    );
}
