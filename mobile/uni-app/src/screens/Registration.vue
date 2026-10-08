<script setup lang="ts">
import { useSensitiveScreen } from '../lib/sensitive';
import { computed, reactive, ref } from 'vue';
import AuthLayout from '../components/AuthLayout.vue';
import FormField from '../components/FormField.vue';
import FormErrors from '../components/FormErrors.vue';
import { t, locale, changeLocale } from '../lib/i18n';
import { useAction } from '../lib/client';
import { go } from '../lib/navigation';
const props = defineProps<{
    page: {
        registration?: {
            emailAvailable: boolean;
            invitationCode: string;
            invitationLocked: boolean;
            invitationInvalid?: boolean;
        };
        challenge?: { id: string; status: string };
    };
}>();
changeLocale('zh-CN');
const emit = defineEmits<{ reload: [] }>();
const action = useAction();
const invitationReleased = ref(false);
const form = reactive({
    channel: 'EMAIL',
    destination: '',
    invitation_code: props.page.registration?.invitationCode ?? '',
    code: '',
    display_name: '',
    password: '',
    password_confirmation: '',
});
const verified = computed(() => props.page.challenge?.status === 'VERIFIED');
const unavailable = computed(
    () => props.page.challenge && !['PENDING', 'VERIFIED'].includes(props.page.challenge.status),
);
const description = computed(() =>
    t(
        !props.page.challenge
            ? 'First, verify your email address.'
            : verified.value
              ? 'Your contact is verified. Finish creating your account.'
              : 'Enter the six-digit code sent by {{value1}}.',
        { value1: t('Email') },
    ),
);
useSensitiveScreen(() => {
    form.password = '';
    form.password_confirmation = '';
    form.code = '';
});
async function submit() {
    if (!props.page.challenge) {
        await action.submit('/register/challenges', {
            channel: form.channel,
            destination: form.destination,
            invitation_code: form.invitation_code,
        });
        if (action.failureCode.value === 'INVITATION_INVALID') invitationReleased.value = true;
        return;
    }
    if (verified.value) {
        await action.submit(`/register/challenges/${props.page.challenge.id}/complete`, {
            display_name: form.display_name,
            password: form.password,
            password_confirmation: form.password_confirmation,
            locale: locale.value,
        });
    } else {
        await action.submit(
            `/register/challenges/${props.page.challenge.id}/verify`,
            { code: form.code },
            { navigate: false, success: () => emit('reload') },
        );
        form.code = '';
    }
}
</script>
<template>
    <AuthLayout registration
        ><view class="registration-content"
            ><text class="auth-heading">{{
                t(
                    !page.challenge
                        ? 'Create your account'
                        : verified
                          ? 'Create your password'
                          : 'Enter verification code',
                )
            }}</text
            ><text class="auth-description">{{ description }}</text
            ><FormErrors :errors="action.errors.value" /><text
                v-if="page.registration?.invitationInvalid"
                class="field-error"
                >{{ t('Enter a valid invitation code.') }}</text
            ><view v-if="unavailable" class="notice"
                ><text class="auth-subheading">{{ t('Challenge unavailable') }}</text
                ><text>{{
                    t('Request a new code or sign in if you already have an account.')
                }}</text></view
            ><text
                v-else-if="page.registration && !page.registration.emailAvailable"
                class="muted"
                >{{ t('Registration is temporarily unavailable.') }}</text
            >
            <form v-else @submit="submit">
                <template v-if="!page.challenge"
                    ><FormField
                        v-model="form.destination"
                        :label="t('Email address')"
                        :disabled="action.pending.value" /><FormField
                        v-model="form.invitation_code"
                        :label="t('Invitation code')"
                        :maxlength="6"
                        type="number"
                        digits-only
                        :disabled="
                            (page.registration?.invitationLocked && !invitationReleased) || action.pending.value
                        " /></template
                ><template v-else-if="verified"
                    ><FormField
                        v-model="form.display_name"
                        :label="t('Display name')"
                        :description="t('Optional. This is not a legal identity field.')"
                        :maxlength="80" /><FormField
                        v-model="form.password"
                        :label="t('Password')"
                        :description="t('At least 6 characters.')"
                        password
                        :maxlength="1024" /><FormField
                        v-model="form.password_confirmation"
                        :label="t('Confirm password')"
                        password
                        :maxlength="1024" /></template
                ><FormField
                    v-else
                    v-model="form.code"
                    :label="t('Verification code')"
                    type="number"
                    digits-only
                    :maxlength="6"
                /><button class="auth-button" form-type="submit" :disabled="action.pending.value">
                    {{
                        t(
                            !page.challenge
                                ? 'Send verification code'
                                : verified
                                  ? 'Create account'
                                  : 'Verify code',
                        )
                    }}
                </button>
            </form>
            <button v-if="page.challenge" class="text-button" @click="go('/register', true)">
                {{ t('Request another code') }}</button
            ><view v-else class="auth-footer"
                ><text>{{ t('Already registered?') }} </text
                ><text class="inline-link" @click="go('/login', true)">{{
                    t('Sign in')
                }}</text></view
            ></view
        ></AuthLayout
    >
</template>
<style scoped>
.registration-content {
    max-width: 448px;
    margin: auto;
}
.auth-heading {
    display: block;
    font-size: 24px;
    font-weight: 600;
    line-height: 1.35;
    margin: 0 0 4px;
}
.auth-description {
    display: block;
    font-size: 14px;
    line-height: 1.5;
    color: #727d76;
    margin-bottom: 20px;
}
.auth-button {
    background: #25241f;
    color: #fff;
    min-height: 54px;
    border-radius: 14px;
    width: 100%;
    padding: 13px 16px;
    font-size: 17px;
    line-height: 1.5;
}
.auth-button[disabled] {
    opacity: 0.5;
}
.auth-footer {
    text-align: center;
    font-size: 14px;
    color: #727d76;
    margin-top: 20px;
}
.inline-link {
    color: var(--user-primary, #39ad8d);
    font-weight: 600;
}
.text-button {
    display: block;
    margin: 20px auto 0;
    background: none;
    color: var(--user-primary, #39ad8d);
    font-size: 14px;
    padding: 0;
}
.notice {
    padding: 16px;
    border: 1px solid #ddd;
    border-radius: 12px;
    font-size: 14px;
    line-height: 1.6;
}
.auth-subheading {
    display: block;
    font-weight: 600;
}
.field-error {
    display: block;
    color: #9f2828;
    margin-bottom: 16px;
}
</style>
