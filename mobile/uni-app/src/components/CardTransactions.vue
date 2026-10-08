<script setup lang="ts">
import { computed, ref, watch, onBeforeUnmount } from 'vue';
import { request } from '../lib/api';
import { t, dateTime } from '../lib/i18n';
import {
    mergeCardTransactions,
    transactionPage,
    transactionMoney,
    transactionStates,
    transactionTitles,
    type CardTransaction,
} from '../generated/card-transactions';
import PageSkeleton from './PageSkeleton.vue';
import UiIcon from './UiIcon.vue';
import Modal from './Modal.vue';
import { useSensitiveScreen } from '../lib/sensitive';
const selected = ref<CardTransaction | null>(null);
useSensitiveScreen(() => {
    selected.value = null;
});
const details = computed(() => {
    const item = selected.value;
    if (!item) return [];
    return [
        [t('Card'), ending(item)],
        [t('Transaction type'), t(transactionTitles[item.type] ?? 'Card transaction')],
        [t('Status'), t(transactionStates[item.state] ?? 'Confirming')],
        [t('Transaction amount'), transactionMoney(item.amount, item.currency)],
        [
            t('Transaction fee'),
            item.feeAmount != null && item.feeCurrency
                ? transactionMoney(item.feeAmount, item.feeCurrency)
                : '—',
        ],
        [
            t('Fee refund'),
            item.feeReturnAmount != null && item.feeReturnCurrency
                ? transactionMoney(item.feeReturnAmount, item.feeReturnCurrency)
                : '—',
        ],
        [
            t(item.timeKind === 'completed' ? 'Completion time' : 'Recorded time'),
            dateTime(item.displayAt),
        ],
        ...(item.merchant && item.merchant !== 'TEST / LOCAL MOCK'
            ? [[t('Merchant'), item.merchant]]
            : []),
        ...(item.note ? [[t('Transaction note'), item.note]] : []),
    ];
});
const props = defineProps<{ cardIds: string[]; singleCard?: boolean }>(),
    items = ref<CardTransaction[]>([]),
    loading = ref(false),
    failed = ref(0),
    hasMore = ref(false);
let generation = 0,
    states: { id: string; page: number; more: boolean; failed: boolean }[] = [];
const key = computed(() => [...props.cardIds].sort().join(','));
function start() {
    selected.value = null;
    generation++;
    states = props.cardIds.map((id) => ({ id, page: 1, more: true, failed: false }));
    items.value = [];
    loading.value = false;
    void run(false);
}
watch(key, start, { immediate: true });
onBeforeUnmount(() => generation++);
async function run(failedOnly: boolean) {
    if (loading.value) return;
    const current = generation;
    loading.value = true;
    const queue = states.filter((s) => (failedOnly ? s.failed : s.more && !s.failed));
    function publish() {
        if (current !== generation) return;
        failed.value = states.filter((s) => s.failed).length;
        hasMore.value = states.some((s) => s.more && !s.failed);
    }
    await Promise.all(
        Array.from({ length: Math.min(3, queue.length) }, async () => {
            while (queue.length && current === generation) {
                const source = queue.shift()!;
                try {
                    const result = await request(
                        '/client/cards/' + source.id + '/transactions?page=' + source.page,
                    );
                    const data = transactionPage(result, source.id, source.page);
                    if (current !== generation) return;
                    items.value = mergeCardTransactions(items.value, data.items);
                    source.page++;
                    source.more = data.hasMore;
                    source.failed = false;
                } catch {
                    if (current === generation) source.failed = true;
                }
                publish();
            }
        }),
    );
    if (current === generation) {
        loading.value = false;
        publish();
    }
}
function ending(item: CardTransaction) {
    return t('Card ending in {{last4}}', { last4: item.last4 });
}
function title(item: CardTransaction) {
    return item.merchant === 'TEST / LOCAL MOCK'
        ? ending(item) + ' · ' + t(transactionTitles[item.type] ?? 'Card transaction')
        : item.merchant || t(transactionTitles[item.type] ?? 'Card transaction');
}
</script>
<template>
    <view class="transactions"
        ><text v-if="!singleCard" class="heading">{{ t('All card transactions') }}</text
        ><view v-if="failed" class="warning"
            ><text>{{ t('Card transactions could not be updated. Please try again later.') }}</text
            ><button class="secondary" :disabled="loading" @click="run(true)">
                {{ t('Retry') }}
            </button></view
        ><view v-if="items.length" class="list"
            ><button
                v-for="item in items"
                :key="item.id"
                class="item"
                :aria-label="t('Transaction details') + ': ' + title(item)"
                @click="selected = item"
            >
                <view class="icon"><UiIcon name="cards" :size="16" /></view
                ><view class="body"
                    ><view class="top"
                        ><view class="merchant"
                            ><text>{{ title(item) }}</text
                            ><text v-if="item.merchant !== 'TEST / LOCAL MOCK'" class="small muted"
                                >{{ ending(item)
                                }}{{
                                    item.merchant
                                        ? ' · ' +
                                          t(transactionTitles[item.type] ?? 'Card transaction')
                                        : ''
                                }}</text
                            ></view
                        ><view class="money"
                            ><text>{{ transactionMoney(item.amount, item.currency) }}</text
                            ><text class="small muted"
                                >{{ t('Transaction fee') }}:
                                {{
                                    item.feeAmount != null && item.feeCurrency
                                        ? transactionMoney(item.feeAmount, item.feeCurrency)
                                        : '—'
                                }}</text
                            ><text
                                v-if="item.feeReturnAmount != null && item.feeReturnCurrency"
                                class="small muted"
                                >{{ t('Fee refund') }}:
                                {{
                                    transactionMoney(item.feeReturnAmount, item.feeReturnCurrency)
                                }}</text
                            ><text v-if="item.state !== 'completed'" class="small muted">{{
                                t(transactionStates[item.state] ?? 'Confirming')
                            }}</text></view
                        ></view
                    ><text class="time muted">{{ dateTime(item.displayAt) }}</text></view
                ><UiIcon name="chevron-right" :size="16" /></button></view
        ><view v-if="!loading && !failed && !items.length" class="empty"
            ><UiIcon name="cards" :size="24" color="#68736e" /><text class="empty-title">{{
                t('No card transactions yet')
            }}</text
            ><text v-if="!singleCard" class="small muted">{{
                t('Transactions from all your cards will appear here.')
            }}</text></view
        ><PageSkeleton v-if="loading && !items.length" compact /><text v-else-if="loading" class="loading muted">{{ t('Loading card transactions...') }}</text
        ><button v-if="!loading && hasMore" class="secondary more" @click="run(false)">
            {{ t('Load more transactions') }}
        </button></view
    >
    <Modal :open="!!selected" :title="t('Transaction details')" @close="selected = null">
        <view v-if="selected" class="detail-list">
            <view v-for="[label, value] in details" :key="label" class="detail-row">
                <text class="muted">{{ label }}</text
                ><text class="detail-value" selectable>{{ value }}</text>
            </view>
        </view>
    </Modal>
