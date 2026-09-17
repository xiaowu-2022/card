import { displayMoney } from '@/lib/exact-amount';
import { t, useClientTranslation, errorMessage } from '@/i18n';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { CheckCircle2, CreditCard, LoaderCircle, Plus, RefreshCw } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { MoneyDisplay } from '@/components/user/UserMoney';
import { UserEmptyState } from '@/components/user/UserEmptyState';
import { UserStatusBanner } from '@/components/user/UserStatusBanner';
import { IdentityVerificationDialog } from '@/components/user/IdentityVerificationDialog';
import { UserCardTransactions } from '@/components/user/UserCardTransactions';
import { CardManagementActions } from '@/components/user/CardManagementActions';
import { CardholderMaterialsForm } from '@/components/user/CardholderMaterialsForm';
import {
    loadCardholderApplicationFields,
    useCardholderMaterialsForm,
} from '@/hooks/useCardholderMaterialsForm';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from '@/components/ui/dialog';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { userThemeStyle } from '@/lib/user-theme';
import { cardDisplayName } from '@/lib/card-display-name';
import { UserLayout } from '@/layouts/UserLayout';
import type { SharedProps } from '@/types/global';

type Product = {
    id: string;
    name: string;
    cardType: string;
    cardCurrency: string;
    openingFee: string;
    minimumInitialLoad: string;
    minimumRequiredBalance: string;
    maxCardsPerUser: number;
    readyForSetup: boolean;
    guidance: string;
};
type Cardholder = {
    id: string | null;
    requestId: string | null;
    productId: string | null;
    canSync: boolean;
    state: 'setup' | 'submitting' | 'ready' | 'action_required' | 'not_available' | 'unknown';
    safeReason: string | null;
    submittedAt: string | null;
    syncedAt: string | null;
};
type IssueOrder = {
    id: string;
    productName: string;
    openingFee: string;
    initialLoadAmount: string;
    state: 'creating' | 'unknown' | 'created' | 'failed';
    requestedAt: string;
};
type UserCard = {
    state?: string;
    pendingOperationCount?: number;
    refundLocked?: boolean;
    id: string;
    productName: string;
    maskedPan: string;
    last4: string;
    expiry: string | null;
    currency: string;
    balance: string | null;
    management?: string[];
    minimumReload?: string;
    syncedAt?: string | null;
};
type Props = {
    refundPending?: boolean;
    products: Product[];
    providerAvailable: boolean;
    kycApproved: boolean;
    availableBalance: string | null;
    walletAsset: string | null;
    cardholder: Cardholder;
    issueOrders: IssueOrder[];
    cards: UserCard[];
    demo?: boolean;
};

function minorUnits(value: string): bigint | null {
    const match = /^(\d+)(?:\.(\d{0,8}))?$/.exec(value);
    if (!match) return null;
    const fraction = (match[2] ?? '').padEnd(8, '0');
    return BigInt(match[1]!) * 100000000n + BigInt(fraction || '0');
}

function moneyFromMinor(value: bigint): string {
    const integer = value / 100000000n;
    const fraction = (value % 100000000n).toString().padStart(8, '0');
    return `${integer}.${fraction}`;
}

