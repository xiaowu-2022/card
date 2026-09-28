<script setup lang="ts">
defineProps<{ modelValue: boolean; label: string; disabled?: boolean }>();
const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>();
</script>
<template>
    <checkbox-group @change="emit('update:modelValue', $event.detail.value.includes('confirmed'))"
        ><label class="confirm-check"
            ><checkbox
                value="confirmed"
                :checked="modelValue"
                :disabled="disabled"
                color="#39ad8d"
            /><text>{{ label }}</text></label
        ></checkbox-group
    >
</template>
<style scoped>
.confirm-check {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    font-size: 14px;
    line-height: 24px;
    margin: 20px 0;
}
.confirm-check checkbox {
    flex-shrink: 0;
}
</style>
