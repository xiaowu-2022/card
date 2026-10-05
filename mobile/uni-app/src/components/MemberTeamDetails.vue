<script setup lang="ts">
import { computed, ref } from 'vue';
import Modal from './Modal.vue';
import FormErrors from './FormErrors.vue';
import { t, dateTime } from '../lib/i18n';
import { request } from '../lib/api';
import { explainError } from '../lib/client';
import { go } from '../lib/navigation';
import {
    fullMoney,
    incomeLabels,
    memberStatusLabel,
    type IncomeTotals,
} from '../lib/promotion-report';
import { promotionLevel } from '../lib/promotion';
import type { MemberData } from '../lib/report-types';
type Summary = {
    registeredMembers?: { direct: number; indirect: number };
    totalMembers: number;
    rows: { rank: number; direct: number; indirect: number; annual: string; activation: string }[];
};
const props = defineProps<{ member: MemberData; subject?: string | null; beneficiary: string }>(),
    open = ref(false),
    tab = ref('income'),
    summary = ref<Summary | null>(null),
    loading = ref(false),
    errors = ref<Record<string, string>>({});
const description = computed(() =>
        t('Commission beneficiary: {{account}}', { account: props.beneficiary }),
    ),
    expiry = computed(() =>
        props.member.endsAt
            ? t('Valid until {{time}}', { time: dateTime(props.member.endsAt) })
            : '',
    ),
    total = computed(() =>
        t('{{count}} team members', { count: summary.value?.totalMembers ?? 0 }),
    ),
    note = computed(() =>
        t(
            'Team counts exclude this member and use current levels. Commissions belong to {{account}} from these descendants, grouped by the source level at the time. Teams may overlap; do not add these summaries together.',
            { account: props.beneficiary },
        ),
    );
async function select(value: string) {
    tab.value = value;
    if (value !== 'team' || summary.value || loading.value) return;
    loading.value = true;
    errors.value = {};
    try {
        summary.value = await request<Summary>(
            '/client/promotion/members/' +
                props.member.id +
                '/team-summary' +
                (props.subject ? '?subject=' + encodeURIComponent(props.subject) : ''),
        );
    } catch (e) {
        errors.value = explainError(e);
    } finally {
        loading.value = false;
    }
}
function records() {
    open.value = false;
    go(
        '/promotion/commissions?source_member=' +
            encodeURIComponent(props.member.id) +
            (props.subject ? '&subject=' + encodeURIComponent(props.subject) : ''),
    );
}
</script>
<template>
    <button
        class="member-data-button"
        @click="
            open = true;
            tab = 'income';
        "
    >
        {{ t('View member data') }} ›</button
    ><Modal
        :open="open"
        :title="t('Member data') + ' · ' + member.accountId"
        :description="description"
        @close="open = false"
        ><view class="member-tabs"
            ><button :class="{ selected: tab === 'income' }" @click="select('income')">
                {{ t('Income breakdown') }}</button
            ><button :class="{ selected: tab === 'team' }" @click="select('team')">
                {{ t('Team statistics') }}
            </button></view
        ><view class="identity"
            ><text v-if="member.displayName" class="strong">{{ member.displayName }}</text
            ><text v-if="member.maskedEmail">{{ member.maskedEmail }}</text
            ><text class="status">{{
                memberStatusLabel(member.membershipStatus, member.rank)
            }}</text
            ><text>{{ t('Joined at') }}: {{ dateTime(member.joinedAt) }}</text
            ><text v-if="member.endsAt">{{ expiry }}</text></view
        ><template v-if="tab === 'income'"
            ><view class="money-row total"
                ><text>{{ t('Total') }}</text
                ><text>{{ fullMoney(member.totals.total) }}</text></view
            ><view v-for="(label, key) in incomeLabels" :key="key" class="money-row"
                ><text>{{ t(label) }}</text
                ><text>{{ fullMoney(member.totals[key as keyof IncomeTotals] ?? '0') }}</text></view
            ><button class="records" @click="records">
                {{ t('View commission records') }} ›
            </button></template
        ><template v-else
            ><text v-if="loading">{{ t('Loading team…') }}</text
            ><FormErrors :errors="errors" /><button
                v-if="Object.keys(errors).length"
                class="secondary"
                @click="select('team')"
            >
                {{ t('Retry') }}</button
            ><template v-if="summary"
                ><text v-if="summary.totalMembers === 0">{{ t('No team members yet.') }}</text
                ><template v-else
                    ><text class="team-total">{{ total }} · USDT</text
                    ><view class="team-table"
                        ><view class="table-row header"
                            ><text
                                v-for="label in [
                                    'Level',
                                    'Direct members',
                                    'Indirect members',
                                    'Annual fee commission',
                                    'Activation commission',
                                ]"
                                :key="label"
                                >{{ t(label) }}</text
                            ></view
                        ><view class="table-row"
                            ><text>{{ t('Registered member') }}</text
                            ><text>{{ summary.registeredMembers?.direct ?? 0 }}</text
                            ><text>{{ summary.registeredMembers?.indirect ?? 0 }}</text
                            ><text>—</text><text>—</text></view
                        ><view v-for="row in summary.rows" :key="row.rank" class="table-row"
                            ><text>{{ promotionLevel(row.rank) }}</text
                            ><text>{{ row.direct }}</text
                            ><text>{{ row.indirect }}</text
                            ><text>{{ fullMoney(row.annual).replace(' USDT', '') }}</text
                            ><text>{{ fullMoney(row.activation).replace(' USDT', '') }}</text></view
                        ></view
                    ><text class="note muted">{{ note }}</text></template
                ></template
            ></template
        ></Modal
    >
</template>
<style scoped>
.member-data-button {
    min-height: 36px;
    background: none;
    font-size: 12px;
    line-height: 20px;
    padding: 8px 0;
    margin: 0;
    color: #37604a;
}
.member-tabs {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 4px;
    background: #eef3ee;
    padding: 4px;
    border-radius: 12px;
}
.member-tabs button {
    width: 100%;
    background: none;
    font-size: 14px;
    line-height: 22px;
    min-height: 40px;
    padding: 8px;
    border-radius: 8px;
}
.member-tabs .selected {
    background: white;
    box-shadow: 0 1px 4px #0000000a;
}
.identity {
    padding: 16px 0;
    border-bottom: 1px solid #e1e7dd;
    font-size: 12px;
    line-height: 20px;
}
.identity > text {
    display: block;
    margin-top: 4px;
}
.strong {
    font-weight: 600;
    font-size: 16px;
}
.status {
    color: #37604a;
}
.money-row {
    display: flex;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px;
    padding: 12px 0;
    font-size: 13px;
    line-height: 20px;
}
.money-row.total {
    font-size: 16px;
    font-weight: 600;
    border-bottom: 1px solid #e1e7dd;
}
.records {
    display: block;
    width: 100%;
    text-align: left;
    margin-top: 8px;
    border-top: 1px solid #e1e7dd;
    background: none;
    padding: 12px 0;
    font-size: 14px;
    color: #37604a;
}
.team-total {
    display: block;
    margin: 16px 0;
    font-size: 14px;
}
.table-row {
    display: grid;
    grid-template-columns: 24% 14% 14% 24% 24%;
    font-size: 11px;
    line-height: 18px;
    border-bottom: 1px solid #e1e7dd;
}
.table-row text {
    padding: 10px 3px;
    text-align: right;
    overflow-wrap: anywhere;
}
.table-row text:first-child {
    text-align: left;
}
.header {
    font-weight: 500;
}
.note {
    display: block;
    font-size: 12px;
    line-height: 20px;
    margin-top: 12px;
}
</style>
