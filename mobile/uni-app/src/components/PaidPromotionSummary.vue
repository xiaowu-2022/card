<script setup lang="ts">
import { computed, ref } from 'vue';
import UiIcon from './UiIcon.vue';
import { locale, t } from '../lib/i18n';
import { go } from '../lib/navigation';
import { exactAmount } from '../generated/exact-amount';
import {
    commissionSum,
    promotionMoney,
    promotionTableAmount,
    promotionLevel,
} from '../lib/promotion';
import type { PaidPromotionData } from '../lib/promotion-types';
const props = defineProps<{ paid: PaidPromotionData }>(),
    showAll = ref(true),
    expanded = ref<number | null>(null),
    notes = ref(false);
const rows = computed(() =>
    props.paid.tables.ACTIVATION.map((activation) => {
        const annual = props.paid.tables.ANNUAL.find((r) => r.rank === activation.rank)!,
            people = props.paid.teamByLevel.find((r) => r.rank === activation.rank)!;
        return {
            rank: activation.rank,
            directPeople: people.direct,
            indirectPeople: people.indirect,
            ANNUAL: annual,
            ACTIVATION: activation,
            annualAmount: commissionSum(annual.direct.amount, annual.indirect.amount),
            activationAmount: commissionSum(activation.direct.amount, activation.indirect.amount),
            hasRecords: [
                annual.direct,
                annual.indirect,
                activation.direct,
                activation.indirect,
            ].some((c) => c.count > 0 || !/^0(?:\.0+)?$/.test(c.amount)),
        };
    }),
);
const visible = computed(() =>
    rows.value.filter(
        (r) => showAll.value || r.hasRecords || r.directPeople > 0 || r.indirectPeople > 0,
    ),
);
const kinds = ['ANNUAL', 'ACTIVATION'] as const,
    relations = ['direct', 'indirect'] as const;
function count(kind: string, value: number) {
    return t(
        kind === 'ANNUAL' ? '{{count}} paid orders' : '{{count}} ordinary member activations',
        { count: value },
    );
}
function price(cell: { minimum: string; maximum: string }) {
    return cell.minimum === cell.maximum
        ? exactAmount(cell.minimum)
        : exactAmount(cell.minimum) + '–' + exactAmount(cell.maximum);
}
function tableHeading(label: string) {
    const translated = t(label);
    return locale.value === 'zh-CN' && label !== 'Level'
        ? translated.replace(/\s/g, '').replace(/^(.{2})(.{2})$/, '$1\n$2')
        : translated;
}
</script>
<template>
    <view class="summary"
        ><view class="heading"
            ><text>{{ t('Reward breakdown') }}</text
            ><button @click="showAll = !showAll">
                {{ t(showAll ? 'Show populated levels' : 'Show all levels') }}</button
            ><text class="unit muted">{{ t('Amounts in USDT') }}</text></view
        ><view class="table-head table-row"
            ><text
                v-for="label in [
                    'Level',
                    'Direct members',
                    'Indirect members',
                    'Annual fee commission',
                    'Activation commission',
                ]"
                :key="label"
                >{{ tableHeading(label) }}</text
            ></view
        ><view class="table-row">
            <text>{{ t('Registered member') }}</text>
            <text>{{ paid.registeredMembers?.direct ?? 0 }}</text>
            <text>{{ paid.registeredMembers?.indirect ?? 0 }}</text>
            <text>—</text><text>—</text> </view
        ><view v-for="row in visible" :key="row.rank"
            ><view class="table-row"
                ><button
                    :aria-expanded="expanded === row.rank"
                    @click="expanded = expanded === row.rank ? null : row.rank"
                >
                    <text class="level-label">{{ promotionLevel(row.rank) }}</text>
                    <UiIcon
                        name="chevron-down"
                        :size="12"
                        :class="{ 'level-expanded': expanded === row.rank }"
                    /></button
                ><text>{{ row.directPeople }}</text
                ><text>{{ row.indirectPeople }}</text
                ><text>{{ row.rank ? promotionTableAmount(row.annualAmount) : '—' }}</text
                ><text>{{ promotionTableAmount(row.activationAmount) }}</text></view
            ><view v-if="expanded === row.rank" class="detail"
                ><view v-for="kind in kinds" :key="kind"
                    ><text class="detail-title">{{
                        t(kind === 'ANNUAL' ? 'Annual fee commission' : 'Activation commission')
                    }}</text
                    ><text v-if="kind === 'ANNUAL' && row.rank === 0" class="small muted">{{
                        t('Ordinary members do not earn annual fee commission.')
                    }}</text
                    ><template v-else
                        ><view v-for="relation in relations" :key="relation" class="cell"
                            ><text
                                >{{ t(relation === 'direct' ? 'Direct' : 'Indirect') }} ·
                                {{ count(kind, row[kind][relation].count) }}</text
                            ><text v-if="kind !== 'ANNUAL'" class="muted"
                                >{{ t(relation === 'direct' ? 'Unit price' : 'Difference') }}:
                                {{
                                    row[kind][relation].count
                                        ? price(row[kind][relation]) + ' USDT'
                                        : '—'
                                }}</text
                            ><text class="strong">{{
                                promotionMoney(row[kind][relation].amount)
                            }}</text></view
                        ><button
                            class="records"
                            @click="go('/promotion/rewards?kind=' + kind + '&rank=' + row.rank)"
                        >
                            {{ t('View records') }}
                        </button></template
                    ></view
                ></view
            ></view
        ><text
            v-if="
                !visible.length &&
                !paid.registeredMembers?.direct &&
                !paid.registeredMembers?.indirect
            "
            class="empty muted"
            >{{ t('No team members or reward records yet.') }}</text
        ><text class="note muted">{{
            t('Members: current level. Commission: level at the time earned.') +
            ' ' +
            t(
                'Deposit refunds do not reduce ordinary member counts. Becoming an agent replaces ordinary membership; after agent status ends, a new deposit payment is required.',
            )
        }}</text
        ><button class="notes-button muted" @click="notes = !notes">
            {{ notes ? '−' : '+' }} {{ t('Statistics notes') }}</button
        ><view v-if="notes" class="note muted"
            ><text>{{
                t(
                    'Member counts use current valid levels; commission uses historical event levels. Do not multiply current member counts by current reward rates.',
                )
            }}</text
            ><text>{{
                t('Select a level for actual award records. Ranges reflect historical rates.')
            }}</text></view
        ></view
    >
