<script setup lang="ts">
import { computed, getCurrentInstance } from 'vue';
const props = withDefaults(defineProps<{
    modelValue?: string;
    value?: string;
    type?: string;
    password?: boolean;
    digitsOnly?: boolean;
    disabled?: boolean;
    maxlength?: number;
    placeholder?: string;
}>(), { type: 'text', maxlength: 255 });
const emit = defineEmits<{
    'update:modelValue': [value: string];
    input: [event: { detail: { value: string } }];
    confirm: [];
}>();
const instance = getCurrentInstance();
function confirm(event: KeyboardEvent) {
    if (instance?.vnode.props?.onConfirm) {
        event.preventDefault();
        emit('confirm');
    }
}
const current = computed(() => props.modelValue ?? props.value ?? '');
function change(event: Event | { detail: { value: string } }) {
    let value = 'detail' in event && event.detail && typeof event.detail === 'object'
        ? (event.detail as { value: string }).value : ((event as Event).target as HTMLInputElement).value;
    if (props.digitsOnly) {
        value = value.replace(/\D/g, '');
        if ('target' in event && event.target) (event.target as HTMLInputElement).value = value;
    }
    emit('update:modelValue', value);
    emit('input', { detail: { value } });
}
</script>
<template>
    <!-- Use a real HTML input on H5: no clipped uni-input wrapper while Safari edits. -->
    <!-- #ifdef H5 -->
    <component :is="'input'"
        class="web-text-input"
        :value="current"
        :type="password ? 'password' : 'text'"
        :inputmode="type === 'digit' ? 'decimal' : type === 'number' ? 'numeric' : undefined"
        :disabled="disabled" :maxlength="maxlength" :placeholder="placeholder"
        @input="change" @keydown.enter="confirm"
    />
    <!-- #endif -->
    <!-- #ifndef H5 -->
    <input :value="current" :type="type" :password="password"
        :disabled="disabled" :maxlength="maxlength" :placeholder="placeholder"
        @input="change" @confirm="emit('confirm')" />
    <!-- #endif -->
</template>
<style scoped>
.web-text-input {
    display: block;
    box-sizing: border-box;
    min-width: 0;
    color: #25241f;
    -webkit-text-fill-color: #25241f;
    caret-color: #25241f;
    opacity: 1;
    font-family: inherit;
    font-size: 16px;
    line-height: normal;
    -webkit-appearance: none;
    appearance: none;
    outline-offset: 2px;
}
.web-text-input::placeholder {
    color: #727d76;
    -webkit-text-fill-color: #727d76;
    opacity: 1;
}
.web-text-input:-webkit-autofill {
    -webkit-text-fill-color: #25241f;
    -webkit-box-shadow: 0 0 0 1000px #fff inset;
}
</style>
