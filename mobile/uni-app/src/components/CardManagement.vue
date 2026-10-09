<script setup lang="ts">
import { computed, ref, onBeforeUnmount } from 'vue';
import Modal from './Modal.vue';
import UiIcon from './UiIcon.vue';
import FormField from './FormField.vue';
import FormErrors from './FormErrors.vue';
import ConfirmCheck from './ConfirmCheck.vue';
import CardTransactions from './CardTransactions.vue';
import CardholderFields from './CardholderFields.vue';
import { t, dateTime } from '../lib/i18n';
import { request, ApiError } from '../lib/api';
import { requestId, explainError } from '../lib/client';
import { useSensitiveScreen } from '../lib/sensitive';
import { systemMoney } from '../generated/system-money';
import { cardReloadBalance, displayMoney } from '../generated/exact-amount';
import { cardholderChanges, holderEditFields } from '../generated/cardholder-changes';
import {
    cardActionLabels as labels,
    cardShortLabels as shortLabels,
    cardOperationStates as states,
    record,
    operation,
    type CardOperation,
    type ManagedCard,
} from '../lib/card-operations';
import { minorUnits } from '../lib/card-types';
const props = defineProps<{
        card: ManagedCard;
        availableBalance: string | null;
        walletAsset: string | null;
    }>(),
    emit = defineEmits<{ reload: [] }>();
const active = ref<string | null>(null),
    more = ref(false),
    password = ref(''),
    amount = ref(''),
    fields = ref<Record<string, string>>({}),
    original = ref<Record<string, string>>({}),
    holderLoaded = ref(false),
    busy = ref(false),
    errors = ref<Record<string, string>>({}),
    details = ref<{ pan: string; cvv: string } | null>(null),
    copyNotice = ref(''),
    order = ref<CardOperation | null>(null),
    history = ref<CardOperation[]>([]),
    confirmed = ref(false),
    intent = ref(requestId()),
    uncertain = ref(false);
let generation = 0,
    visible = false,
    revealTimer: ReturnType<typeof setTimeout> | undefined;
const capabilities = computed(() => props.card.management ?? []),
    actions = computed(() =>
        props.card.refundLocked
            ? ['transactions']
            : [
                  'reveal',
                  props.card.state === 'Frozen' ? 'unfreeze' : 'load',
                  'return',
                  'transactions',
              ],
    ),
    moreActions = computed(() =>
        ['unfreeze', 'holder'].filter(
            (a) => capabilities.value.includes(a) && !actions.value.includes(a),
        ),
    );
const icons: Record<string, string> = {
    reveal: 'eye',
    transactions: 'list',
    holder: 'user-round',
    load: 'plus',
    return: 'undo2',
    cancel: 'x',
    freeze: 'lock-keyhole',
    unfreeze: 'unlock',
};
const terminal = computed(
        () => !!order.value && ['completed', 'declined', 'expired'].includes(order.value.state),
    ),
    needsPassword = computed(
        () =>
            !!active.value &&
            !['load', 'transactions', 'history', 'refresh'].includes(active.value) &&
            !terminal.value &&
            order.value?.state !== 'confirming',
    ),
    needsConfirmation = computed(() => needsPassword.value && active.value !== 'reveal');
const insufficientReloadBalance = computed(() => {
    if (active.value !== 'load' || order.value || props.availableBalance === null) return false;
    const available = minorUnits(props.availableBalance);
    const required =
        amount.value === ''
            ? minorUnits(props.card.minimumReload ?? '0')
            : /^\d+(?:\.\d{1,2})?$/.test(amount.value)
              ? minorUnits(amount.value)
              : null;
    return available !== null && required !== null && required > available;
});
const amountError = computed(() => {
    if (order.value || !['load', 'return'].includes(active.value ?? '')) return '';
    if (insufficientReloadBalance.value) return t('Your available balance is not enough.');
    if (!amount.value) return '';
    const parsed = minorUnits(amount.value);
    if (!/^\d+(?:\.\d{1,2})?$/.test(amount.value) || parsed === null || parsed <= 0n)
        return t('Enter a positive amount with at most 2 decimal places.');
    if (active.value === 'load' && parsed < (minorUnits(props.card.minimumReload ?? '0') ?? 0n))
        return t('The amount is below this card’s minimum reload.');
    if (active.value === 'return' && props.card.balance !== null && parsed > (minorUnits(props.card.balance) ?? 0n))
        return t('The return amount exceeds the available card balance.');
    return '';
});
const projected = computed(() => cardReloadBalance(amount.value, props.card.balance)),
    amountValid = computed(() => {
        if (!/^\d+(?:\.\d{1,2})?$/.test(amount.value)) return false;
        const parsed = minorUnits(amount.value);
        return (
            parsed !== null &&
            parsed > 0n &&
            (active.value !== 'load' ||
                (props.availableBalance !== null &&
                    parsed <= (minorUnits(props.availableBalance) ?? 0n) &&
                    parsed >= (minorUnits(props.card.minimumReload ?? '0') ?? 0n))) &&
            (active.value !== 'return' ||
                (props.card.balance !== null && parsed <= (minorUnits(props.card.balance) ?? 0n)))
        );
    });
