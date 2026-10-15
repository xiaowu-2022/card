<script setup lang="ts">
import { useFormFeedback } from '../lib/form-feedback';
import { computed, ref, watch, onMounted } from 'vue';
import ProcessingOverlay from './ProcessingOverlay.vue';
import Modal from './Modal.vue';
import CardholderFields from './CardholderFields.vue';
import CardRecipient from './CardRecipient.vue';
import FormField from './FormField.vue';
import FormErrors from './FormErrors.vue';
import { t } from '../lib/i18n';
import { ApiError, request } from '../lib/api';
import { getPage, useAction, requestId, explainError } from '../lib/client';
import { session } from '../lib/session';
import { useSensitiveScreen } from '../lib/sensitive';
import { go } from '../lib/navigation';
import { exactAmount } from '../generated/exact-amount';
import {
    minorUnits,
    moneyFromMinor,
    type Product,
    type Cardholder,
    type CardsPage,
} from '../lib/card-types';
const props = defineProps<{
    product: Product;
    selectedFormFactor: string;
    availableBalance: string | null;
    application?: Cardholder;
    open: boolean;
    birthDateRequired?: boolean;
}>();
const emit = defineEmits<{ close: []; reload: [] }>();
const action = useAction();
const holder = ref(props.application),
    working = ref(false),
    loading = ref(false);
const fields = ref<Record<string, string>>({
    legal_first_name: '',
    legal_last_name: '',
    email: session.value?.user?.email ?? '',
    mobile: '',
    mobile_country_code: 'CN',
});
const original = ref<Record<string, string> | null>(null),
    errors = ref<Record<string, string>>({});
const amount = ref(exactAmount(props.product.minimumInitialLoad)),
    balance = ref(props.availableBalance);
const materialId = ref(props.application?.requestId ?? requestId()),
    issueId = ref(requestId());
const materialUncertain = ref(false),
    issueUncertain = ref(false);
