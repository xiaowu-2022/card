<script setup lang="ts">
import { ref } from 'vue';
import { onLoad, onShow, onHide } from '@dcloudio/uni-app';
import TextInput from '../../components/TextInput.vue';
import PageShell from '../../components/PageShell.vue';
import { request } from '../../lib/api';
import { requireUser } from '../../lib/session';
import { supportDenied } from '../../lib/support-workspace';
import { t, dateTime } from '../../lib/i18n';
type Amount = { asset: string; amount: string };
type Profile = {
    remark: string | null;
    remarkRevision: number;
    name: string;
    email: string | null;
    accountId: string;
    rank: number;
    partner: boolean;
    registeredAt: string;
    balances: Amount[];
    deposits: Amount[];
    withdrawals: Amount[];
    referrer: { name: string; email: string | null; accountId: string } | null;
};
const conversation = ref(''),
    profile = ref<Profile | null>(null),
    failed = ref(false),
    busy = ref(false);
const remark = ref(''),
    saving = ref(false),
    saveFailed = ref(false);
async function saveRemark() {
    if (!profile.value || saving.value) return;
    saving.value = true;
    saveFailed.value = false;
    try {
        await request(
            '/support-workspace/conversations/' + conversation.value + '/remark',
            'POST',
            { remark: remark.value, revision: profile.value.remarkRevision },
        );
        await load();
    } catch (e) {
        saveFailed.value = true;
        if (supportDenied(e)) profile.value = null;
    } finally {
        saving.value = false;
    }
}
let generation = 0;
onLoad((options) => {
    conversation.value = String(options?.conversation ?? '');
});
async function load() {
    const run = ++generation;
    busy.value = true;
    failed.value = false;
    profile.value = null;
    try {
        if (!(await requireUser())) return;
        const data = await request<Profile>(
            '/support-workspace/conversations/' +
                encodeURIComponent(conversation.value) +
                '/customer',
        );
        if (run === generation) {
            profile.value = data;
            remark.value = data.remark ?? '';
        }
    } catch (error) {
        if (run === generation) {
            failed.value = true;
            supportDenied(error);
        }
    } finally {
        if (run === generation) busy.value = false;
    }
}
onShow(load);
onHide(() => {
    generation++;
    profile.value = null;
});
const rankLabel = (rank: number) =>
    rank > 0 ? t('Agent level {{rank}}', { rank }) : t('Not an agent');
const amounts = (values: Amount[]) =>
    values.length
        ? values
              .map((v) => v.amount.replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '') + ' ' + v.asset)
              .join('\n')
        : '0';
</script>
<template>
    <PageShell
        :title="t('Customer profile')"
        :back="'/support-workspace/chat?conversation=' + conversation"
        history-back
        hide-messages
        active="account"
    >
        <text v-if="busy">{{ t('Loading…') }}</text>
        <view v-else-if="failed"
            ><text>{{ t('Unable to load. Please try again.') }}</text
            ><button @click="load">{{ t('Retry loading conversations') }}</button></view
        >
        <view v-else-if="profile" class="profile">
            <view class="identity"
                ><view class="avatar">{{ Array.from(profile.name)[0] }}</view
                ><text class="name">{{ profile.name }}</text
                ><text>{{ profile.email }}</text
                ><text>{{ profile.accountId }}</text></view
            >
            <view class="details">
                <view
                    ><text>{{ t('Customer name') }}</text
                    ><text>{{ profile.name }}</text></view
                >
                <view class="remark-editor"
                    ><text>{{ t('Customer remark') }}</text
                    ><view
                        ><TextInput
                            v-model="remark"
                            :maxlength="60"
                            :disabled="saving"
                            :aria-label="t('Customer remark')"
                        /><button
                            :disabled="saving || remark === (profile.remark ?? '')"
                            @click="saveRemark"
                        >
                            {{ t('Save') }}
                        </button></view
                    ></view
                >
                <text v-if="saveFailed" class="note">{{
                    t('Unable to save. Refresh and try again.')
                }}</text>
                <view
                    ><text>{{ t('Customer agent level') }}</text
                    ><text>{{ rankLabel(profile.rank) }}</text></view
                >
                <view
                    ><text>{{ t('Current available balance') }}</text
                    ><text>{{ amounts(profile.balances) }}</text></view
                >
                <view
                    ><text>{{ t('Partner status') }}</text
                    ><text>{{ t(profile.partner ? 'Is a partner' : 'Not a partner') }}</text></view
                >
                <view
                    ><text>{{ t('Customer registered at') }}</text
                    ><text>{{ dateTime(profile.registeredAt) }}</text></view
                >
                <view
                    ><text>{{ t('Cumulative deposits') }}</text
                    ><text>{{ amounts(profile.deposits) }}</text></view
                >
                <view
                    ><text>{{ t('Cumulative withdrawals') }}</text
                    ><text>{{ amounts(profile.withdrawals) }}</text></view
                >
                <view
                    ><text>{{ t('Upstream agent') }}</text
                    ><view v-if="profile.referrer" class="parent"
                        ><text>{{ profile.referrer.name }}</text
                        ><text>{{
                            profile.referrer.email || profile.referrer.accountId
                        }}</text></view
                    ><text v-else>—</text></view
                >
            </view>
            <text class="note">{{
                t(
                    'Totals include completed deposits and withdrawals only, shown separately by currency.',
                )
            }}</text>
        </view>
    </PageShell>
</template>
<style scoped>
.remark-editor > view {
    display: flex;
    flex: 1;
    min-width: 0;
    gap: 8px;
    align-items: center;
}
.remark-editor input {
    min-width: 0;
    flex: 1;
    border: 1px solid #dce3dd;
    border-radius: 8px;
    padding: 8px;
}
.remark-editor button {
    flex-shrink: 0;
    padding: 0 12px;
    line-height: 36px;
    font-size: 13px;
    color: #278b70;
}
.profile {
    display: flex;
    flex-direction: column;
    gap: 20px;
}
.identity {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    padding: 20px 0;
    font-size: 13px;
    color: #77857e;
}
.avatar {
    width: 60px;
    height: 60px;
    line-height: 60px;
    text-align: center;
    border-radius: 16px;
    background: #dcefe8;
    color: #278b70;
    font-size: 28px;
}
.name {
    font-size: 20px;
    color: #171c19;
    font-weight: 600;
}
.details {
    background: white;
    padding: 0 16px;
    border-radius: 16px;
}
.details > view {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    padding: 16px 0;
    border-bottom: 1px solid #edf0ee;
    font-size: 14px;
}
.details > view > text:first-child {
    color: #77857e;
    flex-shrink: 0;
}
.details > view > text:last-child {
    text-align: right;
    white-space: pre-line;
    overflow-wrap: anywhere;
}
.parent {
    display: flex;
    flex-direction: column;
    gap: 4px;
    text-align: right;
    overflow-wrap: anywhere;
    min-width: 0;
}
.note {
    color: #84918b;
    font-size: 12px;
    line-height: 1.6;
}
</style>
