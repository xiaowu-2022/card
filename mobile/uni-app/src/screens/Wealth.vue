<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import PageShell from '../components/PageShell.vue';
import SelectField from '../components/SelectField.vue';
import FormField from '../components/FormField.vue';
import FormErrors from '../components/FormErrors.vue';
import ConfirmCheck from '../components/ConfirmCheck.vue';
import Modal from '../components/Modal.vue';
import WealthNotice from '../components/WealthNotice.vue';
import UiIcon from '../components/UiIcon.vue';
import { t } from '../lib/i18n';
import { go } from '../lib/navigation';
import { useAction, requestId } from '../lib/client';
import {
    wealthState,
    wealthDate,
    wealthTerm,
    type WealthSetting,
    type WealthOrder,
} from '../lib/wealth';
import { exactAmount } from '../generated/exact-amount';
const props = defineProps<{
    page: {
        settings: WealthSetting[];
        selectedAsset?: string;
        view?: 'details' | 'deposit' | 'withdraw';
        orders: { data: WealthOrder[]; prev_page_url: string | null; next_page_url: string | null };
    };
}>();
const view = computed(() => props.page.view ?? 'deposit'),
    asset = ref(props.page.selectedAsset ?? 'USDT'),
    review = ref(false),
    action = useAction();
const setting = computed(() => props.page.settings.find((s) => s.asset === asset.value)!);
const form = reactive({
    asset: asset.value,
    months: '1',
    amount: '',
    revision: setting.value.revision ?? '',
    request_id: requestId(),
    confirmed: false,
});
const product = computed(() =>
    setting.value.products.find((p) => p.months === Number(form.months)),
);
const title = computed(() =>
    view.value === 'details'
        ? t('{{asset}} wealth details', { asset: asset.value })
        : asset.value +
          ' · ' +
          t(view.value === 'deposit' ? 'Wealth deposit action' : 'Wealth withdraw action'),
);
const available = computed(() =>
    t('Available balance: {{amount}}', {
        amount: exactAmount(setting.value.available) + ' ' + asset.value,
    }),
);
const minimum = computed(() =>
    t('Minimum wealth deposit: {{amount}}', {
        amount: exactAmount(setting.value.minimum) + ' ' + asset.value,
    }),
);
const reviewDescription = computed(() =>
    t('Review wealth deposit: {{amount}}, {{months}} months, {{rate}}% annual rate.', {
        amount: exactAmount(form.amount) + ' ' + asset.value,
        months: product.value?.months ?? 1,
        rate: exactAmount(product.value?.rate ?? '0'),
    }),
);
function changed() {
    review.value = false;
    form.confirmed = false;
    form.request_id = requestId();
}
function selectCurrency(value: string) {
    asset.value = value;
    form.asset = value;
    form.months = '1';
    form.amount = '';
    form.revision = setting.value.revision ?? '';
    changed();
    if (view.value !== 'deposit') go('/wealth/assets/' + value + '?view=' + view.value, true);
}
function openReview() {
    if (action.pending.value || !product.value?.enabled || !setting.value.revision) return;
    form.revision = setting.value.revision;
    form.confirmed = false;
    review.value = true;
}
function closeReview() {
    if (action.pending.value) return;
    review.value = false;
    form.confirmed = false;
}
async function submit() {
    if (!form.confirmed || !product.value?.enabled) return;
    await action.submit('/wealth/orders', { ...form, months: Number(form.months) });
    review.value = false;
    form.confirmed = false;
}
function paidLabel(order: WealthOrder) {
    return t('Total interest paid') + ': ' + exactAmount(order.paid) + ' ' + order.asset;
}
</script>
<template>
    <PageShell :title="title" back="/wealth" active="assets"
        ><view class="wealth-page"
            ><WealthNotice v-if="view === 'withdraw'" /><SelectField
                :model-value="asset"
                :label="t('Select currency')"
                :options="page.settings.map((s) => ({ value: s.asset, label: s.asset }))"
                :disabled="action.pending.value || review"
                @update:model-value="selectCurrency"
            /><view v-if="view !== 'deposit'" class="wealth-totals"
                ><view
                    ><text class="small muted">{{ t('Current wealth principal') }}</text
                    ><text class="total">{{ exactAmount(setting.principal) }}</text
                    ><text class="small muted">{{ asset }}</text></view
                ><view
                    ><text class="small muted">{{ t('Cumulative net earnings') }}</text
                    ><text class="total">{{ exactAmount(setting.net) }}</text
                    ><text class="small muted">{{ asset }}</text></view
                ></view
            ><FormErrors :errors="action.errors.value" />
            <form v-if="view === 'deposit'" class="wealth-form" @submit="openReview">
                <text class="available">{{ available }}</text
                ><SelectField
                    v-model="form.months"
                    :label="t('Wealth term')"
                    :options="
                        setting.products.map((p) => ({
                            value: String(p.months),
                            label:
                                wealthTerm(p.months, exactAmount(p.rate)) +
                                (!p.enabled ? ' · ' + t('Unavailable') : ''),
                        }))
                    "
                    :disabled="review || action.pending.value"
                    @update:model-value="changed"
                /><FormField
                    v-model="form.amount"
                    :label="t('Deposit principal')"
                    type="digit"
                    :disabled="review || action.pending.value"
                    @update:model-value="changed"
                /><text v-if="setting.minimum" class="muted">{{ minimum }}</text
                ><text v-if="!product?.enabled" class="muted">{{
                    t('This wealth product is not available for new deposits.')
                }}</text
                ><button
                    class="primary wide"
                    form-type="submit"
                    :disabled="
                        action.pending.value ||
                        !product?.enabled ||
                        !setting.revision ||
                        review ||
                        !form.amount
                    "
                >
                    {{ t('Review wealth deposit') }}
                </button>
            </form>
            <Modal
                :open="view === 'deposit' && review"
                :title="t('Confirm wealth deposit')"
                :description="reviewDescription"
                :busy="action.pending.value"
                @close="closeReview"
                ><form @submit="submit">
                    <text class="review-copy">{{
                        t(
                            'Redeem principal on the maturity day before midnight in the order timezone. Otherwise, the same principal renews for the same term and rate. Paid interest stays in your wallet.',
                        )
                    }}</text
                    ><text class="review-copy muted">{{
                        t(
                            'No extra interest accrues during the redemption window. Processing delays do not shift the next term.',
                        )
                    }}</text
                    ><WealthNotice /><ConfirmCheck
                        v-model="form.confirmed"
                        :label="t('I confirm the term, interest rate and early withdrawal rules.')"
                        :disabled="action.pending.value"
                    /><view class="review-buttons"
                        ><button
                            class="secondary"
                            :disabled="action.pending.value"
                            @click="closeReview"
                        >
                            {{ t('Back') }}</button
                        ><button
                            class="primary"
                            form-type="submit"
                            :disabled="
                                action.pending.value ||
                                !form.confirmed ||
                                !product?.enabled ||
                                !setting.revision
                            "
                        >
                            {{ t('Confirm wealth deposit') }}
                        </button></view
                    >
                </form></Modal
            ><template v-if="view !== 'deposit'"
                ><view class="records-heading"
                    ><text>{{ t('Wealth deposit records') }}</text
                    ><button
                        v-if="view === 'details'"
                        class="deposit-link"
                        @click="go('/wealth/assets/' + asset + '?view=deposit')"
                    >
                        {{ t('Wealth deposit action') }}
                    </button></view
                ><text v-if="!page.orders.data.length" class="muted">{{
                    t(
                        view === 'withdraw'
                            ? 'No deposits available for early withdrawal.'
                            : 'No wealth deposits yet.',
                    )
                }}</text
                ><view class="order-list"
                    ><view
                        v-for="order in page.orders.data"
                        :key="order.id"
                        class="wealth-record"
                        @click="go('/wealth/orders/' + order.id + '?view=' + view)"
                        ><view class="order-heading"
                            ><text class="order-principal"
                                >{{ exactAmount(order.principal) }}
                                <text class="currency">{{ order.asset }}</text></text
                            ><text
                                class="order-state"
                                :class="{ active: order.displayStatus === 'ACTIVE' }"
                                >{{ wealthState(order.displayStatus) }}</text
                            ></view
                        ><text class="term">{{
                            wealthTerm(order.months, exactAmount(order.rate))
                        }}</text
                        ><text class="small muted"
                            >{{ wealthDate(order.startedAt, order.timezone) }} →
                            {{ wealthDate(order.maturesAt, order.timezone) }}</text
                        ><view class="order-footer"
                            ><text class="small muted">{{ paidLabel(order) }}</text
                            ><UiIcon name="chevron-right" :size="18" /></view></view></view
                ><view class="pagination"
                    ><button
                        v-if="page.orders.prev_page_url"
                        class="text-button"
                        @click="go(page.orders.prev_page_url, true)"
                    >
                        {{ t('Previous') }}</button
                    ><view v-else /><button
                        v-if="page.orders.next_page_url"
                        class="text-button"
                        @click="go(page.orders.next_page_url, true)"
                    >
                        {{ t('Next') }}
                    </button></view
                ></template
            ></view
        ></PageShell
    >
