// Read-model fixtures only. Never inserted into application databases or sent to providers.
export function addParityStates(fixture) {
    const id = '11111111-1111-4111-8111-111111111111';
    const now = '2026-09-26T06:00:00+08:00';
    const future = '2099-09-26T06:00:00+08:00';
    function add(path, base, component, props) {
        const dto = structuredClone(fixture.pages[base]);
        dto.component = 'user/' + component;
        Object.assign(dto.props, props);
        dto.url = path;
        fixture.pages[path] = dto;
        return dto;
    }
    for (const status of ['PENDING', 'VERIFIED'])
        add(`/register/challenges/${id}?fixture=${status}`, '/register', 'VerifyRegistration', {
            challenge: { id, channel: 'EMAIL', status },
            registration: undefined,
        });
    add('/forgot-password/' + id, '/forgot-password', 'ResetPassword', {
        reset: { id, expiresAt: future },
    });
    add('/account/restricted', '/account', 'Restricted', { tenantRestricted: false });
    add('/security-deposit/success', '/wallet', 'SecurityDepositSuccess', {
        receipt: { amount: '300.00000000', asset: 'USDT' },
    });
    for (const status of ['PENDING', 'VERIFYING', 'SUCCEEDED', 'REJECTED'])
        add(`/wallet/withdrawals/${id}?fixture=${status}`, '/wallet', 'WithdrawalStatus', {
            order: {
                id,
                amount: '100.00000000',
                feeAmount: '10.00000000',
                receiveAmount: '90.00000000',
                asset: 'USDT',
                network: 'TRON',
                maskedAddress: 'T123…5678',
                status,
                txHash: status === 'SUCCEEDED' ? 'a'.repeat(64) : null,
                reviewReason:
                    status === 'REJECTED' ? 'Please verify the destination address.' : null,
            },
        });
    const wealth = {
        id,
        asset: 'USDT',
        principal: '1000.00000000',
        rate: '8',
        months: 3,
        status: 'ACTIVE',
        displayStatus: 'ACTIVE',
        startedAt: now,
        maturesAt: future,
        paid: '6.66666666',
        returnAmount: '0',
        clawback: null,
        closedAt: null,
        canCancel: true,
        canRedeem: false,
        maturityPolicy: 'MANUAL_REDEEM_RENEW',
        redeemBefore: future,
        redeemBeforeLocal: '2099-09-27 00:00',
        previousOrderId: null,
        nextOrderId: null,
        timezone: 'Asia/Kuala_Lumpur',
        schedule: [
            { month: 1, dueAt: now, amount: '6.66666666', settledAt: now },
            { month: 2, dueAt: future, amount: '6.66666666', settledAt: null },
        ],
    };
    add('/wealth/orders/' + id, '/wealth', 'WealthOrder', {
        order: wealth,
        startWithdrawal: false,
    });
    add('/wealth/orders/' + id + '?fixture=matured', '/wealth', 'WealthOrder', {
        order: { ...wealth, displayStatus: 'REDEEMABLE', canCancel: false, canRedeem: true },
        startWithdrawal: false,
    });
    const ready = add(
        '/wealth/assets/USDT?fixture=enabled',
        '/wealth/assets/USDT?fixture=verified',
        'Wealth',
        {},
    );
    ready.props.settings.forEach((s) => {
        s.available = '2000.00000000';
        s.minimum = '1';
        s.revision = id;
        s.products.forEach((p) => (p.enabled = true));
    });
    const cards = add('/cards?fixture=cards', '/cards?fixture=verified', 'Cards', {});
    cards.props.providerAvailable = true;
    cards.props.availableBalance = '2000.00000000';
    cards.props.cards = [
        {
            id,
            productName: 'Spec Pay U Card',
            maskedPan: '**** **** **** 1234',
            last4: '1234',
            expiry: '11/28',
            currency: 'USD',
            balance: '1234.56',
            state: 'Normal',
            management: ['reveal', 'load', 'return', 'transactions', 'holder', 'activate'],
            minimumReload: '20',
            pendingOperationCount: 0,
            refundLocked: false,
            formFactor: 'physical_card',
            activationStatus: 'NONE',
            produceStatus: 'produced',
            trackingNumber: 'TEST-DELIVERY',
            syncedAt: now,
        },
    ];
    fixture.api['/client/cards/' + id + '/transactions'] = { items: [], page: 1, hasMore: false };
    for (const [name, patch] of Object.entries({
        frozen: { state: 'Frozen', management: ['reveal', 'unfreeze', 'transactions', 'holder'] },
        'refund-locked': { refundLocked: true, management: ['transactions'] },
        'activation-unknown': { state: 'Unactivated', activationStatus: 'UNKNOWN' },
    })) {
        const item = add('/cards?fixture=' + name, '/cards?fixture=cards', 'Cards', {});
        Object.assign(item.props.cards[0], patch);
    }
    const unknown = add('/cards?fixture=unknown', '/cards?fixture=verified', 'Cards', {});
    unknown.props.issueOrders = [
        {
            id,
            productName: 'Spec Pay U Card',
            openingFee: '2',
            initialLoadAmount: '20',
            state: 'unknown',
            requestedAt: now,
        },
    ];
    unknown.props.cardholder = {
        ...unknown.props.cardholder,
        state: 'unknown',
        id,
        requestId: id,
        canSync: true,
    };
    const message = {
        id,
        kind: 'PLATFORM',
        template: null,
        parameters: {},
        title: 'Migration acceptance notification',
        body: 'Offline test message.\n<img src=x onerror=alert(1)> is plain text.',
        time: now,
        href: '/wallet',
        readAt: now,
    };
    add('/messages/' + id, '/messages', 'Message', { message });
    fixture.api['/messages/' + id] = message;
    const empty = { items: [], page: 1, total: 0, hasMore: false };
    add('/promotion/stock', '/promotion/daily', 'PartnerStock', {
        report: {
            accountId: '202600000001',
            partnerId: id,
            updatedAt: now,
            timezone: 'Asia/Kuala_Lumpur',
            sharePercent: '20',
            stock: '2000',
            share: '400',
            negative: false,
            missingRates: 0,
            totals: Object.fromEntries(
                [
                    'annual',
                    'deposits',
                    'fees',
                    'activation',
                    'annualCommission',
                    'rebates',
                    'reimbursements',
                    'advances',
                ].map((k) => [k, '0']),
            ),
            trends: Object.fromEntries(
                ['activation', 'deposits', 'annual'].map((k) => [
                    k,
                    { today: '0', 3: '0', 7: '0', 30: '0' },
                ]),
            ),
            risks: {
                activeCount: 0,
                remaining: '0',
                expiredCount: 0,
                expiredAmount: '0',
                active: empty,
                expired: empty,
            },
            journal: empty,
            unvalued: empty,
        },
    });
    for (const asset of ['USDC', 'ETH', 'BTC']) {
        const p = add(
            '/assets/operate?mode=deposit&asset=' + asset,
            '/assets/operate?mode=deposit&asset=USDT&fixture=verified',
            'AssetFlow',
            { selectedAsset: asset },
        );
        const a = p.props.overview.assets.find((a) => a.asset === asset);
        if (a) {
            a.available = '1000';
            a.rails = [
                {
                    code: asset === 'BTC' ? 'BTC_BITCOIN' : asset + '_ETHEREUM',
                    network: asset === 'BTC' ? 'BITCOIN' : 'ETHEREUM',
                    deposit: true,
                    withdrawal: true,
                    minimum: '0.000001',
                    feePercent: '0.1',
                },
            ];
        }
    }
    for (const mode of ['withdrawal', 'exchange']) {
        const p = add(
            `/assets/operate?mode=${mode}&asset=ETH&fixture=enabled`,
            '/assets/operate?mode=deposit&asset=ETH',
            'AssetFlow',
            { mode },
        );
        p.props.overview.assets.find((a) => a.asset === 'ETH').exchange = true;
    }
    for (const state of ['Review exchange', 'Completed', 'Expired']) {
        add(
            `/assets/operate?mode=exchange&asset=ETH&fixture=${state.replaceAll(' ', '-')}`,
            '/assets/operate?mode=exchange&asset=ETH&fixture=enabled',
            'AssetFlow',
            {
                result: {
                    id,
                    asset: 'ETH',
                    amount: '0.123456789123456789',
                    state: state === 'Expired' ? 'Review exchange' : state,
                    rate: '2000',
                    fee: '0',
                    receive: '246.91357824',
                    expiresAt: state === 'Expired' ? now : future,
                    canConfirm: state === 'Review exchange',
                },
            },
        );
    }
    return fixture;
}
