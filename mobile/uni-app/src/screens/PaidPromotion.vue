<script setup lang="ts">
import { computed, reactive, ref, onBeforeUnmount } from 'vue';
import PageShell from '../components/PageShell.vue';
import StatusBanner from '../components/StatusBanner.vue';
import FinancialConfirmation from '../components/FinancialConfirmation.vue';
import Modal from '../components/Modal.vue';
import FormErrors from '../components/FormErrors.vue';
import { t, dateTime } from '../lib/i18n';
import { go } from '../lib/navigation';
import { useAction, requestId } from '../lib/client';
import { promotionLevel, membershipAction, promotionMoney, promotionUnits } from '../lib/promotion';
import { exactAmount } from '../generated/exact-amount';
import type { PaidPromotionData, PaidLevel } from '../lib/promotion-types';
type Quote = {
    id: string;
    rank: number;
    amount: string;
    previousTariff: string;
    depositApplied: string;
    settlementTotal: string;
    tariff: string;
    expiresAt: string;
    status: string;
    cycleId: string | null;
};
const props = defineProps<{ page: { paid: PaidPromotionData; quote: Quote | null } }>(),
    emit = defineEmits<{ reload: [] }>();
const p = computed(() => props.page.paid),
    q = computed(() => props.page.quote),
    form = reactive({ level_id: '', request_id: requestId() }),
    action = useAction(),
    clock = ref(Date.now()),
    expanded = ref<Record<string, boolean>>({}),
    verificationOpen = ref(!p.value.paymentAccess.verified);
const timer = setInterval(() => (clock.value = Date.now()), 1000);
onBeforeUnmount(() => clearInterval(timer));
const ready = computed(() => p.value.paymentAccess.verified && p.value.paymentAccess.walletActive),
    available = computed(() => p.value.levels.filter((l) => l.selectable)),
    expired = computed(
        () =>
            !!q.value &&
            q.value.status === 'QUOTED' &&
            new Date(q.value.expiresAt).getTime() <= clock.value,
    ),
    insufficient = computed(
        () =>
            !!q.value && promotionUnits(p.value.availableBalance) < promotionUnits(q.value.amount),
    ),
    quotedLevel = computed(() => p.value.levels.find((l) => l.rank === q.value?.rank)),
    quoteUnavailable = computed(
        () => q.value?.status === 'QUOTED' && !quotedLevel.value?.selectable,
    ),
    blocked = computed(
        () =>
            p.value.manualLevel ||
            p.value.pending ||
            p.value.activation.refundPending ||
            !ready.value ||
            action.pending.value,
    );