</template>
<style scoped>
.wealth-page :deep(.select-trigger) {
    border-radius: 999px;
    min-height: clamp(48px, 9.6cqw, 72px);
    padding-inline: 20px;
}
.wealth-page {
    display: flex;
    flex-direction: column;
    gap: 20px;
}
.wealth-page > :deep(.select-field) {
    margin: 0;
}
.wealth-totals {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    padding: 16px;
    background: white;
    border-radius: 12px;
    font-size: 14px;
}
.small {
    display: block;
    font-size: 12px;
    line-height: 20px;
}
.total {
    display: block;
    margin-top: 4px;
    font-size: 18px;
    font-weight: 600;
    overflow-wrap: anywhere;
}
.wealth-form {
    background: white;
    border-radius: 16px;
    padding: 20px;
}
.available {
    display: block;
    font-size: 14px;
    margin-bottom: 16px;
}
.primary,
.secondary {
    min-height: 44px;
    padding: 10px 20px;
    border-radius: 999px;
    font-size: 14px;
    line-height: 24px;
}
.wide {
    width: 100%;
    margin-top: 16px;
}
.review-copy {
    display: block;
    font-size: 14px;
    line-height: 24px;
    margin-bottom: 16px;
}
.review-buttons {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}
.records-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    font-weight: 600;
}
.deposit-link {
    flex-shrink: 0;
    background: #047857;
    color: white;
    border: 1px solid #047857;
    border-radius: 12px;
    min-height: 44px;
    padding: 8px 20px;
    font-size: 14px;
    line-height: 24px;
    font-weight: 600;
}
.order-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.wealth-record {
    padding: 16px;
    background: white;
    border-radius: 16px;
    border: 1px solid #0000000d;
    box-shadow: 0 1px 2px #0000000d;
}
.order-heading {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    justify-content: space-between;
    gap: 8px;
}
.order-principal {
    font-size: 20px;
    font-weight: 600;
    overflow-wrap: anywhere;
}
.currency {
    font-size: 14px;
    font-weight: 500;
}
.order-state {
    padding: 4px 10px;
    border-radius: 999px;
    background: #f0f3f1;
    color: #68736e;
    font-size: 12px;
}
.order-state.active {
    background: #ecfdf5;
    color: #065f46;
}
.term {
    display: block;
    margin-top: 12px;
    font-size: 14px;
}
.order-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-top: 12px;
}
.text-button {
    background: none;
    padding: 12px 0;
    font-size: 14px;
    line-height: 20px;
}
</style>
