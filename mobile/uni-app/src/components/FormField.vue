<script setup lang="ts">
import { computed, ref, getCurrentInstance } from 'vue';
import { useFeedbackField } from '../lib/form-feedback';
import TextInput from './TextInput.vue';
const props = withDefaults(
    defineProps<{
        name?: string;
        aliases?: string[];
        modelValue: string;
        label?: string;
        placeholder?: string;
        type?: 'text' | 'number' | 'digit';
        password?: boolean;
        digitsOnly?: boolean;
        disabled?: boolean;
        maxlength?: number;
        description?: string;
        error?: string;
    }>(),
    { type: 'text', maxlength: 255 },
);
const element = ref();
const errorId = `field-error-${getCurrentInstance()?.uid}`;
const feedbackError = useFeedbackField(() => [props.name ?? '', ...(props.aliases ?? [])].filter(Boolean), () => element.value);
const fieldError = computed(() => feedbackError.value || props.error);
const emit = defineEmits<{ 'update:modelValue': [value: string]; confirm: [] }>();
const confirmEvents = getCurrentInstance()?.vnode.props?.onConfirm ? { confirm: () => emit('confirm') } : {};
function input(event: { detail: { value: string } }) {
    const value = event.detail.value;
    emit(
        'update:modelValue',
        props.digitsOnly ? value.replace(/\D/g, '').slice(0, props.maxlength) : value,
    );
}
</script>
<template>
    <view ref="element" class="form-field" :data-field="name"
        ><text v-if="label" class="form-label">{{ label }}</text
        ><TextInput
            class="form-input"
            :value="modelValue"
            :placeholder="placeholder"
            :type="type"
            :password="password"
            :digits-only="digitsOnly"
            :disabled="disabled"
            :maxlength="maxlength"
            :aria-label="label || placeholder"
            :aria-invalid="!!fieldError"
            :aria-describedby="fieldError ? errorId : undefined"
            :name="name"
            @input="input" v-on="confirmEvents" /><text v-if="description" class="form-description">{{
            description
        }}</text
        ><text v-if="fieldError" class="field-error" :id="errorId" role="alert">{{ fieldError }}</text
        ><slot
    /></view>
</template>
<style scoped>
.form-field {
    margin-bottom: 20px;
}
.form-label {
    display: block;
    font-size: 14px;
    font-weight: 500;
    margin-bottom: 8px;
}
.form-input {
    box-sizing: border-box;
    width: 100%;
    height: 54px;
    min-height: 54px;
    border: 1px solid #e2ded4;
    background: white;
    border-radius: 14px;
    padding: 0 18px;
    font-size: 16px;
    color: #25241f;
}
.form-description,
.field-error {
    display: block;
    font-size: 14px;
    line-height: 1.6;
    margin-top: 6px;
    color: #727d76;
}
.field-error {
    color: #9f2828;
}
</style>
