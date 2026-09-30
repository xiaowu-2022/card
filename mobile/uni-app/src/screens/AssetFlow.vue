<script setup lang="ts">
import { computed, reactive, ref, onBeforeUnmount } from 'vue';
import PageShell from '../components/PageShell.vue';
import FormField from '../components/FormField.vue';
import FormErrors from '../components/FormErrors.vue';
import SelectField from '../components/SelectField.vue';
import ConfirmCheck from '../components/ConfirmCheck.vue';
import DepositInstructions from '../components/DepositInstructions.vue';
import AssetIcon from '../components/AssetIcon.vue';
import { t, dateTime } from '../lib/i18n';
import { go } from '../lib/navigation';
import { useAction, requestId } from '../lib/client';
import { subtract, type AssetOverview } from '../lib/assets';
import { exactAmount, withdrawalPercentageFee, meetsTopupMinimum } from '../generated/exact-amount';
import { assetNetworkLabel } from '../generated/asset-network';
type Result = {
    id: string;
    asset: string;
    amount: string;
    state: string;
    network?: string;
    address?: string;
    fee?: string;
    feePercent?: string | null;
    receive?: string;
    rate?: string;
    expiresAt?: string;
    canConfirm?: boolean;
    canCancel?: boolean;
};
const props = defineProps<{
    page: {
        overview: AssetOverview;
        mode: 'deposit' | 'withdrawal' | 'exchange';
        selectedAsset: string;
        result: Result | null;
    };
}>();
const emit = defineEmits<{ reload: [] }>();
const action = useAction(),
    review = ref(false),
    reviewRate = ref<string | null>(null),
    clock = ref(Date.now());
