<script setup lang="ts">
import { computed, ref } from 'vue';
import Modal from './Modal.vue';
import CardholderMaterials from './CardholderMaterials.vue';
import CardRecipient from './CardRecipient.vue';
import CardVisual from './CardVisual.vue';
import FormField from './FormField.vue';
import FormErrors from './FormErrors.vue';
import StatusBanner from './StatusBanner.vue';
import { t } from '../lib/i18n';
import { request } from '../lib/api';
import { useAction, requestId, explainError } from '../lib/client';
import { useSensitiveScreen } from '../lib/sensitive';
import { go } from '../lib/navigation';
import { displayMoney, exactAmount } from '../generated/exact-amount';
import { minorUnits, moneyFromMinor, type Product, type Cardholder } from '../lib/card-types';
const props = defineProps<{
        product: Product;
        selectedFormFactor: string;
        availableBalance: string | null;
        application?: Cardholder;
        open: boolean;
    }>(),
    emit = defineEmits<{ close: []; reload: [] }>();
const action = useAction(),
    materialsBusy = ref(false),
    recipientBusy = ref(false),
    reviewing = ref(false),
    editing = ref(false),
    loading = ref(false),
    fields = ref<Record<string, string>>({}),
    readErrors = ref<Record<string, string>>({}),
    recipientId = ref(''),
    recipientSummary = ref(''),
    amount = ref(exactAmount(props.product.minimumInitialLoad)),
    id = ref(requestId()),
    uncertain = ref(false);
const factor = computed(
    () =>
        props.application?.formFactor ??
        ((props.product.supportedFormFactors ?? ['virtual_card']).includes(props.selectedFormFactor)
            ? props.selectedFormFactor
            : (props.product.supportedFormFactors?.[0] ?? 'virtual_card')),
);
const ready = computed(() => props.application?.state === 'ready' && !editing.value),
    needsMaterials = computed(
        () =>
            !props.application ||
            props.application.state === 'action_required' ||
            (editing.value && props.application.state === 'ready'),
    ),
    busy = computed(
        () => action.pending.value || materialsBusy.value || recipientBusy.value || loading.value,
    );
const calculation = computed(() => {
    const opening = minorUnits(props.product.openingFee),
        initial = minorUnits(amount.value),
        minimum = minorUnits(props.product.minimumInitialLoad),
        available = props.availableBalance === null ? null : minorUnits(props.availableBalance);
    if (opening === null || initial === null || minimum === null) return null;
    return {
        total: moneyFromMinor(opening + initial),
        enough: available !== null && available >= opening + initial,
        meetsMinimum: initial >= minimum,
    };
});
const canSubmit = computed(
    () =>
        ready.value &&
        props.product.readyForSetup &&
        (factor.value !== 'physical_card' || !!recipientId.value) &&
        !!calculation.value?.enough &&
        !!calculation.value?.meetsMinimum,
);
const receiptHint = computed(() =>
        t('The card receives the same amount in {{value1}}.', {
            value1: props.product.cardCurrency,
        }),
    ),
    minimumHint = computed(() =>
        t('Initial balance must be at least ${{amount}}.', {
            amount: displayMoney(props.product.minimumInitialLoad),
        }),
    ),
    needed = computed(() =>
        t('You need ${{value1}} to open this card.', {
            value1: displayMoney(calculation.value?.total ?? '0'),
        }),
    ),
    availableHint = computed(() =>
        t('Available: ${{value1}}', { value1: displayMoney(props.availableBalance ?? '0') }),
    ),
    warning = computed(() =>
        t(
            'An opening fee of ${{fee}} and initial balance of ${{amount}} will be reserved separately while your card is created. Do not create another request while it is pending.',
            { fee: displayMoney(props.product.openingFee), amount: displayMoney(amount.value) },
        ),
    );
