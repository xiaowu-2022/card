<script setup lang="ts">
defineProps<{ name: 'bell' | 'support' | 'account' | 'cards' | 'assets' }>();
</script>
<template><image :src="'/static/icons/' + name + '.svg'" mode="aspectFit" style="width:26px;height:26px;vertical-align:middle" /></template>