const pendingCopy = computed(() =>
        t('Pending card operations: {{count}}', { count: props.card.pendingOperationCount ?? 0 }),
    ),
    minimumCopy = computed(() =>
        t('Minimum reload: {{amount}}', { amount: systemMoney(props.card.minimumReload ?? '0') }),
    );
function clearSensitive() {
    generation++;
    visible = false;
    details.value = null;
    password.value = '';
    fields.value = {};
    original.value = {};
    holderLoaded.value = false;
    copyNotice.value = '';
    confirmed.value = false;
    clearTimeout(revealTimer);
    active.value = null;
}
useSensitiveScreen(clearSensitive);
function close() {
    if (busy.value) return;
    clearSensitive();
    active.value = null;
    order.value = null;
    errors.value = {};
    emit('reload');
}
function open(action: string) {
    if (busy.value) return;
    generation++;
    visible = true;
    active.value = action;
    more.value = false;
    password.value = '';
    details.value = null;
    copyNotice.value = '';
    order.value = null;
    errors.value = {};
    amount.value = '';
    fields.value = {};
    original.value = {};
    holderLoaded.value = false;
    confirmed.value = false;
    uncertain.value = false;
    intent.value = requestId();
    clearTimeout(revealTimer);
    if (action === 'holder') void loadHolder();
    if (action === 'history') void run('history');
}
async function post(data: Record<string, unknown>) {
    return record(await request('/client/cards/' + props.card.id + '/management', 'POST', data));
}
async function loadHolder() {
    const runId = generation;
    busy.value = true;
    try {
        const result = await post({ action: 'holder_details' });
        if (runId !== generation || !visible) return;
        const saved = record(result.fields);
        const values = Object.fromEntries(
            holderEditFields.map((key) => {
                if (saved[key] !== undefined && typeof saved[key] !== 'string')
                    throw new Error('Invalid holder');
                return [key, saved[key] ?? ''];
            }),
        ) as Record<string, string>;
        fields.value = { ...values };
        original.value = { ...values };
        holderLoaded.value = true;
    } catch {
        if (runId === generation)
            errors.value = {
                form: t('Cardholder information could not be loaded. Please close and try again.'),
            };
    } finally {
        busy.value = false;
    }
}
async function run(action: string, target?: CardOperation) {
    if (busy.value) return;
    if (action === 'holder' && !holderLoaded.value) return;
    const changes = action === 'holder' ? cardholderChanges(fields.value, original.value) : {};
    if (action === 'holder' && !Object.keys(changes).length) {
        errors.value = { form: t('Enter at least one change.') };
        return;
    }
    if (needsPassword.value && !['history', 'sync', 'refresh'].includes(action) && !password.value)
        return;
    if (
        needsConfirmation.value &&
        !['history', 'sync', 'refresh'].includes(action) &&
        !confirmed.value
    )
        return;
    const runId = generation;
    busy.value = true;
    errors.value = {};
    const input: Record<string, unknown> = { action };
    if (['confirm', 'sync'].includes(action)) input.order_id = target?.id ?? order.value?.id;
    if (['quote', 'return', 'freeze', 'unfreeze', 'cancel', 'holder'].includes(action))
        input.request_id = intent.value;
    if (['quote', 'return'].includes(action)) input.amount = amount.value;
    if (!['quote', 'confirm', 'sync', 'refresh', 'history'].includes(action))
        input.current_password = password.value;
    if (['return', 'freeze', 'unfreeze', 'cancel', 'holder'].includes(action))
        input.confirmed = confirmed.value;
    if (action === 'holder') Object.assign(input, changes);
    try {
        if (input.request_id) uncertain.value = true;
        let result = await post(input);
        if (runId !== generation || !visible) return;
        if (action === 'quote' && result.state === 'quoted') {
            const quoted = operation(result);
            order.value = { ...quoted, state: 'confirming' };
            result = await post({ action: 'confirm', order_id: quoted.id });
            if (runId !== generation || !visible) return;
        }
        uncertain.value = false;
        if (action === 'reveal') {
            if (
                typeof result.pan !== 'string' ||
                !/^\d{12,19}$/.test(result.pan) ||
                typeof result.cvv !== 'string' ||
                !/^\d{3,4}$/.test(result.cvv)
            )
                throw new Error('Invalid card details');
            details.value = { pan: result.pan, cvv: result.cvv };
            clearTimeout(revealTimer);
            revealTimer = setTimeout(() => {
                details.value = null;
                copyNotice.value = '';
            }, 30000);
        } else if (action === 'history') {
            if (!Array.isArray(result.orders)) throw new Error('Invalid history');
            history.value = result.orders.map(operation);
        } else if (action === 'refresh') {
            emit('reload');
        } else {
            const updated = operation(result);
            if (action === 'sync' && target)
                history.value = history.value.map((item) =>
                    item.id === target.id ? updated : item,
                );
            else order.value = updated;
            if (result.state !== 'quoted') emit('reload');
        }
    } catch (e) {
        if (runId === generation) {
            errors.value = explainError(e);
            if (e instanceof ApiError && e.status === 422) uncertain.value = false;
            if (e instanceof ApiError && e.payload?.error?.code === 'USER_OPERATION_RESTRICTED') {
                uncertain.value = false;
                if (order.value?.state === 'confirming') order.value = { ...order.value, state: 'quoted' };
            }
        }
    } finally {
        password.value = '';
        busy.value = false;
    }
}
function submit() {
    if (
        !active.value ||
        (!order.value && ['load', 'return'].includes(active.value) && !amountValid.value)
    )
        return;
    void run(
        active.value === 'load'
            ? order.value?.state === 'quoted'
                ? 'confirm'
                : 'quote'
            : active.value,
    );
}
async function copy(part: 'pan' | 'cvv' | 'all') {
    if (!details.value) return;
    const runId = generation;
    copyNotice.value = '';
    const data =
        part === 'all'
            ? t('Card number') + ': ' + details.value.pan + '\n' + t('Expiry') + ': ' + (props.card.expiry || '—') + '\nCVV: ' + details.value.cvv
            : details.value[part];
    try {
        await new Promise<void>((resolve, reject) =>
            uni.setClipboardData({
                data,
                showToast: false,
                success: () => resolve(),
                fail: reject,
            }),
        );
        if (runId === generation && visible) copyNotice.value = t('Card information copied');
    } catch {
        if (runId === generation && visible)
            copyNotice.value = t('Copy failed. Please select and copy manually.');
    }
}
</script>
<template>
    <view class="card-controls"
        ><view class="actions" :class="{ locked: card.refundLocked }"
            ><button
                v-for="action in actions"
                :key="action"
                :disabled="!capabilities.includes(action) || busy"
                :aria-label="t(labels[action] ?? 'Card management')"
                @click="open(action)"
            >
                <UiIcon :name="icons[action]" :size="16" /><text>{{
                    t(shortLabels[action] ?? 'Card management')
                }}</text></button
            ><button v-if="!card.refundLocked" :disabled="busy" @click="more = !more">
                <UiIcon name="more-horizontal" :size="16" /><text>{{ t('More') }}</text>
            </button></view
        ><view v-if="more" class="more-menu"
            ><button @click="open('history')">{{ t('Card operation history') }}</button
            ><button v-for="action in moreActions" :key="action" @click="open(action)">
                {{ t(labels[action] ?? 'Card management') }}
            </button></view
        ><button
            v-if="!card.refundLocked && (card.pendingOperationCount ?? 0) > 0"
            class="secondary wide"
            @click="open('history')"
        >
            {{ pendingCopy }}</button
        ><text v-if="card.refundLocked" class="locked-copy muted">{{
            t(
                'Cards are locked for the security deposit refund. Only transaction history is available.',
            )
        }}</text
        ><Modal
            :open="active !== null"
            :title="
                t(
                    active === 'history'
                        ? 'Card operation history'
                        : (labels[active ?? ''] ?? 'Card management'),
                )
            "
            :busy="busy"
            @close="close"
            ><CardTransactions v-if="active === 'transactions'" :card-ids="[card.id]" single-card />
            <form v-else @submit="submit">
                <template v-if="active === 'history'"
                    ><text
                        v-if="!busy && !history.length && !Object.keys(errors).length"
                        class="muted"
                        >{{ t('No records yet.') }}</text
                    ><view v-for="item in history" :key="item.id" class="operation-history"
                        ><view
                            ><text class="strong">{{
                                t(
                                    labels[
                                        item.kind === 'holder_update'
                                            ? 'holder'
                                            : item.kind === 'cancel_return'
                                              ? 'return'
                                              : item.kind
                                    ] ?? 'Card management',
                                )
                            }}</text
                            ><text class="small muted">{{ dateTime(item.createdAt) }}</text></view
                        ><view class="right"
                            ><text v-if="item.arrival" class="strong">{{
                                systemMoney(item.arrival)
                            }}</text
                            ><text
                                class="small"
                                :class="item.state === 'completed' ? 'success' : 'muted'"
                                >{{ t(states[item.state] ?? 'Awaiting confirmation') }}</text
                            ><button
                                v-if="item.state === 'confirming'"
                                class="text-button"
                                :disabled="busy"
                                @click="run('sync', item)"
                            >
                                {{ t('Check result') }}
                            </button></view
                        ></view
                    ><text v-if="busy" class="muted">{{ t('Loading…') }}</text
                    ><button
                        v-if="Object.keys(errors).length"
                        class="secondary"
                        :disabled="busy"
                        @click="run('history')"
                    >
                        {{ t('Retry') }}
                    </button></template
                ><template v-else
                    ><template v-if="active === 'holder' && !order"
                        ><CardholderFields
                            v-if="holderLoaded"
                            v-model="fields"
                            editing
                            :disabled="busy || uncertain"
                        /><text v-else-if="busy">{{
                            t('Loading cardholder information…')
                        }}</text></template
                    ><view v-if="active === 'load'" class="amount-row"
                        ><text class="muted">{{ t('Available Wallet balance') }}</text
                        ><text class="strong">{{
                            availableBalance !== null && walletAsset
                                ? displayMoney(availableBalance) + ' ' + walletAsset
                                : t('Unavailable')
                        }}</text></view
                    ><FormField
                        v-if="(active === 'load' || active === 'return') && !order"
                        v-model="amount"
                        :label="t('Card operation amount')"
                        type="digit"
                        :description="active === 'load' ? minimumCopy : undefined"
                        :error="amountError"
                        :disabled="busy || uncertain"
                    /><view v-if="active === 'load' && !order" class="amount-row"
                        ><text class="muted">{{ t('Estimated card balance after reload') }}</text
                        ><text class="strong">{{
                            projected === null ? '—' : systemMoney(projected)
                        }}</text></view
                    ><text v-if="active === 'cancel' && !order" class="danger">{{
                        t(
                            'Card cancellation is permanent. After completion, remaining funds return to your wallet minus applicable fees. Your security deposit requires a separate refund request.',
                        )
                    }}</text
                    ><view v-if="order" class="order-status"
                        ><text class="strong">{{
                            t(states[order.state] ?? 'Awaiting confirmation')
                        }}</text
                        ><template v-if="order.state === 'quoted'"
                            ><text
                                >{{ t('Wallet debit') }}:
                                {{ order.debit === null ? '—' : systemMoney(order.debit) }}</text
                            ><text
                                >{{ t('Card receives') }}:
                                {{
                                    order.arrival === null ? '—' : systemMoney(order.arrival)
                                }}</text
                            ><text
                                >{{ t('Fee') }}:
                                {{ order.fee === null ? '—' : systemMoney(order.fee) }}</text
                            ><text>{{
                                t(
                                    'The quote expires shortly. Confirm only if you accept the displayed amounts.',
                                )
                            }}</text></template
                        ><button
                            v-if="order.state === 'confirming'"
                            class="secondary"
                            :disabled="busy"
                            @click="run('sync')"
                        >
                            {{ t('Check result') }}
                        </button></view
                    ><view v-if="details" class="card-details"
                        ><view class="detail-line"
                            ><view
                                ><text class="muted">{{ t('Card number') }}</text
                                ><text class="pan" selectable>{{
                                    details.pan.replace(/(.{4})(?=.)/g, '$1 ')
                                }}</text></view
                            ><button class="secondary" @click="copy('pan')">
                                {{ t('Copy card number') }}
                            </button></view
                        ><view class="detail-line">
                            <view><text class="muted">{{ t('Expiry') }}</text>
                            <text class="cvv" selectable>{{ card.expiry || '—' }}</text></view>
                        </view
                        ><view class="detail-line"
                            ><view
                                ><text class="muted">CVV</text
                                ><text class="cvv" selectable>{{ details.cvv }}</text></view
                            ><button class="secondary" @click="copy('cvv')">
                                {{ t('Copy CVV') }}
                            </button></view
                        ><button class="primary wide" @click="copy('all')">
                            {{ t('Copy all card information') }}</button
                        ><text v-if="copyNotice">{{ copyNotice }}</text
                        ><text class="small muted">{{
                            t(
                                'Card information hides after 30 seconds or when you leave this page. Copied information remains in your clipboard.',
                            )
                        }}</text></view
                    ><FormField
                        v-if="needsPassword && !details"
                        v-model="password"
                        :label="t('Current password')"
                        password
                        :disabled="busy"
                    /><ConfirmCheck
                        v-if="needsConfirmation"
                        v-model="confirmed"
                        :label="t('I understand and confirm this card operation.')"
                        :disabled="busy"
                    /><button
                        v-if="!terminal && order?.state !== 'confirming' && !details"
                        class="primary wide"
                        form-type="submit"
                        :disabled="
                            busy ||
                            (active === 'holder' && !holderLoaded) ||
                            (!order && ['load', 'return'].includes(active ?? '') && !amountValid) ||
                            (needsPassword && !password) ||
                            (needsConfirmation && !confirmed)
                        "
                    >
                        {{ t(active === 'load' ? 'Reload' : 'Confirm') }}
                    </button></template
                ><FormErrors :errors="errors" /></form></Modal
    ></view>