const timer = setInterval(() => (clock.value = Date.now()), 1000);
onBeforeUnmount(() => clearInterval(timer));
const form = reactive({
    mode: props.page.mode,
    asset: props.page.selectedAsset,
    rail: '',
    amount: '',
    address: '',
    expected_fee: '',
    confirmed: false,
    request_id: requestId(),
});
const account = computed(() => props.page.overview.assets.find((a) => a.asset === form.asset)!);
const rails = computed(
    () =>
        account.value?.rails.filter((r) =>
            props.page.mode === 'deposit' ? r.deposit : r.withdrawal,
        ) ?? [],
);
form.rail = props.page.mode === 'exchange' ? '' : (rails.value[0]?.code ?? '');
const rail = computed(() => rails.value.find((r) => r.code === form.rail));
const calculatedFee = computed(() =>
    withdrawalPercentageFee(form.amount, rail.value?.feePercent ?? null, form.asset),
);
const seconds = computed(() =>
    props.page.result?.expiresAt
        ? Math.max(0, Math.ceil((Date.parse(props.page.result.expiresAt) - clock.value) / 1000))
        : 0,
);
const unavailable = computed(() =>
    props.page.mode === 'exchange' ? !account.value?.exchange : !rails.value.length,
);
const unavailableMessage = computed(() =>
    props.page.mode === 'exchange'
        ? (account.value?.exchangeUnavailableReason ?? 'Exchange is not available for this asset.')
        : props.page.mode === 'deposit'
          ? 'No deposit network is available for this currency.'
          : 'No withdrawal network is available for this currency.',
);
const title = computed(() =>
    t(
        props.page.mode === 'deposit'
            ? 'Top up'
            : props.page.mode === 'withdrawal'
              ? 'Withdraw'
              : 'Exchange to USDT',
    ),
);
const expires = computed(() =>
    t('Valid until {{time}}', { time: dateTime(props.page.result?.expiresAt ?? '') }),
);
const confirmLabel = computed(
    () => t('Confirm exchange') + ' · ' + t('{{seconds}} seconds', { seconds: seconds.value }),
);
const invalid = computed(
    () =>
        action.pending.value ||
        !form.amount ||
        (props.page.mode === 'exchange' ? !account.value?.exchange : !rail.value) ||
        (review.value && !form.confirmed) ||
        (props.page.mode === 'withdrawal' && calculatedFee.value === null) ||
        (props.page.mode === 'deposit' &&
            rail.value?.code === 'USDT_TRON' &&
            !meetsTopupMinimum(form.amount, rail.value.minimum ?? '0')),
);
function intentChanged() {
    form.request_id = requestId();
    form.confirmed = false;
    review.value = false;
}
function chooseAsset(value: string) {
    form.asset = value;
    form.rail = props.page.mode === 'exchange' ? '' : (rails.value[0]?.code ?? '');
    form.amount = '';
    form.address = '';
    form.expected_fee = '';
    intentChanged();
}
async function submit() {
    if (unavailable.value || action.pending.value) return;
    if (rail.value?.code === 'USDT_TRON') {
        if (props.page.mode === 'deposit')
            await action.submit('/wallet/top-ups', {
                request_id: form.request_id,
                requested_amount: form.amount,
            });
        else go('/wallet/withdraw');
        return;
    }
    if (props.page.mode === 'withdrawal' && !review.value) {
        if (calculatedFee.value === null) return;
        reviewRate.value = rail.value?.feePercent ?? null;
        form.expected_fee = calculatedFee.value;
        review.value = true;
        return;
    }
    await action.submit('/assets/orders', { ...form });
}
async function operation(kind: 'confirm' | 'cancel') {
    if (!props.page.result) return;
    await action.submit(
        `/assets/${kind === 'confirm' ? 'exchanges' : 'withdrawals'}/${props.page.result.id}/${kind}`,
        { confirmed: true },
        { navigate: false, success: () => emit('reload') },
    );
}
</script>
<template>
    <PageShell :title="title" back="/dashboard" active="assets"
        ><view class="flow"
            ><FormErrors :errors="action.errors.value" /><DepositInstructions
                v-if="page.result && page.mode === 'deposit'"
                :asset="page.result.asset"
                :amount="page.result.amount"
                :network="page.result.network"
                :address="page.result.address"
                :state="
                    seconds === 0 && page.result.state !== 'Completed'
                        ? 'Top-up expired'
                        : page.result.state
                "
                :expires-at="page.result.expiresAt"
                :payable="seconds > 0 && page.result.state !== 'Completed'"
                :new-href="'/assets/operate?mode=deposit&asset=' + page.result.asset"
            /><view v-else-if="page.result" class="flow-card stack"
                ><view class="result-heading"
                    ><AssetIcon :asset="page.result.asset" /><view
                        ><text class="bold block">{{ t(page.result.state) }}</text
                        ><text class="muted"
                            >{{ page.result.asset }}
                            {{
                                page.result.network
                                    ? ' · ' +
                                      assetNetworkLabel(page.result.network, page.result.asset)
                                    : ''
                            }}</text
                        ></view
                    ></view
                ><text class="result-amount"
                    >{{ exactAmount(page.result.amount) }}
                    <text class="currency">{{ page.result.asset }}</text></text
                ><text v-if="page.mode === 'withdrawal'" class="detail"
                    >{{ t('Destination address') }}: {{ page.result.address }}</text
                ><text v-if="page.result.rate" class="detail"
                    >{{ t('Exchange rate') }}: 1 {{ page.result.asset }} ≈
                    {{ exactAmount(page.result.rate) }} USDT</text
                ><text v-if="page.result.fee !== undefined" class="detail"
                    >{{ t('Platform fee') }}: {{ exactAmount(page.result.fee) }}
                    {{ page.mode === 'exchange' ? 'USDT' : page.result.asset }}
                    {{
                        page.result.feePercent != null
                            ? ' (' + exactAmount(page.result.feePercent) + '%)'
                            : ''
                    }}</text
                ><view v-if="page.result.receive" class="receive-result"
                    ><text class="muted block">{{ t('You receive') }}</text
                    ><text class="receive-amount"
                        >{{ exactAmount(page.result.receive) }}
                        {{ page.mode === 'exchange' ? 'USDT' : page.result.asset }}</text
                    ></view
                ><text v-if="page.result.expiresAt" class="small muted">{{ expires }}</text
                ><template v-if="page.mode === 'exchange' && page.result.canConfirm"
                    ><text class="muted">{{
                        t(
                            'This exchanges your available balance internally. Confirm only after reviewing the final amounts.',
                        )
                    }}</text
                    ><button
                        class="primary wide"
                        :disabled="action.pending.value || seconds === 0"
                        @click="operation('confirm')"
                    >
                        {{ confirmLabel }}
                    </button></template
                ><button
                    v-if="page.result.canCancel"
                    class="secondary wide"
                    :disabled="action.pending.value"
                    @click="operation('cancel')"
                >
                    {{ t('Cancel withdrawal') }}</button
                ><text
                    class="text-link"
                    @click="go('/assets/operate?mode=' + page.mode + '&asset=' + page.result.asset)"
                    >{{ t('Start a new request') }}</text
                ></view
            ><view v-else-if="account" class="flow-card"
                ><SelectField
                    v-model="form.asset"
                    class="asset-choice"
                    hide-label
                    :label="t('Select currency')"
                    :options="page.overview.assets.map((a) => ({ value: a.asset, label: a.asset }))"
                    :disabled="action.pending.value"
                    @update:model-value="chooseAsset"
                    ><view class="asset-choice-value"
                        ><AssetIcon :asset="form.asset" /><text>{{ form.asset }}</text></view
                    ></SelectField
                ><text class="available"
                    >{{ t('Available balance') }}: {{ exactAmount(account.available) }}
                    {{ account.asset }}</text
                ><view v-if="page.mode === 'exchange'" class="receive-box"
                    ><text class="small muted">{{ t('Receive currency') }}</text
                    ><view class="result-heading"
                        ><AssetIcon asset="USDT" /><text class="bold">USDT</text></view
                    ><text class="small muted">{{
                        t('Card opening and card top-ups use your available USDT balance.')
                    }}</text></view
                ><SelectField
                    v-if="page.mode !== 'exchange' && !unavailable"
                    v-model="form.rail"
                    hide-label
                    :placeholder="t('Select network')"
                    :label="t('Select network')"
                    :options="
                        rails.map((r) => ({
                            value: r.code,
                            label: assetNetworkLabel(r.network, form.asset) ?? r.network,
                        }))
                    "
                    :disabled="action.pending.value"
                    @update:model-value="intentChanged"
                /><text v-if="unavailable" class="unavailable">{{ t(unavailableMessage) }}</text
                ><button
                    v-else-if="rail?.code === 'USDT_TRON' && page.mode === 'withdrawal'"
                    class="primary wide"
                    @click="submit"
                >
                    {{ t('Continue') }}</button
                ><template v-else
                    ><FormField
                        v-model="form.amount"
                        :label="t('Amount') + ' · ' + account.asset"
                        type="digit"
                        :disabled="action.pending.value"
                        @update:model-value="intentChanged"
                    /><FormField
                        v-if="page.mode === 'withdrawal'"
                        v-model="form.address"
                        :label="t('Destination address')"
                        :disabled="action.pending.value"
                        :maxlength="128"
                        @update:model-value="intentChanged"
                    /><text
                        v-if="rail?.code === 'USDT_TRON' && page.mode === 'deposit'"
                        class="hint"
                        >{{ t('TRC20 · No top-up fee') }}</text
                    ><text v-if="rail?.minimum && page.mode === 'deposit'" class="hint"
                        >{{ t('Minimum deposit') }}: {{ exactAmount(rail.minimum) }}
                        {{ account.asset }}</text
                    ><text
                        v-if="
                            page.mode === 'withdrawal' &&
                            (review ? reviewRate : rail?.feePercent) != null
                        "
                        class="hint"
                        >{{ t('Withdrawal fee rate') }}:
                        {{ exactAmount((review ? reviewRate : rail?.feePercent) ?? '0') }}%
                        {{
                            (review ? form.expected_fee : calculatedFee) !== null
                                ? ' · ' +
                                  exactAmount(review ? form.expected_fee : (calculatedFee ?? '0')) +
                                  ' ' +
                                  account.asset
                                : ''
                        }}</text
                    ><view v-if="page.mode === 'withdrawal' && review" class="review-box"
                        ><text class="block"
                            >{{ assetNetworkLabel(rail?.network, form.asset) }} ·
                            {{ form.address }}</text
                        ><text class="block"
                            >{{ t('Amount') }}: {{ form.amount }} {{ account.asset }}</text
                        ><text class="block"
                            >{{ t('You receive') }}: {{ subtract(form.amount, form.expected_fee) }}
                            {{ account.asset }}</text
                        ><ConfirmCheck
                            v-model="form.confirmed"
                            :label="t('I checked the network, address and final amount.')" /></view
                    ><text v-if="page.mode === 'exchange'" class="hint">{{
                        t('The next step shows the final rate, fee and quote expiry.')
                    }}</text
                    ><button class="primary wide" :disabled="invalid" @click="submit">
                        {{
                            t(
                                page.mode === 'exchange'
                                    ? 'Get quote'
                                    : page.mode === 'withdrawal'
                                      ? review
                                          ? 'Confirm withdrawal'
                                          : 'Review withdrawal'
                                      : 'Create deposit order',
                            )
                        }}
                    </button></template
                ></view
            ><view v-if="page.mode === 'withdrawal' && !page.result" class="withdrawal-note"
                ><text>{{
                    t(
                        'USDT and USDC withdrawals incur a 10% fee. ETH and BTC withdrawals have no fee.',
                    )
                }}</text
                ><text>{{
                    t('Account-to-account transfers are free for all currencies.')
                }}</text></view
            ></view
        ></PageShell
    >