function ProductIssue({
    product,
    availableBalance,
    open,
    onClose,
    onSelectProduct,
    restoreFocus,
    application,
}: {
    product: Product;
    availableBalance: string | null;
    open: boolean;
    onClose: () => void;
    onSelectProduct: (id: string) => void;
    restoreFocus: boolean;
    application?: Cardholder;
}) {
    useClientTranslation();
    const { tenant } = usePage<SharedProps>().props;
    const [reviewing, setReviewing] = useState(false);
    const titleRef = useRef<HTMLHeadingElement>(null);
    const materialsForm = useCardholderMaterialsForm(product.id);
    const [editingMaterials, setEditingMaterials] = useState(false);
    const [loadingMaterials, setLoadingMaterials] = useState(false);
    const [materialReadError, setMaterialReadError] = useState('');
    const materialReadGeneration = useRef(0);
    const editingReady = editingMaterials && application?.state === 'ready';
    const ready = application?.state === 'ready' && !editingReady;
    const needsMaterials = !application || application.state === 'action_required' || editingReady;
    function closeApplication() {
        materialReadGeneration.current++;
        setLoadingMaterials(false);
        setMaterialReadError('');
        if (editingMaterials) materialsForm.reset();
        setEditingMaterials(false);
        onClose();
    }
    useEffect(
        () => () => {
            materialReadGeneration.current++;
        },
        [],
    );
    useEffect(() => {
        const hide = () => {
            if (document.hidden && editingMaterials && !materialsForm.processing)
                closeApplication();
        };
        document.addEventListener('visibilitychange', hide);
        return () => document.removeEventListener('visibilitychange', hide);
    });
    async function editMaterials() {
        if (!application?.id || loadingMaterials) return;
        const generation = ++materialReadGeneration.current;
        setEditingMaterials(true);
        setLoadingMaterials(true);
        setMaterialReadError('');
        try {
            const fields = await loadCardholderApplicationFields(application.id);
            if (generation !== materialReadGeneration.current) return;
            materialsForm.setData((data) => ({ ...data, ...fields, front: null, back: null }));
            materialsForm.clearErrors();
        } catch {
            if (generation === materialReadGeneration.current) {
                setEditingMaterials(false);
                setMaterialReadError(
                    t('Cardholder information could not be loaded. Please close and try again.'),
                );
            }
        } finally {
            if (generation === materialReadGeneration.current) setLoadingMaterials(false);
        }
    }
    const form = useForm({
        request_id: crypto.randomUUID(),
        card_product_id: product.id,
        initial_load_amount: product.minimumInitialLoad.replace(/0+$/, '').replace(/\.$/, ''),
    });
    const calculation = useMemo(() => {
        const opening = minorUnits(product.openingFee);
        const initial = minorUnits(form.data.initial_load_amount);
        const available = availableBalance === null ? null : minorUnits(availableBalance);
        const minimum = minorUnits(product.minimumInitialLoad);
        if (opening === null || initial === null || minimum === null) return null;
        const total = opening + initial;
        return {
            total: moneyFromMinor(total),
            enough: available !== null && available >= total,
            meetsMinimum: initial >= minimum,
        };
    }, [
        availableBalance,
        form.data.initial_load_amount,
        product.minimumInitialLoad,
        product.openingFee,
    ]);
    const canSubmit =
        ready && product.readyForSetup && Boolean(calculation?.enough && calculation.meetsMinimum);
    const formError = (form.errors as Record<string, string>).form;

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next && !form.processing && !materialsForm.processing && !reviewing)
                    closeApplication();
            }}
        >
            <DialogContent
                className="user-theme user-card-dialog flex max-h-[calc(100dvh-2rem)] max-w-2xl flex-col overflow-clip p-0"
                style={userThemeStyle(tenant?.branding.primaryColor)}
                closeLabel={t('Close')}
                closeDisabled={form.processing || materialsForm.processing || reviewing}
                onOpenAutoFocus={(event) => {
                    event.preventDefault();
                    titleRef.current?.focus();
                }}
                onCloseAutoFocus={(event) => {
                    // The trigger lives in My Cards, outside the per-product form owner.
                    event.preventDefault();
                    if (restoreFocus) document.getElementById('open-card-application')?.focus();
                }}
            >
                <DialogHeader className="mb-0 shrink-0 px-5 pt-6 pb-4 pr-16 sm:px-7 sm:pr-16">
                    <DialogTitle
                        ref={titleRef}
                        tabIndex={-1}
                        className="flex items-center gap-2 outline-none"
                    >
                        <CheckCircle2 className="size-5 text-emerald-600" />
                        {ready ? t('Confirm card opening') : t('Apply for a card')}
                    </DialogTitle>
                    <DialogDescription className="sr-only">
                        {t('Submit materials for this card, then review and confirm opening.')}
                    </DialogDescription>
                </DialogHeader>
                <div className="user-card-dialog-body min-h-0 overflow-y-auto overscroll-contain px-5 pb-6 sm:px-7">
                    <ol
                        className="mb-6 grid grid-cols-2 gap-3 text-sm"
                        aria-label={t('Card application steps')}
                    >
                        <li
                            className={ready ? 'text-muted-foreground' : 'font-semibold'}
                            aria-current={!ready ? 'step' : undefined}
                        >
                            {t('1. Cardholder materials')}
                        </li>
                        <li
                            className={ready ? 'font-semibold' : 'text-muted-foreground'}
                            aria-current={ready ? 'step' : undefined}
                        >
                            {t('2. Review and open')}
                        </li>
                    </ol>
                    {materialReadError && (
                        <p role="alert" className="mb-4 text-sm text-danger">
                            {materialReadError}
                        </p>
                    )}
                    {needsMaterials ? (
                        loadingMaterials ? (
                            <p role="status">{t('Loading cardholder information…')}</p>
                        ) : (
                            <>
                                {editingReady && (
                                    <p className="mb-4 text-sm text-muted-foreground">
                                        {t(
                                            'Update your details and select the identity documents again. Continue opening after the update is completed.',
                                        )}
                                    </p>
                                )}
                                <CardholderMaterialsForm
                                    form={materialsForm}
                                    updateRequestId={application?.requestId ?? undefined}
                                    onAdded={() => {
                                        setEditingMaterials(false);
                                        onSelectProduct(product.id);
                                    }}
                                />
                            </>
                        )
                    ) : !ready ? (
                        <section className="space-y-4">
                            <h2 className="text-xl font-semibold">
                                {t('Confirming cardholder addition')}
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                {t(
                                    'The cardholder addition could not be confirmed. Do not submit it again.',
                                )}
                            </p>
                            <Button
                                variant="secondary"
                                disabled={!application?.canSync}
                                onClick={() =>
                                    router.post(`/cards/cardholder/${application?.id}/sync`)
                                }
                            >
                                {t('Refresh status')}
                            </Button>
                        </section>
                    ) : (
                        <>
                            <Button
                                variant="secondary"
                                className="mb-4"
                                disabled={form.processing || reviewing}
                                onClick={() => void editMaterials()}
                            >
                                {t('Edit card application information')}
                            </Button>
                            <p className="mb-5 text-sm text-emerald-700">
                                {t(
                                    'Cardholder added. Confirm the amount to continue opening this card.',
                                )}
                            </p>
                            <Card className="user-card-product overflow-hidden border-0">
                                <CardContent className="p-0">
                                    <div className="bg-slate-950 p-6 text-white sm:p-7">
                                        <div className="flex items-start justify-between gap-4">
                                            <div>
                                                <p className="text-xs font-semibold uppercase tracking-[0.18em] text-white/60">
                                                    {t('Mastercard U Card')}
                                                </p>
                                                <h2 className="mt-2 text-2xl font-semibold">
                                                    {cardDisplayName(product.name)}
                                                </h2>
                                            </div>
                                            <CreditCard className="size-7 text-white/75" />
                                        </div>
                                        <p className="mt-10 text-sm text-white/70">
                                            {t('Mastercard U Card · balance in {{currency}}', {
                                                currency: product.cardCurrency,
                                            })}
                                        </p>
                                    </div>
                                    <div className="space-y-5 p-5 sm:p-6">
                                        <dl className="divide-y border-y text-sm">
                                            <div className="flex justify-between gap-4 py-3">
                                                <dt className="text-muted-foreground">
                                                    {t('Opening fee')}
                                                </dt>
                                                <dd className="font-semibold">
                                                    {'$ '}
                                                    <MoneyDisplay
                                                        amount={product.openingFee}
                                                        asset="USDT"
                                                        compact
                                                        hideSymbol
                                                    />
                                                </dd>
                                            </div>
                                            <div className="flex justify-between gap-4 py-3">
                                                <dt className="text-muted-foreground">
                                                    {t('Minimum initial balance')}
                                                </dt>
                                                <dd className="font-semibold">
                                                    <MoneyDisplay
                                                        amount={product.minimumInitialLoad}
                                                        asset="USDT"
                                                        compact
                                                    />
                                                </dd>
                                            </div>
                                            <div className="flex justify-between gap-4 py-3">
                                                <dt className="text-muted-foreground">
                                                    {t('Available Wallet balance')}
                                                </dt>
                                                <dd className="font-semibold">
                                                    {availableBalance ? (
                                                        <MoneyDisplay
                                                            amount={availableBalance}
                                                            asset="USDT"
                                                            compact
                                                        />
                                                    ) : (
                                                        t('Unavailable')
                                                    )}
                                                </dd>
                                            </div>
                                        </dl>
                                        <FormField
                                            id={`initial-${product.id}`}
                                            label={t('Initial card balance')}
                                            error={errorMessage(form.errors.initial_load_amount)}
                                            description={t(
                                                'The card receives the same amount in {{value1}}.',
                                                {
                                                    value1: product.cardCurrency,
                                                },
                                            )}
                                        >
                                            <Input
                                                id={`initial-${product.id}`}
                                                inputMode="decimal"
                                                disabled={form.processing}
                                                value={form.data.initial_load_amount}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'initial_load_amount',
                                                        event.target.value,
                                                    )
                                                }
                                            />
                                        </FormField>
                                        {calculation ? (
                                            <div className="flex items-center justify-between rounded-xl bg-muted p-4 text-sm">
                                                <span>{t('Total from Wallet')}</span>
                                                <strong>
                                                    <MoneyDisplay
                                                        amount={calculation.total}
                                                        asset="USDT"
                                                        compact
                                                    />
                                                </strong>
                                            </div>
                                        ) : null}
                                        {formError && (
                                            <p role="alert" className="text-sm text-destructive">
                                                {errorMessage(formError)}
                                            </p>
                                        )}
                                        {!calculation?.meetsMinimum ? (
                                            <p className="text-sm text-destructive">
                                                {t(
                                                    'Initial balance must be at least ${{amount}}.',
                                                    {
                                                        amount: displayMoney(
                                                            product.minimumInitialLoad,
                                                        ),
                                                    },
                                                )}
                                            </p>
                                        ) : null}
                                        {calculation && !calculation.enough ? (
                                            <UserStatusBanner
                                                tone="warning"
                                                title={t(
                                                    'You need ${{value1}} to open this card.',
                                                    {
                                                        value1: displayMoney(calculation.total),
                                                    },
                                                )}
                                                description={t('Available: ${{value1}}', {
                                                    value1: displayMoney(
                                                        availableBalance ?? '0.00000000',
                                                    ),
                                                })}
                                            />
                                        ) : null}
                                        {calculation && !calculation.enough ? (
                                            <Button asChild className="w-full">
                                                <Link href="/wallet/top-up">
                                                    {t('Top up wallet')}
                                                </Link>
                                            </Button>
                                        ) : (
                                            <Button
                                                className="w-full"
                                                disabled={!canSubmit || form.processing}
                                                onClick={() => setReviewing(true)}
                                            >
                                                {form.processing
                                                    ? t('Submitting…')
                                                    : t('Open card')}
                                            </Button>
                                        )}
                                    </div>
                                </CardContent>
                                <AlertDialog open={reviewing} onOpenChange={setReviewing}>
                                    <AlertDialogContent>
                                        <div className="space-y-2">
                                            <AlertDialogTitle>
                                                {t('Confirm card opening')}
                                            </AlertDialogTitle>
                                            <AlertDialogDescription>
                                                {t(
                                                    'An opening fee of ${{fee}} and initial balance of ${{amount}} will be reserved separately while your card is created. Do not create another request while it is pending.',
                                                    {
                                                        fee: displayMoney(product.openingFee),
                                                        amount: displayMoney(
                                                            form.data.initial_load_amount,
                                                        ),
                                                    },
                                                )}
                                            </AlertDialogDescription>
                                        </div>
                                        <div className="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                                            <AlertDialogCancel className="inline-flex min-h-10 items-center justify-center rounded-lg border bg-surface px-4 text-sm font-semibold">
                                                {t('Cancel')}
                                            </AlertDialogCancel>
                                            <AlertDialogAction
                                                className="inline-flex min-h-10 items-center justify-center rounded-lg bg-primary px-4 text-sm font-semibold text-primary-foreground"
                                                disabled={!canSubmit || form.processing}
                                                onClick={() => {
                                                    if (!canSubmit || form.processing) return;
                                                    form.transform((data) => ({
                                                        ...data,
                                                        cardholder_application_id: application?.id,
                                                    }));
                                                    form.post('/cards/issues', {
                                                        onSuccess: () => {
                                                            setReviewing(false);
                                                            onClose();
                                                            // A new intent is allowed only after the server accepted this one.
                                                            form.setData(
                                                                'request_id',
                                                                crypto.randomUUID(),
                                                            );
                                                        },
                                                    });
                                                }}
                                            >
                                                {t('Confirm and open')}
                                            </AlertDialogAction>
                                        </div>
                                    </AlertDialogContent>
                                </AlertDialog>
                            </Card>
                        </>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}