</template>
<style scoped>
.transactions {
    min-width: 0;
}
.heading {
    display: block;
    font-size: 18px;
    font-weight: 600;
    line-height: 28px;
    margin-bottom: 16px;
}
.list {
    background: white;
    border-radius: 16px;
    padding: 0 16px;
}
.detail-row {
    display: flex;
    justify-content: space-between;
    gap: 20px;
    padding: 14px 0;
    border-bottom: 1px solid #e2e7e4;
    font-size: 14px;
}
.detail-value {
    text-align: right;
    overflow-wrap: anywhere;
    min-width: 0;
    flex: 1;
    white-space: pre-wrap;
}
.item {
    width: 100%;
    background: transparent;
    text-align: left;
    border-radius: 0;
    align-items: center;
    display: flex;
    min-width: 0;
    gap: 12px;
    padding: 20px 0;
    border-bottom: 1px solid #e2e7e4;
}
.item:last-child {
    border: 0;
}
.icon {
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    border-radius: 50%;
    background: #f0f3f1;
}
.body {
    min-width: 0;
    flex: 1;
}
.top {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    justify-content: space-between;
    gap: 8px 16px;
    font-size: 14px;
    line-height: 22px;
}
.merchant {
    min-width: 0;
    flex: 1;
    flex-basis: 112px;
    font-weight: 500;
    overflow-wrap: anywhere;
}
.money {
    max-width: 100%;
    text-align: right;
    font-weight: 600;
    overflow-wrap: anywhere;
}
.small {
    display: block;
    font-size: 12px;
    font-weight: 400;
    line-height: 20px;
    margin-top: 4px;
}
.time {
    display: block;
    font-size: 12px;
    line-height: 20px;
    margin-top: 8px;
}
.empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    border-radius: 16px;
    background: white;
    padding: 32px 20px;
    text-align: center;
    font-size: 14px;
    line-height: 22px;
}
.empty > text {
    margin-top: 12px;
}
.empty-title {
    color: #171915;
    font-weight: 500;
}
.empty > .small {
    margin-top: 8px;
}
.loading {
    display: block;
    text-align: center;
    font-size: 14px;
    padding: 24px 0;
}
.warning {
    padding: 16px;
    border-radius: 12px;
    background: #fffbeb;
    border: 1px solid #fde68a;
    color: #78350f;
    font-size: 14px;
    line-height: 22px;
    margin-bottom: 16px;
}
.warning button {
    margin-top: 12px;
    font-size: 14px;
}
.more {
    width: 100%;
    margin-top: 16px;
    font-size: 14px;
}
</style>