</template>
<style scoped>
.card-controls {
    padding: 8px 8px 14px;
    position: relative;
}
.actions {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 4px;
}
.actions.locked {
    grid-template-columns: 1fr;
}
.actions button {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 6px;
    min-height: 56px;
    min-width: 0;
    border-radius: 8px;
    padding: 8px 2px;
    background: none;
    font-size: 16px;
    line-height: 24px;
    margin: 0;
}
.actions button[disabled] {
    opacity: 0.5;
}
.actions text {
    max-width: 100%;
    text-align: center;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.more-menu {
    position: absolute;
    right: 8px;
    top: 62px;
    background: white;
    box-shadow: 0 4px 16px #0002;
    z-index: 30;
    border: 1px solid #e2e7e4;
    border-radius: 10px;
    padding: 6px;
    min-width: 180px;
}
.more-menu button {
    display: block;
    width: 100%;
    text-align: left;
    font-size: 14px;
    line-height: 22px;
    padding: 10px 12px;
    background: none;
}
.wide {
    width: 100%;
    font-size: 14px;
    margin-top: 8px;
    line-height: 24px;
    min-height: 44px;
    padding: 10px 16px;
}
.locked-copy {
    display: block;
    text-align: center;
    font-size: 12px;
    line-height: 20px;
}
.operation-history {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 20px;
    padding: 14px 0;
    border-bottom: 1px solid #e2e7e4;
    font-size: 14px;
    line-height: 22px;
}
.small {
    display: block;
    font-size: 12px;
    line-height: 20px;
    margin-top: 6px;
}
.right {
    text-align: right;
}
.strong {
    font-weight: 600;
    overflow-wrap: anywhere;
}
.success {
    color: #047857;
}
.text-button {
    background: none;
    padding: 8px 0;
    font-size: 12px;
    line-height: 20px;
    text-decoration: underline;
}
.amount-row {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: 8px;
    padding: 12px 16px;
    border-radius: 8px;
    background: #f0f3f1;
    margin-bottom: 20px;
    font-size: 14px;
    line-height: 22px;
}
.danger {
    display: block;
    padding: 16px;
    border: 1px solid #fecaca;
    border-radius: 8px;
    background: #fef2f2;
    color: #7f1d1d;
    font-size: 14px;
    line-height: 22px;
    margin-bottom: 20px;
}
.order-status {
    padding: 16px;
    border: 1px solid #e2e7e4;
    border-radius: 12px;
    font-size: 14px;
    line-height: 22px;
    margin-bottom: 20px;
}
.order-status > text {
    display: block;
    margin-bottom: 8px;
}
.order-status button {
    font-size: 14px;
}
.card-details {
    padding: 16px;
    border: 1px solid #e2e7e4;
    border-radius: 12px;
    margin-bottom: 20px;
    font-size: 14px;
    line-height: 22px;
}
.detail-line {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 16px;
}
.detail-line > view {
    min-width: 0;
}
.detail-line button {
    font-size: 14px;
}
.pan,
.cvv {
    display: block;
    overflow-wrap: anywhere;
    font-family: ui-monospace, monospace;
    font-size: 18px;
    line-height: 28px;
}
.cvv {
    font-size: 20px;
}
</style>