const recipient = ref<InstanceType<typeof CardRecipient> | null>(null);
const factor = computed(() => holder.value?.formFactor ?? props.selectedFormFactor);
const u1Profile = computed(() => props.product.applicationProfile === 'U1');
const ready = computed(() => holder.value?.state === 'ready');
const pending = computed(
    () =>
        materialUncertain.value ||
        (holder.value && !['ready', 'action_required', 'setup'].includes(holder.value.state)),
);
const busy = computed(() => working.value || loading.value || action.pending.value);
const calculation = computed(() => {
    const fee = minorUnits(props.product.openingFee),
        initial = minorUnits(amount.value),
        minimum = minorUnits(props.product.minimumInitialLoad),
        available = balance.value === null ? null : minorUnits(balance.value);
    if (
        !/^(?:0|[1-9]\d{0,9})(?:\.\d{1,2})?$/.test(amount.value) ||
        fee === null ||
        initial === null ||
        minimum === null
    )
        return null;
    return {
        total: moneyFromMinor(fee + initial),
        enough: available !== null && available >= fee + initial,
        meetsMinimum: initial >= minimum,
    };
});
const validFields = computed(
    () =>
        u1Profile.value ||
        ['legal_first_name', 'legal_last_name', 'email', 'mobile', 'mobile_country_code'].every(
            (key) => fields.value[key]?.trim(),
        ) &&
        (!props.birthDateRequired || !!fields.value.date_of_birth),
);
const canSubmit = computed(
    () =>
        props.product.readyForSetup &&
        !pending.value &&
        validFields.value &&
        !!calculation.value?.enough &&
        !!calculation.value?.meetsMinimum &&
        (factor.value !== 'physical_card' || !!recipient.value?.valid),
);
const minimumHint = computed(() =>
    t('Initial balance must be at least ${{amount}}.', {
        amount: exactAmount(props.product.minimumInitialLoad),
    }),
);
const needed = computed(() =>
    t('You need ${{value1}} to open this card.', {
        value1: exactAmount(calculation.value?.total ?? '0'),
    }),
);
let generation = 0;
watch(
    () => props.application,
    (value) => {
        if (!busy.value && value?.requestId === materialId.value) holder.value = value;
    },
);
watch(amount, () => {
    if (!issueUncertain.value) issueId.value = requestId();
});
function close() {
    if (busy.value) return;
    generation++;
    fields.value = {};
    original.value = null;
    emit('reload');
    emit('close');
}
useSensitiveScreen(
    () => {
        generation++;
        fields.value = {};
        original.value = null;
        emit('close');
    },
    { retainUntilUnmount: true },
);
async function loadSavedFields() {
    if (!ready.value || !holder.value?.id) return;
    const run = generation;
    loading.value = true;
    try {
        const data = await request<{ fields: Record<string, unknown> }>(
            '/client/cards/cardholder/' + holder.value.id + '/details',
            'POST',
        );
        if (run !== generation) return;
        const keys = u1Profile.value ? ['email'] : [
            'legal_first_name',
            'legal_last_name',
            'email',
            'mobile',
            'mobile_country_code',
        ];
        if (typeof data.fields.date_of_birth === 'string') keys.push('date_of_birth');
        const saved = Object.fromEntries(
            keys.map((key) => {
                if (typeof data.fields[key] !== 'string') throw new Error();
                return [key, data.fields[key]];
            }),
        ) as Record<string, string>;
        fields.value = saved;
        original.value = { ...saved };
    } catch {
        if (run === generation)
            errors.value = {
                form: t('Cardholder information could not be loaded. Please close and try again.'),
            };
    } finally {
        loading.value = false;
    }
}
onMounted(loadSavedFields);
async function readHolder(run: number) {
    const page = await getPage<CardsPage>('/cards');
    if (run !== generation) return null;
    const current = page.props.cardholder;
    if (
        current.requestId !== materialId.value ||
        current.productId !== props.product.id ||
        !current.id
    )
        throw new Error('Application could not be confirmed');
    holder.value = current;
    balance.value = page.props.availableBalance;
    const product = page.props.products.find((item) => item.id === props.product.id);
    if (
        !product ||
        product.openingFee !== props.product.openingFee ||
        product.cardCurrency !== props.product.cardCurrency ||
        product.minimumInitialLoad !== props.product.minimumInitialLoad
    ) {
        errors.value = {
            form: t(
                'Card fees changed. Close and reopen the application to confirm the current fees.',
            ),
        };
        return null;
    }
    materialUncertain.value = false;
    return current;
}
async function refreshStatus() {
    if (busy.value) return;
    const run = generation;
    working.value = true;
    errors.value = {};
    try {
        if (holder.value?.id && holder.value.canSync)
            await request('/client/cards/cardholder/' + holder.value.id + '/sync', 'POST', {});
        const current = await readHolder(run);
        if (current?.state === 'ready') await loadSavedFields();
        emit('reload');
    } catch (error) {
        if (run === generation) errors.value = explainError(error);
    } finally {
        working.value = false;
    }
}
async function submit() {
    feedback.begin();
    if ((!canSubmit.value && !issueUncertain.value) || busy.value) return;
    const run = generation;
    working.value = true;
    errors.value = {};
    try {
        if (
            !issueUncertain.value &&
            (!ready.value ||
                !original.value ||
                JSON.stringify(fields.value) !== JSON.stringify(original.value))
        ) {
            materialUncertain.value = true;
            await request('/client/cards/cardholder', 'POST', {
                ...fields.value,
                card_product_id: props.product.id,
                form_factor: factor.value,
                request_id: materialId.value,
            });
            if (run !== generation) return;
            const current = await readHolder(run);
            if (!current || current.state !== 'ready') {
                emit('reload');
                return;
            }
            original.value = { ...fields.value };
        }
        if (run !== generation || !ready.value || !holder.value?.id) return;
        let recipientId: string | null = null;
        if (factor.value === 'physical_card') {
            recipientId = (await recipient.value?.save(holder.value.id)) ?? null;
            if (run !== generation || !recipientId) return;
        }
        if (
            !issueUncertain.value &&
            (!calculation.value?.enough || !calculation.value?.meetsMinimum)
        )
            return;
        issueUncertain.value = true;
        const result = await action.submit(
            '/cards/issues',
            {
                request_id: issueId.value,
                card_product_id: props.product.id,
                initial_load_amount: amount.value,
                cardholder_application_id: holder.value.id,
                form_factor: factor.value,
                recipient_application_id: recipientId,
            },
            { navigate: false },
        );
        if (run !== generation) return;
        if (result) {
            issueUncertain.value = false;
            emit('reload');
            emit('close');
        } else if (action.failureStatus.value === 422) issueUncertain.value = false;
    } catch (error) {
        if (run !== generation) return;
        errors.value = explainError(error);
        if (error instanceof ApiError && error.status === 422) {
            materialUncertain.value = false;
            const raw = error.payload?.errors?.form,
                reason = Array.isArray(raw) ? raw[0] : raw;
            if (reason === 'The cardholder could not be added. Check the details and try again.')
                materialId.value = requestId();
        }
    } finally {
        working.value = false;
    }
}
const combinedErrors = computed(() => ({ ...errors.value, ...action.errors.value }));
const feedback = useFormFeedback(combinedErrors);
</script>
<template>
    <ProcessingOverlay
        :open="open && busy"
        :message="t(loading ? 'Loading cardholder information…' : 'Processing card opening…')"
    />
    <Modal
        wide
        compact
        :open="open"
        :title="t('Apply for a card') + ' · ' + product.name"
        :busy="busy"
        @close="close"
    >
        <view class="application">
            <FormField
                v-if="u1Profile"
                name="email"
                v-model="fields.email"
                :label="t('Verification email (optional)')"
                :disabled="busy || pending || issueUncertain"
            />
            <CardholderFields
                v-else
                compact
                fixed-calling-code
                hide-title
                v-model="fields"
                :birth-date-required="birthDateRequired"
                :disabled="busy || pending || issueUncertain"
                :errors="combinedErrors"
            />
            <CardRecipient
                v-if="factor === 'physical_card'"
                ref="recipient"
                embedded
                :application-id="holder?.id ?? undefined"
                :saved="holder?.recipient"
            />
            <FormField
                name="initial_load_amount" :aliases="['amount']"
                v-model="amount"
                :label="t('Initial card balance') + ' (USDT)'"
                type="digit"
                :disabled="busy || issueUncertain"
            />
            <view class="fees">
                <view
                    ><text>{{ t('Opening fee') }}</text
                    ><text>{{ exactAmount(product.openingFee) }} USDT</text></view
                >
                <view
                    ><text>{{ t('Initial card balance') }}</text
                    ><text>{{ exactAmount(amount) }} {{ product.cardCurrency }}</text></view
                >
                <view class="total"
                    ><text>{{ t('Total from Wallet') }}</text
                    ><text
                        >{{ calculation ? exactAmount(calculation.total) : '—' }} USDT</text
                    ></view
                >
            </view>
            <FormErrors :errors="combinedErrors" />
            <text v-if="!calculation?.meetsMinimum" class="error">{{ minimumHint }}</text>
            <text v-if="calculation && !calculation.enough" class="error">{{ needed }}</text>
            <view v-if="!u1Profile" class="notes">
                <text v-if="!u1Profile">{{
                    t(
                        'Your email receives purchase verification codes. Please ensure you can receive these emails.',
                    )
                }}</text>
                <text v-if="!u1Profile">{{
                    t(
                        'Your mobile number receives purchase verification codes. Please ensure it can receive SMS.',
                    )
                }}</text>
            </view>
        </view>
        <template #footer>
            <button
                v-if="pending"
                class="primary open-button"
                :disabled="busy || (!materialUncertain && !holder?.canSync)"
                @click="refreshStatus"
            >
                {{ t('Refresh status') }}
            </button>
            <button
                v-else-if="calculation && !calculation.enough && !issueUncertain"
                class="primary open-button"
                :disabled="busy"
                @click="go('/wallet/top-up')"
            >
                {{ t('Top up wallet') }}
            </button>
            <button
                v-else
                class="primary open-button"
                :disabled="busy || (!canSubmit && !issueUncertain)"
                @click="submit"
            >
                {{ t(busy ? 'Submitting…' : issueUncertain ? 'Check result' : 'Confirm and open') }}
            </button>
        </template>
    </Modal>
</template>
<style scoped>
.application {
    display: flex;
    flex-direction: column;
    gap: 8px;
    font-size: 13px;
    line-height: 20px;
}
.application :deep(.form-field) {
    margin-bottom: 0;
}
.application :deep(.form-label) {
    margin-bottom: 4px;
    font-size: 13px;
}
.application :deep(.form-input) {
    height: 40px;
    min-height: 40px;
    padding: 8px 10px;
    border-radius: 8px;
}
.fees {
    border: 1px solid #e2e7e4;
    border-radius: 8px;
    padding: 6px 10px;
}
.fees > view {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    padding: 3px 0;
}
.total {
    font-weight: 700;
    border-top: 1px solid #e2e7e4;
}
.notes {
    display: flex;
    flex-direction: column;
    gap: 10px;
    color: #68736e;
    border-top: 1px solid #e2e7e4;
    padding-top: 12px;
}
.error {
    color: #b42318;
}
.open-button {
    width: 100%;
    min-height: 48px;
    padding: 12px;
    line-height: 24px;
    font-size: 16px;
    font-weight: 600;
}
</style>