const title = computed(() =>
    t(p.value.activation.qualified ? membershipAction(p.value) : 'Activate your account'),
);
const expiry = computed(() =>
    p.value.manualLevel
        ? ''
        : p.value.cycle
          ? t('Valid until {{time}}', { time: dateTime(p.value.cycle.endsAt) })
          : t('Ordinary members earn 20 USDT for direct activation only.'),
);
const previousExpiry = computed(() =>
    p.value.previousCycle
        ? t('Your previous level expired on {{time}}. Ordinary member rewards now apply.', {
              time: dateTime(p.value.previousCycle.endsAt),
          })
        : '',
);
function rateCopy(value: number) {
    return t('Annual fee reward rate: {{rate}}%', { rate: value });
}
function rewardCopy(value: string) {
    return t('Direct activation reward: {{amount}} USDT per event', { amount: value });
}
function targetCopy(level: PaidLevel) {
    return t(
        'Automatic return requires {{target}} weighted activated accounts. Each direct account counts as 1; each indirect account as 0.5.',
        { target: level.target },
    );
}
const quoteTime = computed(() =>
    q.value ? t('Quote valid until {{time}}', { time: dateTime(q.value.expiresAt) }) : '',
);
const warning = computed(() =>
    q.value
        ? t(
              'Convert {{deposit}} USDT of deposit and pay {{amount}} USDT from your wallet for {{level}}. The {{total}} USDT total becomes annual fee; the converted deposit cannot be refunded separately.',
              {
                  deposit: exactAmount(q.value.depositApplied),
                  amount: exactAmount(q.value.amount),
                  total: exactAmount(q.value.settlementTotal),
                  level: promotionLevel(q.value.rank),
              },
          )
        : '',
);
const quoteRows = computed(() =>
    q.value
        ? [
              ['Target level', promotionLevel(q.value.rank)],
              ['Annual fee', promotionMoney(q.value.tariff)],
              ...(promotionUnits(q.value.previousTariff) > 0n
                  ? [['Purchased tariff', promotionMoney(q.value.previousTariff)]]
                  : []),
              ['Annual fee settlement total', promotionMoney(q.value.settlementTotal)],
              ['Deposit converted to annual fee', promotionMoney(q.value.depositApplied)],
              ['Wallet payment', promotionMoney(q.value.amount)],
              ['Available USDT balance', promotionMoney(p.value.availableBalance)],
          ]
        : [],
);
function select(id: string) {
    if (blocked.value) return;
    form.level_id = id;
    form.request_id = requestId();
}
async function submit() {
    if (blocked.value || !form.level_id) return;
    if (form.level_id === 'ordinary') {
        go('/security-deposit');
        return;
    }
    if (!available.value.some((l) => l.id === form.level_id)) return;
    await action.submit('/promotion/quotes', { ...form });
}
</script>
<template>
    <PageShell :title="title" back="/promotion" active="account"
        ><view class="membership"
            ><view class="summary"
                ><text class="small muted">{{ t('My promotion level') }}</text
                ><text class="level-title">{{ promotionLevel(p.rank) }}</text
                ><text v-if="expiry">{{ expiry }}</text
                ><text v-if="p.membershipStatus === 'EXPIRED'">{{ previousExpiry }}</text
                ><text class="small muted">{{ rateCopy(p.percent) }}</text
                ><text class="small muted">{{ rewardCopy(p.reward) }}</text
                ><text class="small muted">{{
                    t(
                        'Ordinary members pay a deposit with no annual fee. Active agents are exempt from the deposit.',
                    )
                }}</text></view
            ><StatusBanner
                v-if="!p.paymentAccess.verified"
                tone="warning"
                :title="t('Complete identity verification')"
                :description="t('Verify your identity before using financial services.')"
                :action="{ label: t('Verify now'), href: '/kyc' }"
            /><view v-else-if="!ready" class="panel"><text>{{ t('Wallet access is restricted') }}</text></view
            ><Modal
                :open="verificationOpen && !p.paymentAccess.verified"
                :title="t('Complete identity verification')"
                :description="t('Verify your identity before using financial services.')"
                @close="verificationOpen = false"
                ><button class="primary" @click="go('/kyc')">{{ t('Verify now') }}</button></Modal
            ><view class="steps"
                ><view
                    v-for="(step, index) in [
                        'Choose promotion level',
                        'Review promotion payment',
                        'Confirm promotion payment',
                    ]"
                    :key="step"
                    :class="{ current: (q?.status === 'COMPLETED' ? 2 : q ? 1 : 0) === index }"
                    ><text>{{ index + 1 }}</text
                    >{{ t(step) }}</view
                ></view
            ><FormErrors :errors="action.errors.value" />
            <form v-if="!q" @submit="submit">
                <text class="heading">{{ t('Choose promotion level') }}</text
                ><text class="small muted intro">{{
                    t(
                        p.cycle
                            ? 'Upgrades charge the difference from your purchased tariff and retain the current expiry date.'
                            : 'Choose a member deposit or an annual agent level.',
                    )
                }}</text
                ><text v-if="p.activation.refundPending" class="message">{{
                    t('Cancel the pending security deposit refund before continuing.')
                }}</text
                ><text v-if="p.pending" class="message">{{
                    t('Annual fee return is processing. Try upgrading shortly.')
                }}</text
                ><text v-if="!available.length" class="message">{{
                    t(
                        p.rank > 0 && p.rank >= p.upgradeEligibility.highestEnabledRank
                            ? 'You have the highest promotion level.'
                            : 'No promotion levels are currently available.',
                    )
                }}</text
                ><view
                    v-if="!p.activation.agent && p.activation.ordinaryAvailable"
                    class="level"
                    :class="{ selected: form.level_id === 'ordinary', disabled: blocked }"
                    @click="select('ordinary')"
                    ><view class="radio" :class="{ checked: form.level_id === 'ordinary' }" /><view
                        class="level-body"
                        ><text class="heading">{{ promotionLevel(0) }}</text
                        ><text class="small muted">{{ t('No annual fee') }}</text
                        ><text class="price">{{
                            promotionMoney(p.activation.depositRequired)
                        }}</text
                        ><text class="small muted">{{ t('Security deposit') }}</text
                        ><text class="small muted">{{
                            t(
                                'Your deposit can be converted into an agent annual fee when upgrading.',
                            )
                        }}</text></view
                    ></view
                ><view
                    v-for="level in p.levels"
                    :key="level.id"
                    class="level-wrapper"
                    :class="{ selected: form.level_id === level.id }"
                    ><view
                        class="level no-border"
                        :class="{ disabled: blocked || !level.selectable }"
                        @click="level.selectable && select(level.id)"
                        ><view
                            class="radio"
                            :class="{ checked: form.level_id === level.id }"
                        /><view class="level-body"
                            ><text class="heading">{{ promotionLevel(level.rank) }}</text
                            ><text
                                v-if="!level.selectable && level.unavailableReason"
                                class="small muted"
                                >{{ t(level.unavailableReason) }}</text
                            ><text class="price"
                                >{{ promotionMoney(level.fee) }}
                                <text class="small">{{ t('per year') }}</text></text
                            ><text class="small muted">{{ rateCopy(level.percent) }}</text
                            ><text class="small muted">{{ rewardCopy(level.reward) }}</text></view
                        ></view
                    ><button class="conditions" @click="expanded[level.id] = !expanded[level.id]">
                        {{ expanded[level.id] ? '−' : '+' }}
                        {{ t('Annual fee rebate conditions') }}</button
                    ><view v-if="expanded[level.id]" class="condition-copy small muted"
                        ><text>{{ targetCopy(level) }}</text
                        ><text>{{
                            t(
                                'Each account counts once on its first member deposit or agent purchase. Annual fees are returned automatically when the target is reached.',
                            )
                        }}</text></view
                    ></view
                ><button
                    v-if="
                        available.length || (!p.activation.agent && p.activation.ordinaryAvailable)
                    "
                    class="primary wide"
                    form-type="submit"
                    :disabled="
                        blocked ||
                        !form.level_id ||
                        (form.level_id !== 'ordinary' &&
                            !available.some((l) => l.id === form.level_id))
                    "
                >
                    {{
                        t(
                            form.level_id === 'ordinary'
                                ? 'Pay security deposit'
                                : 'Next: review fees',
                        )
                    }}
                </button>
            </form>
            <view v-else class="panel quote"
                ><text class="heading">{{
                    t(
                        q.status === 'COMPLETED'
                            ? 'Promotion payment completed.'
                            : 'Review promotion payment',
                    )
                }}</text
                ><view v-for="row in quoteRows" :key="row[0]" class="quote-row"
                    ><text class="muted">{{ t(row[0]) }}</text
                    ><text>{{ row[1] }}</text></view
                ><text class="small muted">{{
                    t(
                        promotionUnits(q.previousTariff) > 0n
                            ? 'Upgrades charge the difference from your purchased tariff and retain the current expiry date.'
                            : 'Choose a level and pay to activate one year of membership. No automatic renewal.',
                    )
                }}</text
                ><text v-if="p.cycle && expiry" class="small">{{ expiry }}</text
                ><template v-if="q.status === 'QUOTED'"
                    ><text class="small">{{ quoteTime }}</text
                    ><text v-if="promotionUnits(q.depositApplied) > 0n" class="small muted">{{
                        t(
                            'The converted deposit becomes annual fee and is no longer refundable as a deposit. Commission uses only the wallet payment; annual fee returns include the converted deposit.',
                        )
                    }}</text
                    ><text v-if="expired" class="error">{{
                        t('The payment quote has expired. Review fees again.')
                    }}</text
                    ><text v-if="quoteUnavailable" class="error">{{
                        t(
                            quotedLevel?.unavailableReason ??
                                'Promotion terms changed. Request a new quote.',
                        )
                    }}</text
                    ><text v-if="insufficient" class="error">{{
                        t('Your available balance is not enough.')
                    }}</text
                    ><FinancialConfirmation
                        :title="t('Confirm promotion payment')"
                        :warning="warning"
                        :url="'/promotion/quotes/' + q.id + '/confirm'"
                        :payload="{}"
                        :disabled="quoteUnavailable || expired || insufficient || blocked"
                        @completed="emit('reload')"
                    /><button class="text-button" @click="go('/promotion/membership', true)">
                        {{ t('Choose again and review fees') }}
                    </button></template
                ><button
                    v-if="q.status === 'COMPLETED'"
                    class="text-button"
                    @click="go('/promotion')"
                >
                    {{ t('Back to promotion') }}
                </button></view
            ><button class="text-button back" @click="go('/promotion')">
                {{ t('Back to promotion') }}
            </button></view
        ></PageShell
    >
