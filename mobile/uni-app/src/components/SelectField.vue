<script setup lang="ts">
import { computed, ref } from 'vue';
import { t } from '../lib/i18n';
import UiIcon from './UiIcon.vue';
const props = defineProps<{
    modelValue: string;
    label?: string;
    options: { value: string; label: string; keywords?: string[] }[];
    disabled?: boolean;
    searchable?: boolean;
    hideLabel?: boolean;
    placeholder?: string;
}>();
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
const opened = ref(false),
    search = ref('');
const selected = computed(
    () =>
        props.options.find((item) => item.value === props.modelValue)?.label ??
        props.placeholder ??
        t('Please select'),
);
const filtered = computed(() =>
    props.options.filter((item) =>
        [item.label, item.value, ...(item.keywords ?? [])]
            .join(' ')
            .toLowerCase()
            .includes(search.value.toLowerCase()),
    ),
);
function pick(value: string) {
    emit('update:modelValue', value);
    opened.value = false;
    search.value = '';
}
</script>
<template>
    <view class="select-field"
        ><text v-if="label && !hideLabel" class="select-label">{{ label }}</text
        ><picker
            v-if="!searchable"
            :range="options"
            range-key="label"
            :value="
                Math.max(
                    0,
                    options.findIndex((item) => item.value === modelValue),
                )
            "
            :disabled="disabled"
            @change="pick(options[Number($event.detail.value)]?.value ?? '')"
            ><view class="select-trigger" :aria-label="label"
                ><slot
                    ><text>{{ selected }}</text></slot
                ><UiIcon name="chevron-down" :size="18" /></view></picker
        ><button v-else class="select-trigger" :disabled="disabled" @click="opened = true">
            <text>{{ selected }}</text
            ><UiIcon name="chevron-down" :size="18" /></button
        ><view v-if="opened" class="select-overlay" @click.self="opened = false"
            ><view class="select-panel"
                ><view class="select-head"
                    ><text>{{ label || t('Please select') }}</text
                    ><button class="icon" :aria-label="t('Close')" @click="opened = false">
                        <UiIcon name="x" :size="20" /></button></view
                ><input
                    v-model="search"
                    class="search-field"
                    :placeholder="t('Search options')"
                    :aria-label="t('Search options')"
                /><scroll-view scroll-y class="select-options"
                    ><button
                        v-for="item in filtered"
                        :key="item.value"
                        class="select-option"
                        :class="{ selected: item.value === modelValue }"
                        @click="pick(item.value)"
                    >
                        {{ item.label }}</button
                    ><text v-if="!filtered.length" class="empty">{{
                        t('No matching options')
                    }}</text></scroll-view
                ></view
            ></view
        ></view
    >
</template>
<style scoped>
.select-field {
    margin-bottom: 20px;
}
.select-label {
    display: block;
    font-size: 14px;
    font-weight: 500;
    margin-bottom: 8px;
}
.select-trigger {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    min-height: 44px;
    padding: 9px 12px;
    border: 1px solid #e2e7e4;
    border-radius: 8px;
    font-size: 16px;
    background: white;
    color: #171c19;
    line-height: 24px;
    text-align: left;
}
.select-overlay {
    position: fixed;
    inset: 0;
    z-index: 200;
    background: #0006;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.select-panel {
    background: white;
    border-radius: 20px;
    padding: 16px;
    width: 100%;
    max-width: 480px;
    box-shadow: 0 20px 60px #0003;
}
.select-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-weight: 600;
}
.search-field {
    border: 1px solid #e2e7e4;
    border-radius: 10px;
    height: 44px;
    padding: 0 12px;
    margin: 12px 0;
}
.select-options {
    max-height: 55vh;
    height: 400px;
}
.select-option {
    background: none;
    text-align: left;
    font-size: 15px;
    line-height: 22px;
    padding: 12px;
    border-radius: 8px;
}
.select-option.selected {
    background: #e8f4ee;
    color: #277660;
}
</style>