</template>
<style scoped>
.flow {
    max-width: 512px;
    margin: auto;
}
.flow-card {
    background: white;
    border-radius: 16px;
    padding: 20px;
}
.asset-choice :deep(.select-trigger) {
    background: #f0f3f1;
    border: 0;
    border-radius: 12px;
    padding: 12px;
    min-height: 60px;
}
.asset-choice-value {
    display: flex;
    align-items: center;
    gap: 12px;
    font-weight: 500;
}
.stack {
    gap: 20px;
}
.available {
    display: block;
    font-size: 12px;
    color: #68736e;
    overflow-wrap: anywhere;
    margin-bottom: 20px;
}
.result-heading {
    display: flex;
    align-items: center;
    gap: 12px;
}
.block {
    display: block;
}
.bold {
    font-weight: 600;
}
.small {
    font-size: 12px;
}
.detail {
    display: block;
    font-size: 14px;
    overflow-wrap: anywhere;
}
.result-amount {
    font-size: 30px;
    line-height: 36px;
    font-weight: 600;
    overflow-wrap: anywhere;
}
.currency {
    font-size: 16px;
}
.receive-result {
    border-top: 1px solid #e2e7e4;
    padding-top: 16px;
}
.receive-amount {
    display: block;
    font-size: 24px;
    font-weight: 600;
    line-height: 32px;
    margin-top: 4px;
    overflow-wrap: anywhere;
}
.wide {
    width: 100%;
    min-height: 48px;
    border-radius: 999px;
    padding: 12px 20px;
    font-size: 14px;
    line-height: 24px;
}
.text-link {
    display: block;
    text-align: center;
    text-decoration: underline;
    font-size: 14px;
    padding: 12px 0;
}
.receive-box {
    display: flex;
    flex-direction: column;
    gap: 8px;
    border-radius: 12px;
    background: #f0f3f1;
    padding: 16px;
    margin-bottom: 20px;
}
.unavailable {
    display: block;
    padding: 16px;
    border-radius: 12px;
    background: #f0f3f1;
    font-size: 14px;
    color: #68736e;
}
.hint {
    display: block;
    margin-bottom: 20px;
    font-size: 12px;
    color: #68736e;
    line-height: 1.6;
}
.review-box {
    padding: 16px;
    background: #f0f3f1;
    border-radius: 12px;
    font-size: 14px;
    line-height: 24px;
    margin-bottom: 20px;
    overflow-wrap: anywhere;
}
.review-box > .block + .block {
    margin-top: 12px;
}
.withdrawal-note {
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding: 24px 8px 0;
    font-size: 14px;
    color: #68736e;
    line-height: 24px;
}
</style>
