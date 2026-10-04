<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import PageShell from '../components/PageShell.vue';
import UiIcon from '../components/UiIcon.vue';
import ReportSummary from '../components/ReportSummary.vue';
import ReportDates from '../components/ReportDates.vue';
import ReportPagination from '../components/ReportPagination.vue';
import SelectField from '../components/SelectField.vue';
import FormField from '../components/FormField.vue';
import Modal from '../components/Modal.vue';
import TeamContext from '../components/TeamContext.vue';
import MemberTeamDetails from '../components/MemberTeamDetails.vue';
import { t, dateTime } from '../lib/i18n';
import { go } from '../lib/navigation';
import { exactAmount } from '../generated/exact-amount';
import {
    rankOptions,
    reportMoney,
    fullMoney,
    reportRank,
    relationLabel,
    visitReport,
    incomeLabels,
    teamHref,
    rememberTeam,
    teamReturnHref,
    memberStatusLabel,
    type ReportFilters,
} from '../lib/promotion-report';
import type { Report, CommissionHistory, Movement, Member } from '../lib/report-types';
const props = defineProps<{
    page: {
        report?: Report;
        history?: CommissionHistory;
        section?: 'daily' | 'direct';
        canViewStock?: boolean;
    };
}>();
const commission = computed(() => !!props.page.history),
    daily = computed(() => props.page.section === 'daily'),
    p = computed(() => props.page.history ?? props.page.report!),
    url = computed(() =>
        commission.value ? '/promotion/commissions' : '/promotion/' + props.page.section,
    ),
    title = computed(() =>
        t(commission.value ? 'Commission details' : daily.value ? 'Daily data' : 'Team members'),
    );
const draft = reactive<Record<string, string>>({}),
    search = ref(''),
    filtersOpen = ref(false),
    expanded = ref<Record<string, boolean>>({}),
    notes = ref(false);
watch(
    () => p.value.filters,
    (f) => {
        if (!commission.value && !daily.value) rememberTeam(f);
        Object.assign(
            draft,
            {
                account_id: '',
                kind: 'all',
                rank: 'all',
                relation: 'all',
                activity: 'all',
                funding: 'all',
            },
            Object.fromEntries(Object.entries(f).map(([k, v]) => [k, String(v ?? '')])),
        );
        search.value = String(f.account_id ?? '');
    },
    { immediate: true },
);
const currentFilters = computed(() => ({
    ...p.value.filters,
    ...(commission.value || daily.value
        ? { date_from: p.value.dateFrom, date_to: p.value.dateTo }
        : {}),
}));
function filter(values: ReportFilters) {
    filtersOpen.value = false;
    visitReport(url.value, currentFilters.value, { ...values, page: 1 });
}
const filterKeys = computed(() =>
    commission.value
        ? ['account_id', 'kind', 'rank', 'relation']
        : daily.value
          ? ['activity']
          : ['account_id', 'rank', 'funding'],
);
const active = computed(() =>
    filterKeys.value.filter((key) => p.value.filters[key] && p.value.filters[key] !== 'all'),
);
const activityLabels: Record<string, string> = {
    invitation: 'Invitation registration',
    activation: 'Deposit payment',
    annual: 'Annual fee payment',
    commission: 'Commission',
};
const purchaseLabels: Record<string, string> = {
    purchase: 'First purchase',
    upgrade: 'Level upgrade',
    renewal: 'Membership renewal',
};
function chip(key: string) {
    const v = String(p.value.filters[key]);
    return key === 'account_id'
        ? v
        : key === 'kind'
          ? t(incomeLabels[v] ?? 'All income')
          : key === 'rank'
            ? v === 'unknown'
                ? t('Historical record · not recorded')
                : reportRank(Number(v))
            : key === 'relation'
              ? relationLabel(v)
              : key === 'activity'
                ? t(activityLabels[v] ?? 'All activity')
                : t(v === 'funded' ? 'Deposit funded' : 'Deposit not funded');
}
const members = computed(() =>
        !daily.value && !commission.value ? ((p.value as Report).items as Member[]) : [],
    ),
    movements = computed(() => (daily.value ? ((p.value as Report).items as Movement[]) : [])),
    commissions = computed(() => props.page.history?.items ?? []),
    report = computed(() => props.page.report),
    rankChoices = computed(() =>
        rankOptions(p.value.ranks, commission.value).map(([value, label]) => ({ value, label })),
    ),
    kindChoices = computed(() => [
        { value: 'all', label: t('All income') },
        ...Object.entries(incomeLabels).map(([value, label]) => ({ value, label: t(label) })),
    ]),
    activityChoices = computed(() => [
        { value: 'all', label: t('All activity') },
        ...Object.entries(activityLabels).map(([value, label]) => ({ value, label: t(label) })),
        ...(props.page.canViewStock ? [{ value: 'stock', label: t('Stock data') }] : []),
    ]);