let generation = 0;
function close() {
    if (busy.value || reviewing.value) return;
    generation++;
    fields.value = {};
    editing.value = false;
    emit('close');
}
useSensitiveScreen(() => {
    generation++;
    fields.value = {};
    editing.value = false;
    reviewing.value = false;
    recipientSummary.value = '';
    emit('close');
});
async function edit() {
    if (!props.application?.id || busy.value) return;
    const run = ++generation;
    editing.value = true;
    loading.value = true;
    readErrors.value = {};
    try {
        const result = await request<{ fields: Record<string, unknown> }>(
            '/client/cards/cardholder/' + props.application.id + '/details',
            'POST',
        );
        if (run !== generation) return;
        const keys = ['legal_first_name', 'legal_last_name', 'email', 'mobile', 'mobile_country_code'];
        fields.value = Object.fromEntries(
            keys.map((key) => {
                if (typeof result.fields[key] !== 'string') throw new Error('Invalid fields');
                return [key, result.fields[key]];
            }),
        ) as Record<string, string>;
    } catch {
        if (run === generation) {
            editing.value = false;
            readErrors.value = {
                form: t('Cardholder information could not be loaded. Please close and try again.'),
            };
        }
    } finally {
        loading.value = false;
    }
}
async function sync() {
    if (!props.application?.id || busy.value) return;
    await action.submit(
        '/cards/cardholder/' + props.application.id + '/sync',
        {},
        { navigate: false, success: () => emit('reload') },
    );
}
function added() {
    editing.value = false;
    fields.value = {};
    emit('reload');
}
async function issue() {
    if (!canSubmit.value || busy.value) return;
    uncertain.value = true;
    const result = await action.submit(
        '/cards/issues',
        {
            request_id: id.value,
            card_product_id: props.product.id,
            initial_load_amount: amount.value,
            cardholder_application_id: props.application?.id,
            form_factor: factor.value,
            recipient_application_id: factor.value === 'physical_card' ? recipientId.value : null,
        },
        { navigate: false },
    );
    if (result) {
        reviewing.value = false;
        uncertain.value = false;
        emit('reload');
        emit('close');
    } else if (action.failureStatus.value === 422) {
        uncertain.value = false;
        reviewing.value = false;
    }
}
function changeAmount() {
    if (!uncertain.value) id.value = requestId();
}
</script>
<template>
    <Modal
        wide
        :open="open"
        :title="t(ready ? 'Confirm card opening' : 'Apply for a card')"
        :busy="busy || reviewing"
        @close="close"
        ><view class="application"
            ><view class="steps"
                ><text :class="{ current: !ready }">{{ t('1. Cardholder materials') }}</text
                ><text :class="{ current: ready }">{{ t('2. Review and open') }}</text></view
            ><FormErrors :errors="readErrors" /><template v-if="needsMaterials"
                ><text v-if="loading">{{ t('Loading cardholder information…') }}</text
                ><template v-else
                    ><text v-if="editing" class="intro muted">{{
                        t(
                            'Update your details and select the identity documents again. Continue opening after the update is completed.',
                        )
                    }}</text
                    ><CardholderMaterials
                        :key="editing ? 'edit' : 'new'"
                        :product-id="product.id"
                        :form-factor="factor"
                        :update-request-id="application?.requestId ?? undefined"
                        :initial="fields"
                        @busy="materialsBusy = $event"
                        @added="added" /></template></template
            ><view v-else-if="!ready" class="pending"
                ><text class="heading">{{ t('Confirming cardholder addition') }}</text
                ><text class="muted">{{
                    t('The cardholder addition could not be confirmed. Do not submit it again.')
                }}</text
                ><button class="secondary" :disabled="!application?.canSync || busy" @click="sync">
                    {{ t('Refresh status') }}
                </button></view
            ><template v-else
                ><button class="secondary" :disabled="busy || reviewing || uncertain" @click="edit">
                    {{ t('Edit card application information') }}</button
                ><text class="success intro">{{
                    t('Cardholder added. Confirm the amount to continue opening this card.')
                }}</text
                ><text>{{ t(factor === 'physical_card' ? 'Physical card' : 'Virtual card') }}</text
                ><CardRecipient
                    v-if="factor === 'physical_card' && application?.id"
                    :application-id="application.id"
                    :saved="application.recipient"
                    @ready="
                        (id, summary) => {
                            recipientId = id;
                            recipientSummary = summary;
                        }
                    "
                    @busy="recipientBusy = $event"
                /><CardVisual
                    :name="product.name"
                    :currency="product.cardCurrency"
                    :bin="product.bin"
                    preview
                /><view class="fee-details"
                    ><view
                        ><text class="muted">{{ t('Opening fee') }}</text
                        ><text class="strong">$ {{ displayMoney(product.openingFee) }}</text></view
                    ><view
                        ><text class="muted">{{ t('Minimum initial balance') }}</text
                        ><text class="strong"
                            >{{ displayMoney(product.minimumInitialLoad) }} USDT</text
                        ></view
                    ><view
                        ><text class="muted">{{ t('Available Wallet balance') }}</text
                        ><text class="strong">{{
                            availableBalance
                                ? displayMoney(availableBalance) + ' USDT'
                                : t('Unavailable')
                        }}</text></view
                    ></view
                ><FormField
                    v-model="amount"
                    :label="t('Initial card balance')"
                    type="digit"
                    :description="receiptHint"
                    :disabled="busy || uncertain || reviewing"
                    @update:model-value="changeAmount"
                /><view v-if="calculation" class="total"
                    ><text>{{ t('Total from Wallet') }}</text
                    ><text class="strong">{{ displayMoney(calculation.total) }} USDT</text></view
                ><text v-if="!calculation?.meetsMinimum" class="error">{{ minimumHint }}</text
                ><StatusBanner
                    v-if="calculation && !calculation.enough"
                    tone="warning"
                    :title="needed"
                    :description="availableHint"
                /><button
                    v-if="calculation && !calculation.enough"
                    class="primary wide"
                    @click="go('/wallet/top-up')"
                >
                    {{ t('Top up wallet') }}</button
                ><button
                    v-else
                    class="primary wide"
                    :disabled="!canSubmit || busy"
                    @click="reviewing = true"
                >
                    {{ t(busy ? 'Submitting…' : 'Open card') }}
                </button></template
            ><FormErrors :errors="action.errors.value" /></view></Modal
    ><Modal
        :open="reviewing"
        :title="
            t('Confirm card opening') +
            ' — ' +
            t(factor === 'physical_card' ? 'Physical card' : 'Virtual card')
        "
        :description="warning"
        :busy="action.pending.value"
        @close="reviewing = false"
        ><text v-if="factor === 'physical_card'" class="intro">{{
            recipientSummary || t('Recipient saved for this application.')
        }}</text
        ><FormErrors :errors="action.errors.value" /><view class="review-buttons"
            ><button
                class="secondary"
                :disabled="action.pending.value || uncertain"
                @click="reviewing = false"
            >
                {{ t('Cancel') }}</button
            ><button class="primary" :disabled="!canSubmit || busy" @click="issue">
                {{ t('Confirm and open') }}
            </button></view
        ></Modal
    >
