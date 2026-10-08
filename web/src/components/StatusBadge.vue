<script setup lang="ts">
import { statusNames } from '../domain.js'
defineProps<{ value?: string }>()
</script>
<template><span class="status-badge" :class="value">{{ (statusNames as Record<string, string>)[value || ''] || value || '—' }}</span></template>