const countsCopy = computed(() =>
        t('{{direct}} direct members, {{total}} members in total', {
            direct: report.value?.memberCounts?.direct ?? 0,
            total: report.value?.memberCounts?.total ?? 0,
        }),
    ),
    matchingCopy = computed(() =>
        t('{{count}} matching members', { count: report.value?.total ?? 0 }),
    );
const back = computed(() =>
    commission.value && (p.value.filters.source_member || p.value.subject?.id)
        ? teamReturnHref(p.value.subject?.id)
        : !daily.value && p.value.subject?.id
          ? teamReturnHref(
                p.value.breadcrumbs && p.value.breadcrumbs.length > 1
                    ? p.value.breadcrumbs[p.value.breadcrumbs.length - 2]?.id
                    : null,
            )
          : '/promotion/invitations',
);
function apply() {
    filter(Object.fromEntries(filterKeys.value.map((key) => [key, draft[key]])));
}
function reset() {
    filter(
        Object.fromEntries(filterKeys.value.map((key) => [key, key === 'account_id' ? '' : 'all'])),
    );
}
function teamCount(row: Member) {
    return t('Team: {{count}} members', { count: row.teamSize });
}
function commissionDetails(row: CommissionHistory['items'][number]) {
    if (!row.sourceAccountId) return [['Commission', fullMoney(row.amount)], ['Credited at', dateTime(row.occurredAt)]];
    return [
        ['Exact commission amount', fullMoney(row.amount)],
        ['Source account', row.sourceAccountId ?? '—'],
        ['Referral relationship', relationLabel(row.relation ?? 'unknown')],
        [
            p.value.subject?.id ? 'Beneficiary level at settlement' : 'My level at settlement',
            reportRank(row.beneficiaryRank),
        ],
        [
            'Source amount',
            row.sourceAmount == null ? t('Not recorded') : fullMoney(row.sourceAmount),
        ],
        [
            row.kind === 'annual' ? 'Applied commission rate' : 'Applied reward difference',
            row.rate == null
                ? t('Not recorded')
                : exactAmount(row.rate) + ' ' + (row.kind === 'annual' ? '%' : 'USDT'),
        ],
        ...(row.standard != null
            ? [
                  [
                      'Settlement standard',
                      row.standard + ' ' + (row.kind === 'annual' ? '%' : 'USDT'),
                  ],
                  ['Already covered', row.covered + ' ' + (row.kind === 'annual' ? '%' : 'USDT')],
              ]
            : []),
        ...(row.businessAt ? [['Business event time', dateTime(row.businessAt)]] : []),
        ['Credited at', dateTime(row.occurredAt)],
    ];
}
function movementDetails(row: Movement) {
    if (!row.sourceAccountId) return [['Commission', fullMoney(row.amount)], ['Credited at', dateTime(row.occurredAt)]];
    return [
        ['Referral relationship', relationLabel(row.relation)],
        ...(row.sourceAmount !== null
            ? [
                  [
                      row.kind === 'annual' ? 'Annual fee paid' : 'Deposit amount',
                      fullMoney(row.sourceAmount),
                  ],
                  ['My commission', fullMoney(row.amount)],
              ]
            : []),
        ['Business event time', dateTime(row.occurredAt)],
        ...(row.postedAt ? [['Credited at', dateTime(row.postedAt)]] : []),
    ];
}
function activityChange(value: string) {
    if (value === 'stock') go('/promotion/stock');
}
</script>
<template>
    <PageShell :title="title" :back="back" active="account" white
        ><view class="report-page"
            ><TeamContext
                v-if="p.subject && !daily"
                :subject="p.subject"
                :breadcrumbs="p.breadcrumbs"
                :totals="report?.subjectTotals"
            /><ReportSummary
                v-if="commission && p.totals"
                :totals="p.totals"
                :title="t('Commission income')"
            /><view class="report-toolbar"
                ><ReportDates
                    v-if="daily || commission"
                    :period="p"
                    :allow-all="commission"
                    @change="(from, to) => filter({ date_from: from, date_to: to })"
                />
                <form v-else class="member-search" @submit="filter({ account_id: search })">
                    <input
                        v-model="search"
                        type="number"
                        :maxlength="24"
                        :placeholder="t('Search account ID')"
                    /><button class="primary" form-type="submit">{{ t('Search') }}</button>
                </form>
                <button class="report-filter-button" @click="filtersOpen = true">
                    <UiIcon name="sliders-horizontal" :size="16" />{{ t('Filters')
                    }}{{ active.length ? ' (' + active.length + ')' : '' }}
                </button></view
            ><text v-if="daily" class="report-period"
                >{{ t('Reporting period') }}: {{ p.dateFrom }} — {{ p.dateTo }}</text
            ><template v-if="daily && report?.totals && report.counts"
                ><ReportSummary
                    :totals="report.totals"
                    :title="t('Period commission income')"
                /><view class="report-team-counts"
                    ><view
                        v-for="entry in [
                            { key: 'invited' as const, label: 'New invitations' },
                            { key: 'funded' as const, label: 'Deposit payment count' },
                            { key: 'orders' as const, label: 'Annual fee orders' },
                        ]"
                        :key="entry.key"
                        ><text>{{ report.counts[entry.key] }}</text
                        ><text>{{ t(entry.label) }}</text></view
                    ></view
                ><text class="report-section-title">{{ t('Team activity') }}</text></template
            ><view v-if="!daily && !commission" class="report-toolbar"
                ><view class="member-count-copy"
                    ><text>{{ countsCopy }}</text
                    ><text v-if="p.filters.account_id || active.length">{{
                        matchingCopy
                    }}</text></view
                ><SelectField
                    :model-value="String(p.filters.sort ?? 'registered_desc')"
                    :label="t('Member sorting')"
                    :options="[
                        { value: 'registered_desc', label: t('Registration: newest first') },
                        { value: 'registered_asc', label: t('Registration: oldest first') },
                        { value: 'commission_desc', label: t('Commission: highest first') },
                        { value: 'commission_asc', label: t('Commission: lowest first') },
                    ]"
                    @update:model-value="(value) => filter({ sort: value })" /></view
            ><view v-if="active.length" class="report-chips"
                ><button
                    v-for="key in active"
                    :key="key"
                    @click="filter({ [key]: key === 'account_id' ? '' : 'all' })"
                >
                    {{ chip(key) }} ×
                </button></view
            ><Modal :open="filtersOpen" :title="t('Filters')" @close="filtersOpen = false"
                ><template v-if="commission"
                    ><FormField
                        v-model="draft.account_id"
                        :label="t('Source account ID')"
                        type="number"
                        :maxlength="24"
                        :placeholder="t('Search account ID')" /><SelectField
                        v-model="draft.kind"
                        :label="t('Commission type')"
                        :options="kindChoices" /><SelectField
                        v-model="draft.rank"
                        :label="t('Source level at event')"
                        :options="rankChoices" /><SelectField
                        v-model="draft.relation"
                        :label="t('Referral relationship')"
                        :options="[
                            { value: 'all', label: t('All') },
                            { value: 'direct', label: relationLabel('direct') },
                            { value: 'indirect', label: relationLabel('indirect') },
                            { value: 'unknown', label: t('Historical record · not recorded') },
                        ]" /></template
                ><SelectField
                    v-else-if="daily"
                    v-model="draft.activity"
                    :label="t('Team activity')"
                    :options="activityChoices"
                    @update:model-value="activityChange"
                /><template v-else
                    ><SelectField
                        v-model="draft.rank"
                        :label="t('Current promotion level')"
                        :options="rankChoices" /><SelectField
                        v-model="draft.funding"
                        :label="t('Deposit status')"
                        :options="[
                            { value: 'all', label: t('All') },
                            { value: 'funded', label: t('Deposit funded') },
                            { value: 'unfunded', label: t('Deposit not funded') },
                        ]" /></template
                ><view class="report-filter-buttons"
                    ><button class="secondary" @click="reset">{{ t('Reset filters') }}</button
                    ><button class="primary" @click="apply">{{ t('Apply filters') }}</button></view
                ></Modal
            ><view v-if="!p.items.length" class="report-empty"
                ><UiIcon name="receipt-text" :size="26" /><text>{{
                    t(
                        !daily && !commission && !p.filters.account_id && !active.length
                            ? 'No team members yet.'
                            : 'No matching records.',
                    )
                }}</text></view
            ><view
                ><view v-for="row in commissions" :key="row.id" class="report-entry"
                    ><view
                        class="report-entry-summary"
                        @click="expanded[row.id] = !expanded[row.id]"
                        ><view class="report-entry-icon"
                            ><UiIcon name="arrow-down-left" :size="18" /></view
                        ><view class="report-entry-body"
                            ><view class="report-entry-top"
                                ><text class="report-entry-title">{{
                                    t(incomeLabels[row.kind] ?? 'Legacy commission')
                                }}</text
                                ><text class="report-positive"
                                    >{{ (!row.amount.startsWith('-') ? '+' : '') + reportMoney(row.amount) }}</text
                                ></view
                            ><view v-if="row.sourceAccountId" class="report-entry-meta"
                                ><text class="report-account">{{ row.sourceAccountId }}</text
                                ><text>{{ reportRank(row.sourceRank) }}</text></view
                            ><view class="report-entry-meta"
                                ><text>{{ dateTime(row.occurredAt) }}</text
                                ><text v-if="row.relation !== 'unknown'">{{
                                    relationLabel(row.relation ?? 'unknown')
                                }}</text
                                ><UiIcon name="chevron-down" :size="14" /></view></view></view
                    ><view v-if="expanded[row.id]" class="report-entry-detail"
                        ><view v-for="detail in commissionDetails(row)" :key="detail[0]"
                            ><text>{{ t(detail[0]) }}</text
                            ><text>{{ detail[1] }}</text></view
                        ></view
                    ></view
                ><view v-for="row in movements" :key="row.id" class="report-entry"
                    ><view
                        class="report-entry-summary"
                        @click="expanded[row.id] = !expanded[row.id]"
                        ><view class="report-entry-icon"
                            ><UiIcon
                                :name="
                                    row.kind === 'invitation'
                                        ? 'user-plus'
                                        : row.kind === 'annual'
                                          ? 'receipt-text'
                                          : 'shield-check'
                                "
                                :size="18" /></view
                        ><view class="report-entry-body"
                            ><view class="report-entry-top"
                                ><text class="report-entry-title">{{
                                    t(activityLabels[row.kind] ?? 'Team activity')
                                }}</text
                                ><text v-if="row.kind !== 'invitation'" class="report-positive">{{
                                    (!row.amount.startsWith('-') ? '+' : '') + reportMoney(row.amount)
                                }}</text></view
                            ><view v-if="row.sourceAccountId" class="report-entry-meta"
                                ><text class="report-account">{{ row.sourceAccountId }}</text
                                ><text>{{
                                    row.kind === 'invitation'
                                        ? relationLabel(row.relation)
                                        : reportRank(row.sourceRank)
                                }}</text></view
                            ><view class="report-entry-meta"
                                ><text>{{ dateTime(row.occurredAt) }}</text
                                ><text v-if="row.kind !== 'invitation'">{{
                                    t('My commission')
                                }}</text
                                ><UiIcon name="chevron-down" :size="14" /></view
                            ><view
                                v-if="row.firstFunding || row.purchaseKind"
                                class="report-entry-tags"
                                ><text v-if="row.firstFunding">{{ t('First activation') }}</text
                                ><text v-if="row.purchaseKind">{{
                                    t(purchaseLabels[row.purchaseKind] ?? 'Annual fee payment')
                                }}</text></view
                            ></view
                        ></view
                    ><view v-if="expanded[row.id]" class="report-entry-detail"
                        ><view v-for="detail in movementDetails(row)" :key="detail[0]"
                            ><text>{{ t(detail[0]) }}</text
                            ><text>{{ detail[1] }}</text></view
                        ></view
                    ></view
                ><view v-for="row in members" :key="row.id" class="report-member"
                    ><view
                        ><button class="report-member-person" @click="go(teamHref(row.id))">
                            <text v-if="row.displayName">{{ row.displayName }}</text
                            ><view class="report-member-account-line"
                                ><text class="report-account">{{ row.accountId }}</text
                                ><UiIcon name="chevron-right" :size="16"
                            /></view></button
                        ><text v-if="row.maskedEmail" class="report-member-email">{{
                            row.maskedEmail
                        }}</text
                        ><view class="report-member-team-count"
                            ><UiIcon name="users" :size="16" /><text>{{
                                teamCount(row)
                            }}</text></view
                        ></view
                    ><view class="report-member-financial"
                        ><text
                            class="member-status"
                            :class="'member-status-' + row.membershipStatus"
                            >{{ memberStatusLabel(row.membershipStatus, row.rank) }}</text
                        ><view class="member-commission-summary"
                            ><text>{{ t('Commission contributed') }}</text
                            ><text class="amount">{{
                                reportMoney(row.totals.total).replace(' USDT', '')
                            }}</text
                            ><text class="currency">USDT</text></view
                        ><MemberTeamDetails
                            :member="row"
                            :subject="p.subject?.id"
                            :beneficiary="p.subject?.accountId ?? ''" /></view></view></view
            ><ReportPagination
                :page="p.page"
                :has-more="p.hasMore"
                @change="(page) => visitReport(url, currentFilters, { page })"
            /><view class="report-notes"
                ><button @click="notes = !notes">
                    {{ notes ? '−' : '+' }} {{ t('Statistics notes') }}</button
                ><template v-if="notes"
                    ><text>{{
                        t(
                            commission
                                ? 'Income totals include all matching records. Levels and relationships use settlement snapshots; missing historical details are not inferred.'
                                : daily
                                  ? 'Income uses posting time; team activity uses event time. Activity filters do not change period totals.'
                                  : 'Member levels are currently effective. Income belongs to the account being viewed, including historical rewards.',
                        )
                    }}</text
                    ><text>{{ t('Dates and times follow the company timezone.') }}</text></template
                ></view
            ></view
        ></PageShell
    >
</template>
<style src="../styles/reports.css"></style>
