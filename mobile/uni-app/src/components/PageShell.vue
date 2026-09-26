<script setup lang="ts">
import { t } from '../lib/i18n';
import { unread, session } from '../lib/session';
import UiIcon from './UiIcon.vue';
defineProps<{ title: string; active?: string; back?: string; guest?: boolean }>();
const badge = (count: number) => count > 99 ? '99+' : String(count);
function open(url: string) { uni.navigateTo({ url }); }
function tab(name: string) { uni.reLaunch({ url: `/pages/${name}/index` }); }
</script>
<template>
  <view class="shell">
    <view class="header">
      <button v-if="back" class="icon" :aria-label="t('Back')" @click="open(back)">‹</button><view v-else class="icon" />
      <text class="header-title">{{ title }}</text>
      <button v-if="!guest && session?.user" class="icon" :aria-label="t('Messages')" @click="open('/pages/messages/index')">
        <UiIcon name="bell" /><text v-if="unread.messages" class="badge">{{ badge(unread.messages) }}</text>
      </button><view v-else class="icon" />
    </view>
    <slot />
    <view v-if="!guest && session?.user" class="tabs">
      <button v-if="!session.restricted" class="tab" :class="{ active: active === 'assets' }" @click="tab('assets')"><view class="tab-icon"><UiIcon name="assets" /></view>{{ t('Assets') }}</button>
      <button v-if="!session.restricted" class="tab" :class="{ active: active === 'cards' }" @click="tab('cards')"><view class="tab-icon"><UiIcon name="cards" /></view>{{ t('Cards') }}</button>
      <button class="tab" :class="{ active: active === 'account' }" @click="tab('account')"><view class="tab-icon"><UiIcon name="account" /><text v-if="unread.messages + unread.support" class="badge">{{ badge(unread.messages + unread.support) }}</text></view>{{ t('Me') }}</button>
    </view>
  </view>
</template>