</template>
<style scoped>
.application {
    display: flex;
    flex-direction: column;
    gap: 20px;
    font-size: 14px;
    line-height: 22px;
}
.steps {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    font-size: 14px;
    line-height: 22px;
    color: #68736e;
    margin-bottom: 4px;
}
.steps .current {
    color: #171c19;
    font-weight: 600;
}
.intro {
    display: block;
    font-size: 14px;
    line-height: 22px;
}
.heading {
    display: block;
    font-size: 20px;
    line-height: 28px;
    font-weight: 600;
    margin-bottom: 12px;
}
.pending > text {
    display: block;
    margin-bottom: 16px;
}
.application button,
.review-buttons button {
    font-size: 14px;
    min-height: 44px;
    line-height: 24px;
    padding: 10px 16px;
}
.success {
    color: #047857;
}
.fee-details {
    border-block: 1px solid #e2e7e4;
}
.fee-details > view {
    display: flex;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px 16px;
    border-bottom: 1px solid #e2e7e4;
    padding: 12px 0;
}
.fee-details > view:last-child {
    border: 0;
}
.strong {
    font-weight: 600;
    overflow-wrap: anywhere;
}
.total {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    background: #f0f3f1;
    border-radius: 12px;
    padding: 16px;
}
.wide {
    width: 100%;
}
.review-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    justify-content: flex-end;
}
.review-buttons button {
    margin: 0;
}
</style>
