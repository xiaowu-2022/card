<script setup lang="ts">
import { ref } from 'vue';
import { useSensitiveScreen } from '../lib/sensitive';
import Modal from './Modal.vue';
import FormField from './FormField.vue';
import FormErrors from './FormErrors.vue';
import ConfirmCheck from './ConfirmCheck.vue';
import { useAction } from '../lib/client';
import { t } from '../lib/i18n';
const props = defineProps<{
    title: string;
    warning: string | string[];
    url: string;
    payload: Record<string, string>;
    disabled?: boolean;
}>();
const emit = defineEmits<{ completed: [] }>();
const open = ref(false),
    password = ref(''),
    confirmed = ref(false),
    action = useAction();
function close() {
    if (action.pending.value) return;
    open.value = false;
    password.value = '';
    confirmed.value = false;
    action.errors.value = {};
}
useSensitiveScreen(() => {
    password.value = '';
    confirmed.value = false;
    open.value = false;
});
async function submit() {
    if (props.disabled || !confirmed.value || !password.value) return;
    await action.submit(
        props.url,
        { ...props.payload, current_password: password.value, confirmed: confirmed.value },
        {
            navigate: false,
            success: () => {
                open.value = false;
                confirmed.value = false;
                emit('completed');
            },
        },
    );
    password.value = '';
}
</script>
<template>
    <button class="primary" :disabled="disabled" @click="open = true">{{ title }}</button
    ><Modal :open="open" :title="title" :busy="action.pending.value" @close="close"
        ><view class="financial-warning"
            ><text
                v-for="(line, index) in Array.isArray(warning) ? warning : [warning]"
                :key="index"
                >{{ line }}</text
            ></view
        >
        <form @submit="submit">
            <FormField
                v-model="password"
                :label="t('Current password')"
                password
                :maxlength="1024"
                :disabled="action.pending.value"
            /><ConfirmCheck
                v-model="confirmed"
                :label="t('I understand and confirm this action.')"
                :disabled="action.pending.value"
            /><FormErrors :errors="action.errors.value" /><button
                class="primary"
                form-type="submit"
                :disabled="disabled || action.pending.value || !confirmed || !password"
            >
                {{ t('Confirm') }}
            </button>
        </form></Modal
    >
</template>
<style scoped>
.financial-warning {
    display: flex;
    flex-direction: column;
    gap: 8px;
    font-size: 14px;
    color: #68736e;
    line-height: 24px;
    margin-bottom: 20px;
}
.primary {
    min-height: 44px;
    border-radius: 999px;
    padding: 10px 24px;
    font-size: 14px;
    line-height: 24px;
}
</style>
