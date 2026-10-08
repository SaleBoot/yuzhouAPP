<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
defineProps<{ title: string; wide?: boolean }>()
const emit = defineEmits<{ close: [] }>()
const panel = ref<HTMLElement>()
const previous = document.activeElement as HTMLElement | null
function keyboard(event: KeyboardEvent) {
  if (event.key === 'Escape') emit('close')
  if (event.key !== 'Tab' || !panel.value) return
  const controls = [...panel.value.querySelectorAll<HTMLElement>('button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), a[href]')]
  const first = controls[0], last = controls.at(-1)
  if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus() }
  else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus() }
}
onMounted(async () => { document.addEventListener('keydown', keyboard); await nextTick(); panel.value?.querySelector<HTMLElement>('input,button')?.focus() })
onBeforeUnmount(() => { document.removeEventListener('keydown', keyboard); previous?.focus() })
</script>

<template>
  <Teleport to="body">
    <div class="modal-backdrop" @click.self="emit('close')">
      <section ref="panel" class="modal" :class="{ wide }" role="dialog" aria-modal="true" :aria-label="title">
        <header class="modal-header"><h2>{{ title }}</h2><button type="button" class="icon-button" aria-label="关闭" @click="emit('close')">×</button></header>
        <div class="modal-body"><slot /></div>
        <footer v-if="$slots.footer" class="modal-footer"><slot name="footer" /></footer>
      </section>
    </div>
  </Teleport>
</template>