</template>
<style scoped>
.membership {
    max-width: 672px;
    margin: auto;
    display: flex;
    flex-direction: column;
    gap: 20px;
    padding-bottom: 20px;
}
.summary {
    padding: 20px;
    border-radius: 16px;
    background: #f2f6ef;
    font-size: 14px;
    line-height: 22px;
}
.summary > text {
    display: block;
    margin-top: 8px;
}
.summary > text:first-child {
    margin-top: 0;
}
.small {
    font-size: 12px;
    line-height: 20px;
}
.level-title {
    font-size: 20px;
    font-weight: 600;
}
.steps {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 8px;
    font-size: 12px;
    line-height: 18px;
}
.steps > view {
    padding: 12px;
    background: #f0f3f1;
    border-radius: 8px;
}
.steps text {
    display: block;
    font-weight: 600;
    margin-bottom: 4px;
}
.steps .current {
    background: #e5f0e9;
}
.panel {
    padding: 20px;
    border: 1px solid #e2e7e4;
    border-radius: 16px;
    background: white;
}
.heading {
    display: block;
    font-size: 16px;
    font-weight: 600;
    line-height: 24px;
}
.intro {
    display: block;
    margin: 16px 0;
}
.message {
    display: block;
    padding: 16px 0;
    font-size: 14px;
    line-height: 22px;
}
.level,
.level-wrapper {
    margin-bottom: 12px;
    padding: 16px;
    border: 1px solid #e2e7e4;
    border-radius: 12px;
    background: white;
}
.level {
    display: flex;
    gap: 12px;
}
.level-wrapper .level {
    padding: 0;
    margin: 0;
    background: transparent;
}
.no-border {
    border: 0;
}
.selected {
    border-color: #065f46;
    background: #ecfdf580;
}
.disabled {
    opacity: 0.65;
}
.level-body {
    flex: 1;
    min-width: 0;
}
.level-body > text {
    display: block;
    margin-top: 8px;
}
.level-body > text:first-child {
    margin: 0;
}
.price {
    font-size: 18px;
    font-weight: 600;
    overflow-wrap: anywhere;
}
.radio {
    width: 16px;
    height: 16px;
    border: 1px solid #85978a;
    border-radius: 50%;
    margin-top: 4px;
    flex-shrink: 0;
    box-sizing: border-box;
}
.radio.checked {
    border: 5px solid #065f46;
}
.conditions {
    background: none;
    border-top: 1px solid #e2e7e4;
    margin-top: 12px;
    padding: 8px 0 0;
    font-size: 12px;
    line-height: 24px;
    text-align: left;
}
.condition-copy text {
    display: block;
    margin-top: 8px;
}
.wide {
    min-height: 48px;
    width: 100%;
    border-radius: 999px;
    margin-top: 16px;
}
.quote {
    display: flex;
    flex-direction: column;
    gap: 16px;
}
.quote-row {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: 8px;
    font-size: 14px;
    overflow-wrap: anywhere;
}
.error {
    font-size: 14px;
    color: #b42318;
}
.text-button {
    padding: 8px 0;
    background: none;
    font-size: 14px;
    text-decoration: underline;
    line-height: 22px;
    margin: 0;
}
.back {
    padding: 12px 0;
    text-align: center;
}
</style>
