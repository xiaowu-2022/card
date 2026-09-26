<script setup lang="ts">
import { ref } from 'vue';
import PageShell from '../../components/PageShell.vue';
import UiIcon from '../../components/UiIcon.vue';
import LoadState from '../../components/LoadState.vue';
import { request, photoUrl } from '../../lib/api';
import { session, unread, logout, bootstrap } from '../../lib/session';
import { useScreen } from '../../lib/screen';
import { t } from '../../lib/i18n';
const actionFailed = ref(false); const busy = ref(false);
const { loading, failed, refresh } = useScreen(async () => { await bootstrap(); await request('/account'); });
const badge = (count: number) => count > 99 ? '99+' : String(count);
function open(page: string) { uni.navigateTo({ url: `/pages/${page}/index` }); }
async function signOut() { busy.value = true; actionFailed.value = false; try { await logout(); } catch { actionFailed.value = true; } finally { busy.value = false; } }
async function changeLanguage(event: { detail: { value: string | number } }) {
    const locale = session.value?.locales[Number(event.detail.value)]; if (!locale) return;
    actionFailed.value = false;
    try { await request('/locale', 'POST', { locale }); await bootstrap(); } catch { actionFailed.value = true; }
}
</script>
<template>
  <PageShell :title="t('Me')" active="account">
    <LoadState :loading="loading" :failed="failed" @retry="refresh">
      <image v-if="session?.tenant.logoUrl" class="brand-logo" mode="aspectFit" :src="photoUrl(session.tenant.logoUrl)" />
      <view class="row"><text class="title">{{ session?.user?.displayName || session?.tenant.name }}</text><text class="muted">{{ session?.user?.email }}</text><text class="body" style="margin-top:24px">{{ t('Account ID') }}　{{ session?.user?.accountId }}</text></view>
      <view class="quick-actions">
        <button v-if="!session?.restricted" class="quick-action" @click="open('support')"><view class="quick-icon"><UiIcon name="support" /><text v-if="unread.support" class="badge">{{ badge(unread.support) }}</text></view>{{ t('Online support') }}</button>
        <button class="quick-action" @click="open('messages')"><view class="quick-icon"><UiIcon name="bell" /><text v-if="unread.messages" class="badge">{{ badge(unread.messages) }}</text></view>{{ t('Messages') }}</button>
      </view>
      <picker :range="session?.locales || []" @change="changeLanguage"><view class="row flex"><text>{{ t('Language') }}</text><text class="green">{{ session?.locale }}　›</text></view></picker>
      <text v-if="actionFailed" class="body error">{{ t('Unable to complete this request. Check your information and current status.') }}</text>
      <button class="secondary" style="margin-top:28px" :disabled="busy" @click="signOut">{{ t('Log out') }}</button>
    </LoadState>
  </PageShell>
</template>
