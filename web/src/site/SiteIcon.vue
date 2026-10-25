<script setup lang="ts">
withDefaults(defineProps<{ name: string; size?: number }>(), { size: 22 })
const paths: Record<string, string> = {
  arrow: 'M5 12h14m-6-6 6 6-6 6', search: 'M21 21l-5-5M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0',
  user: 'M20 21v-2a7 7 0 0 0-14 0v2M17 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0',
  ship: 'M4 16l-1-6 9-4 9 4-1 6M12 6V2M8 8V4h8v4M2 20c2 2 4 2 6 0 2 2 4 2 6 0 2 2 4 2 8 0M12 10v8',
  compass: 'M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0M16 8l-3 5-5 3 3-5 5-3',
  map: 'M9 3 3 6v15l6-3 6 3 6-3V3l-6 3-6-3M9 3v15M15 6v15',
  grid: 'M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z',
  tool: 'M14 5a5 5 0 0 0-6 6L2 17a3 3 0 0 0 5 5l6-6a5 5 0 0 0 6-6l-4 4-3-3 4-4',
  book: 'M3 3h6a4 4 0 0 1 3 2 4 4 0 0 1 3-2h6v17h-6a4 4 0 0 0-3 2 4 4 0 0 0-3-2H3V3M12 5v17',
  bag: 'M4 7h16l1 14H3L4 7M8 7V5a4 4 0 0 1 8 0v2',
  check: 'M5 12l4 4L20 5', clock: 'M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0M12 6v6l4 2',
  heart: 'M20 5a5 5 0 0 0-8 1 5 5 0 0 0-8-1c-5 5 3 11 8 15 5-4 13-10 8-15',
  message: 'M21 11a9 9 0 0 1-9 9H3l2-5a9 9 0 1 1 16-4M7 10h10M7 14h6',
  file: 'M14 2H4v20h16V8l-6-6M14 2v6h6M8 13h8M8 17h6',
  menu: 'M3 6h18M3 12h18M3 18h18'
}
</script>
<template><svg :width="size" :height="size" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path :d="paths[name] || paths.compass" /></svg></template>