export default function Cards(props: Props) {
    useClientTranslation();
    const { errors, tenant } = usePage<SharedProps & { errors: { form?: string } }>().props;
    const unresolved = props.issueOrders.find(
        (order) => order.state === 'creating' || order.state === 'unknown',
    );
    const firstProduct = props.products[0];
    const [choosingCard, setChoosingCard] = useState(false);
    const [verificationPromptOpen, setVerificationPromptOpen] = useState(
        !props.kycApproved && !props.demo,
    );
    const closeVerificationPrompt = () => {
        router.visit('/dashboard', { replace: true });
    };
    const [applicationProductId, setApplicationProductId] = useState<string | null>(null);
    const canOpenApplication =
        props.kycApproved &&
        props.providerAvailable &&
        !props.demo &&
        !unresolved &&
        !props.refundPending;
    const activeApplication =
        props.cardholder.id && props.cardholder.state !== 'not_available'
            ? props.cardholder
            : undefined;

    return (
        <UserLayout>
            <Head title={t('Cards')} />
            <div className="space-y-7 sm:space-y-9">
                <h1 className="sr-only">{t('Cards')}</h1>
                {props.cards.length === 0 && firstProduct && !canOpenApplication ? (
                    <div className="user-card-intro">
                        <h2>{t('Apply for a Mastercard U Card')}</h2>
                    </div>
                ) : null}
                {props.cards.length === 0 && firstProduct && !canOpenApplication ? (
                    <div className="user-card-stage">
                        <div className="user-card-preview">
                            <img
                                src="/images/marketing/spec-pay-application-card.png"
                                alt={t('Card design illustration')}
                                width={613}
                                height={353}
                            />
                        </div>
                    </div>
                ) : null}
                {props.cards.length === 0 && firstProduct && !canOpenApplication ? (
                    <section className="user-card-intro">
                        {!props.kycApproved && !props.demo ? (
                            <button
                                type="button"
                                className="user-card-intro-action w-full"
                                onClick={() => setVerificationPromptOpen(true)}
                                aria-haspopup="dialog"
                            >
                                {t('Verify identity')}
                            </button>
                        ) : (
                            <a
                                className="user-card-intro-action"
                                href={props.demo ? '/login' : '#card-setup'}
                            >
                                {props.demo
                                    ? t('Sign in to apply')
                                    : t('View application requirements')}
                            </a>
                        )}
                    </section>
                ) : null}

                <section>
                    <div className="mb-5 flex items-center justify-between gap-4">
                        <h2 className="text-lg font-semibold">{t('Your cards')}</h2>
                        {canOpenApplication && firstProduct && (
                            <Button
                                id="open-card-application"
                                size="sm"
                                onClick={() => setChoosingCard(true)}
                                aria-haspopup="dialog"
                            >
                                <Plus className="size-4" aria-hidden="true" />
                                {t('Apply for a card')}
                            </Button>
                        )}
                    </div>
                    {props.cards.length > 0 ? (
                        <div className="grid gap-6">
                            {props.cards.map((card) => (
                                <div key={card.id} className="user-card-group">
                                    <div
                                        className={`user-card-visual${card.state === 'Frozen' ? ' user-card-visual-frozen' : ''}`}
                                    >
                                        <div className="flex items-start justify-between">
                                            <p className="font-semibold">
                                                {cardDisplayName(card.productName)}
                                            </p>
                                            <CreditCard className="size-5 text-white/70" />
                                        </div>
                                        <p
                                            className={
                                                card.state === 'Frozen'
                                                    ? 'mt-2 inline-flex rounded-full bg-white/15 px-3 py-1 text-sm font-semibold text-white'
                                                    : 'mt-2 text-xs text-white/80'
                                            }
                                        >
                                            {t(card.state ?? 'Awaiting confirmation')}
                                        </p>
                                        <p className="mt-5 font-mono text-lg tracking-wider">
                                            {card.maskedPan}
                                        </p>
                                        <div className="mt-5 flex items-end justify-between gap-4 text-sm">
                                            <div>
                                                <p className="text-white/60">{t('Balance')}</p>
                                                <p className="mt-1 text-lg font-semibold">
                                                    {card.balance ? (
                                                        <MoneyDisplay
                                                            amount={card.balance}
                                                            asset={card.currency}
                                                            compact
                                                        />
                                                    ) : (
                                                        t('Pending sync')
                                                    )}
                                                </p>
                                            </div>
                                            <div className="text-right">
                                                <p className="text-white/60">{t('Expiry')}</p>
                                                <p className="mt-1">{card.expiry ?? '—'}</p>
                                            </div>
                                        </div>
                                    </div>
                                    {!props.demo && (
                                        <CardManagementActions
                                            card={card}
                                            availableBalance={props.availableBalance}
                                            walletAsset={props.walletAsset}
                                        />
                                    )}
                                </div>
                            ))}
                        </div>
                    ) : (
                        <UserEmptyState
                            title={t('No cards yet')}
                            description={t('Your Mastercard U Cards will appear here.')}
                        />
                    )}
                </section>

                <UserCardTransactions
                    key={`${props.cards
                        .map((card) => card.id)
                        .sort()
                        .join(',')}:${props.providerAvailable}:${Boolean(props.demo)}`}
                    cardIds={props.cards.map((card) => card.id)}
                    available={props.providerAvailable && !props.demo}
                />

                <div id="card-setup" className="scroll-mt-6" />
                {!props.providerAvailable ? (
                    <UserStatusBanner
                        tone="warning"
                        title={t('Card setup unavailable')}
                        description={t(
                            'Card service is unavailable. No application or wallet hold has been created.',
                        )}
                    />
                ) : null}
                <IdentityVerificationDialog
                    open={verificationPromptOpen}
                    onDismiss={closeVerificationPrompt}
                />
                {props.demo ? (
                    <UserStatusBanner
                        tone="neutral"
                        title={t('Card setup preview')}
                        description={t(
                            'Sign in to start the real Demo flow. This public preview cannot create a Cardholder or reserve funds.',
                        )}
                    />
                ) : null}

                {unresolved ? (
                    <section className="rounded-[var(--user-radius-lg)] border bg-surface p-5 sm:p-7">
                        <LoaderCircle className="size-7 text-primary" />
                        <h2 className="mt-4 text-xl font-semibold">{t('Creating your card')}</h2>
                        <p className="mt-2 text-sm text-muted-foreground">
                            {t(
                                "We're confirming the card status. Your reserved funds remain protected; do not open another card.",
                            )}
                        </p>
                        <Button
                            variant="secondary"
                            className="mt-5 w-full sm:w-auto"
                            onClick={() => router.post(`/cards/issues/${unresolved.id}/sync`)}
                        >
                            <RefreshCw className="mr-2 size-4" />
                            {t('Refresh status')}
                        </Button>
                    </section>
                ) : null}

                {errors.form && applicationProductId === null ? (
                    <p className="text-sm text-destructive">{errorMessage(errors.form)}</p>
                ) : null}

                {canOpenApplication ? (
                    <>
                        <Dialog open={choosingCard} onOpenChange={setChoosingCard}>
                            <DialogContent
                                className="user-theme user-card-dialog flex max-h-[calc(100dvh-2rem)] max-w-2xl flex-col overflow-clip p-0"
                                style={userThemeStyle(tenant?.branding.primaryColor)}
                                closeLabel={t('Close')}
                                onCloseAutoFocus={(event) => {
                                    event.preventDefault();
                                    if (applicationProductId === null)
                                        document.getElementById('open-card-application')?.focus();
                                }}
                            >
                                <DialogHeader className="mb-0 shrink-0 px-5 pt-6 pb-4 pr-16 sm:px-7 sm:pr-16">
                                    <DialogTitle>{t('Choose a card')}</DialogTitle>
                                    <DialogDescription className="sr-only">
                                        {t(
                                            'Choose a card product before entering cardholder information.',
                                        )}
                                    </DialogDescription>
                                </DialogHeader>
                                <div
                                    className="min-h-0 space-y-5 overflow-y-auto overscroll-contain px-5 pb-6 sm:px-7"
                                    data-card-product-picker
                                >
                                    {activeApplication && (
                                        <p className="text-sm text-muted-foreground">
                                            {t(
                                                'Continue your existing application before choosing another card product.',
                                            )}
                                        </p>
                                    )}
                                    {props.products.map((option) => (
                                        <section
                                            key={option.id}
                                            className="rounded-2xl border bg-surface p-4 sm:p-5"
                                        >
                                            <div className="rounded-2xl bg-slate-950 p-5 text-white">
                                                <div className="flex items-start justify-between gap-4">
                                                    <div>
                                                        <p className="text-xs text-white/60">
                                                            {t('Mastercard U Card')}
                                                        </p>
                                                        <h3 className="mt-2 text-xl font-semibold">
                                                            {cardDisplayName(option.name)}
                                                        </h3>
                                                    </div>
                                                    <CreditCard
                                                        className="size-6 shrink-0 text-white/70"
                                                        aria-hidden="true"
                                                    />
                                                </div>
                                                <p className="mt-6 text-sm text-white/70">
                                                    {option.cardCurrency}
                                                </p>
                                            </div>
                                            <dl className="my-4 divide-y text-sm">
                                                <div className="flex flex-wrap justify-between gap-2 py-3">
                                                    <dt className="text-muted-foreground">
                                                        {t('Opening fee')}
                                                    </dt>
                                                    <dd className="font-semibold">
                                                        {'$ '}
                                                        <MoneyDisplay
                                                            amount={option.openingFee}
                                                            asset="USDT"
                                                            compact
                                                            hideSymbol
                                                        />
                                                    </dd>
                                                </div>
                                                <div className="flex flex-wrap justify-between gap-2 py-3">
                                                    <dt className="text-muted-foreground">
                                                        {t('Minimum initial balance')}
                                                    </dt>
                                                    <dd className="font-semibold">
                                                        <MoneyDisplay
                                                            amount={option.minimumInitialLoad}
                                                            asset={option.cardCurrency}
                                                            compact
                                                        />
                                                    </dd>
                                                </div>
                                            </dl>
                                            <Button
                                                className="w-full"
                                                disabled={
                                                    !option.readyForSetup ||
                                                    Boolean(
                                                        activeApplication &&
                                                        activeApplication.productId !== option.id,
                                                    )
                                                }
                                                onClick={() => {
                                                    setChoosingCard(false);
                                                    setApplicationProductId(option.id);
                                                }}
                                            >
                                                {t('Open this card')}
                                            </Button>
                                            {!option.readyForSetup && (
                                                <p className="mt-3 text-sm text-muted-foreground">
                                                    {t(option.guidance)}
                                                </p>
                                            )}
                                        </section>
                                    ))}
                                </div>
                            </DialogContent>
                        </Dialog>
                        {props.products.length ? (
                            props.products.map((product) => (
                                <ProductIssue
                                    key={product.id}
                                    product={product}
                                    availableBalance={props.availableBalance}
                                    open={applicationProductId === product.id}
                                    onClose={() => setApplicationProductId(null)}
                                    onSelectProduct={setApplicationProductId}
                                    restoreFocus={applicationProductId === null}
                                    application={
                                        activeApplication?.productId === product.id
                                            ? activeApplication
                                            : undefined
                                    }
                                />
                            ))
                        ) : (
                            <UserEmptyState
                                title={t('No card products available')}
                                description={t(
                                    'Your card program is not currently accepting new requests.',
                                )}
                            />
                        )}
                    </>
                ) : null}
            </div>
        </UserLayout>
    );
}
