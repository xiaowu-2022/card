<script setup lang="ts">
import { useFeedbackSummary } from '../lib/form-feedback';
const props = defineProps<{ errors: Record<string, string> }>();
const summary = useFeedbackSummary(() => props.errors);
</script>
<template>
    <view v-if="Object.keys(summary).length" class="form-errors" role="alert"
        ><text v-for="(value, key) in summary" :key="key" class="block">{{ value }}</text></view
    >
</template>
<style scoped>
.form-errors {
    background: #fff0ef;
    color: #9f2828;
    padding: 14px;
    border: 1px solid #f1d3cf;
    border-radius: 12px;
    font-size: 14px;
    line-height: 1.7;
    margin-bottom: 16px;
}
.block {
    display: block;
}
</style>
