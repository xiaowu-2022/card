<script setup lang="ts">
import TextInput from './TextInput.vue';
const props = withDefaults(
    defineProps<{
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
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
function input(event: { detail: { value: string } }) {
    const value = event.detail.value;
    emit(
        'update:modelValue',
        props.digitsOnly ? value.replace(/\D/g, '').slice(0, props.maxlength) : value,
    );
}
</script>
<template>
    <view class="form-field"
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
            :aria-invalid="!!error"
            @input="input" /><text v-if="description" class="form-description">{{
            description
        }}</text
        ><text v-if="error" class="field-error">{{ error }}</text
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
