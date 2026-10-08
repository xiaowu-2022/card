<script setup lang="ts">
import TextInput from '../../components/TextInput.vue';
import { useSensitiveScreen } from '../../lib/sensitive';
import { ref } from 'vue';
import { onShow } from '@dcloudio/uni-app';
import { bootstrap, login, session } from '../../lib/session';
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
// Set before first render and again after guest bootstrap, which may use a saved locale.
changeLocale('zh-CN');
onShow(async () => {
    changeLocale('zh-CN');
    try {
        await bootstrap();
        if (session.value?.user)
            home(session.value.restricted ? '/account/restricted' : '/dashboard');
        else changeLocale('zh-CN');
    } catch (e) {
        errors.value = explainError(e);
    }
});
useSensitiveScreen(() => {
    password.value = '';
    show.value = false;
});
async function submit() {
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
</script>
<template>
    <AuthLayout login
        ><FormErrors :errors="errors" />
        <form @submit="submit">
            <view class="login-fields"
                ><TextInput
                    v-model="email"
                    class="login-input"
                    :placeholder="t('Email')"
                    :aria-label="t('Email address')"
                    :disabled="pending"
                    :maxlength="255"
                /><view class="password-field"
                    ><TextInput
                        v-model="password"
                        class="login-input"
                        :password="!show"
                        :placeholder="t('Password')"
                        :aria-label="t('Password')"
                        :disabled="pending"
                        :maxlength="1024"
                        @confirm="submit" /><button
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
.login-fields {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.login-input {
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
.password-field .login-input {
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