</template>
<style scoped>
.summary {
    border: 1px solid #e1e7dd;
    border-radius: 16px;
    padding: 16px 12px;
    background: white;
    min-width: 0;
}
.heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 12px;
    font-size: 16px;
    font-weight: 600;
}
.heading button {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 44px;
    font-size: 12px;
    line-height: 18px;
    text-decoration: underline;
    background: none;
    padding: 0;
    margin: 0 0 0 auto;
    min-width: 0;
}
.unit {
    font-size: 11px;
    white-space: nowrap;
    font-weight: 400;
}
.table-row {
    display: grid;
    grid-template-columns: 24% 13% 13% 25% 25%;
    border-bottom: 1px solid #e1e7dd;
    align-items: center;
    font-size: 11px;
    line-height: 18px;
}
.table-row > text {
    padding: 12px 4px;
    overflow-wrap: anywhere;
    text-align: right;
}
.table-row > text:first-child {
    text-align: left;
}
.table-head {
    align-items: end;
    font-weight: 500;
}
.table-head > text {
    white-space: pre-line;
}
.table-row > button {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 4px;
    background: none;
    min-height: 48px;
    width: 100%;
    font-size: 11px;
    line-height: 18px;
    margin: 0;
    padding: 8px 4px;
    text-align: left;
    overflow-wrap: anywhere;
}
.level-label {
    min-width: 0;
}
.level-expanded {
    transform: rotate(180deg);
}
.detail {
    margin: 16px 0;
    padding: 12px;
    border-radius: 12px;
    background: #f0f3f166;
    display: flex;
    flex-direction: column;
    gap: 20px;
}
.detail-title {
    display: block;
    font-size: 14px;
    line-height: 22px;
    font-weight: 500;
    margin-bottom: 12px;
}
.cell {
    font-size: 12px;
    line-height: 20px;
    margin-top: 12px;
}
.cell text {
    display: block;
    margin-top: 4px;
    overflow-wrap: anywhere;
}
.strong {
    font-weight: 500;
}
.small {
    font-size: 12px;
    line-height: 20px;
}
.records {
    min-height: 44px;
    font-size: 12px;
    line-height: 20px;
    text-decoration: underline;
    background: none;
    padding: 12px 0;
    margin: 0;
    text-align: left;
}
.empty {
    display: block;
    text-align: center;
    font-size: 14px;
    padding: 24px 0;
}
.note {
    display: block;
    font-size: 11px;
    line-height: 20px;
    margin-top: 12px;
}
.note text {
    display: block;
}
.notes-button {
    font-size: 12px;
    line-height: 20px;
    background: none;
    padding: 8px 0;
    margin: 0;
    text-align: left;
}
@media (min-width: 640px) {
    .summary {
        padding: 20px;
    }
    .table-row,
    .table-row > button {
        font-size: 14px;
        line-height: 22px;
    }
}
</style>
