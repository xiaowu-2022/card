<script setup lang="ts">
import TextInput from '../../components/TextInput.vue';
import FormField from '../../components/FormField.vue';
import { useFormFeedback } from '../../lib/form-feedback';
import { useSensitiveScreen } from '../../lib/sensitive';
import { ref } from 'vue';
import { onShow, onHide } from '@dcloudio/uni-app';
import { bootstrap, login, session } from '../../lib/session';
import { native } from '../../lib/api';
import { t, changeLocale } from '../../lib/i18n';
import { go, home } from '../../lib/navigation';
import { explainError } from '../../lib/client';
import AuthLayout from '../../components/AuthLayout.vue';
import FormErrors from '../../components/FormErrors.vue';
import UiIcon from '../../components/UiIcon.vue';
const email = ref(''),
    password = ref(''),
    show = ref(false),
    pending = ref(false),
    errors = ref<Record<string, string>>({});
// Fresh H5 visitors start in English; bootstrap retains saved, enabled preferences.
if (!session.value) changeLocale(native ? 'zh-CN' : 'en');
const checkingSession = ref(true), sessionCheckFailed = ref(false);
let checking = false, visible = true;
async function verifySession() {
    if (checking) return;
    checking = true;
    checkingSession.value = true;
    sessionCheckFailed.value = false;
    errors.value = {};
    if (native) changeLocale('zh-CN');
    try {
        await bootstrap();
        if (!visible) return;
        const current = session.value;
        if (!current || typeof current.tenant?.id !== 'string' || !current.tenant.id
            || typeof current.restricted !== 'boolean'
            || (current.user !== null && (typeof current.user?.id !== 'string' || !current.user.id))) {
            throw new Error('Invalid session confirmation');
        }
        if (current.user) {
            home(current.restricted ? '/account/restricted' : '/dashboard');
            return;
        }
        if (native) changeLocale('zh-CN');
        // Only a successful server-confirmed guest result may reveal the login form.
        checkingSession.value = false;
    } catch (e) {
        if (!visible) return;
        sessionCheckFailed.value = true;
        errors.value = explainError(e);
    } finally {
        checking = false;
    }
}
onShow(() => { visible = true; void verifySession(); });
onHide(() => { visible = false; });

useSensitiveScreen(() => {
    password.value = '';
    show.value = false;
});
async function submit() {
    feedback.begin();
    if (pending.value || !email.value || !password.value) return;
    pending.value = true;
    errors.value = {};
    try {
        await login(email.value, password.value);
        password.value = '';
        home(session.value?.restricted ? '/account/restricted' : '/dashboard');
    } catch (e) {
        errors.value = explainError(e);
    } finally {
        pending.value = false;
    }
}
const feedback = useFormFeedback(errors);
</script>
<template>
    <view v-if="checkingSession || sessionCheckFailed" class="login-restoring" :data-webview-pending="checkingSession ? 'true' : 'false'" role="status">
        <text>{{ t('Restoring sign-in…') }}</text>
        <FormErrors v-if="sessionCheckFailed" :errors="errors" />
        <button v-if="sessionCheckFailed" @click="verifySession">{{ t('Retry') }}</button>
    </view>
    <AuthLayout v-else login
        ><FormErrors :errors="errors" />
        <form @submit="submit">
            <view class="login-fields"
                >
                <!-- #ifdef H5 -->
                <FormField
                    name="email" :aliases="['identifier']"
                    v-model="email"
                    class="login-feedback"
                    :placeholder="t('Email')"
                    :aria-label="t('Email address')"
                    :disabled="pending"
                    :maxlength="255"
                />
                <!-- #endif -->
                <!-- #ifndef H5 -->
                <TextInput v-model="email" class="login-input" :placeholder="t('Email')" :aria-label="t('Email address')" :disabled="pending" :maxlength="255" />
                <!-- #endif -->
                <view class="password-field"
                    >
                    <!-- #ifdef H5 -->
                    <FormField
                        name="password"
                        v-model="password"
                        class="login-feedback"
                        :password="!show"
                        :placeholder="t('Password')"
                        :aria-label="t('Password')"
                        :disabled="pending"
                        :maxlength="1024"
                        @confirm="submit" />
                    <!-- #endif -->
                    <!-- #ifndef H5 -->
                    <TextInput v-model="password" class="login-input" :password="!show" :placeholder="t('Password')" :aria-label="t('Password')" :disabled="pending" :maxlength="1024" @confirm="submit" />
                    <!-- #endif -->
                    <button
                        class="password-toggle"
                        :aria-label="t(show ? 'Hide password' : 'Show password')"
                        @click="show = !show"
                    >
                        <UiIcon :name="show ? 'eye' : 'eye-off'" :size="24" /></button></view
                ><button
                    class="auth-submit"
                    form-type="submit"
                    :disabled="pending || !email || !password"
                >
                    {{ t(pending ? 'Signing in…' : 'Sign in') }}
                </button></view
            >
        </form>
        <button class="forgot-link" @click="go('/forgot-password')">
            {{ t('Forgot password?') }}</button
        ><button class="register-button" @click="go('/register')">
            {{ t('Register') }}
        </button></AuthLayout
    >
</template>
<style scoped>
.login-restoring { min-height: 100vh; display: flex; flex-direction: column; justify-content: center; align-items: center; gap: 20px; padding: 28px; box-sizing: border-box; background: #11100c; color: #f6edce; }
.login-feedback { margin-bottom: 0; }
.login-fields {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.login-input,
.login-feedback :deep(.form-input) {
    box-sizing: border-box;
    width: 100%;
    height: 54px;
    min-height: 54px;
    border: 1px solid #e2ded4;
    border-radius: 14px;
    padding: 0 18px;
    background: white;
    font-size: 16px;
    color: #25241f;
}
.password-field {
    position: relative;
}
.password-field .login-input,
.password-field :deep(.form-input) {
    padding-right: 56px;
}
.password-toggle {
    position: absolute;
    right: 24px;
    top: 5px;
    width: 44px;
    height: 44px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: transparent;
    padding: 0;
}
.auth-submit {
    width: 100%;
    min-height: 54px;
    border-radius: 14px;
    background: #25241f;
    color: white;
    padding: 13px 16px;
    font-size: 17px;
    line-height: 1.5;
}
.auth-submit[disabled] {
    background: #e4e0d5;
    color: #787264;
}
.forgot-link {
    display: block;
    text-align: center;
    text-decoration: underline;
    text-underline-offset: 4px;
    font-size: 14px;
    color: #7d8780;
    background: transparent;
    margin: 20px auto 0;
    padding: 0;
    line-height: 20px;
}
.register-button {
    margin-top: 24px;
    width: 100%;
    min-height: 52px;
    border: 1px solid #d3cbbc;
    border-radius: 14px;
    background: transparent;
    font-size: 16px;
    color: #25241f;
    display: flex;
    justify-content: center;
    align-items: center;
}
</style>
