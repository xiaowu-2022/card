<script setup lang="ts">
import { ref } from 'vue';
import { onShow } from '@dcloudio/uni-app';
import PageShell from '../../components/PageShell.vue';
import LoadState from '../../components/LoadState.vue';
import { bootstrap, login, session } from '../../lib/session';
import { ApiError, photoUrl } from '../../lib/api';
import { t } from '../../lib/i18n';
const identifier = ref(''); const password = ref(''); const busy = ref(false); const loading = ref(true); const failed = ref(false); const loginFailed = ref('');
function enter() { uni.reLaunch({ url: session.value?.restricted ? '/pages/account/index' : '/pages/assets/index' }); }
async function init() { loading.value = true; failed.value = false; try { await bootstrap(); if (session.value?.user) enter(); } catch { failed.value = true; } finally { loading.value = false; } }
onShow(() => { void init(); });
async function submit() {
    if (busy.value || !identifier.value || !password.value) return;
    busy.value = true; loginFailed.value = '';
    try { await login(identifier.value, password.value); password.value = ''; enter(); }
    catch (error) { loginFailed.value = error instanceof ApiError && error.status === 422 ? 'Invalid credentials.' : 'Unable to complete this request. Check your information and current status.'; } finally { busy.value = false; }
}
</script>
<template>
  <PageShell :title="t('Sign in')" guest>
    <LoadState :loading="loading" :failed="failed" @retry="init">
      <view class="stack">
        <image v-if="session?.tenant.logoUrl" class="brand-logo" mode="aspectFit" :src="photoUrl(session.tenant.logoUrl)" />
        <text class="title">{{ session?.tenant.name }}</text>
        <view><text class="label">{{ t('Email address') }}</text><input v-model="identifier" class="field" type="text" :maxlength="255" :aria-label="t('Email address')" /></view>
        <view><text class="label">{{ t('Password') }}</text><input v-model="password" class="field" password :maxlength="1024" :aria-label="t('Password')" @confirm="submit" /></view>
        <text v-if="loginFailed" class="error" role="alert">{{ t(loginFailed) }}</text>
        <button class="primary" :disabled="busy || !identifier || !password" :loading="busy" @click="submit">{{ t('Sign in') }}</button>
      </view>
    </LoadState>
  </PageShell>
</template>
