<script setup>
import { BellIcon } from '@heroicons/vue/24/outline'
import { onMounted, ref } from 'vue'

const props = defineProps({
  enabled: { type: Boolean, default: false },
  summaryUrl: { type: String, required: true },
  centerUrl: { type: String, required: true },
})
const unread = ref(0)

onMounted(async () => {
  if (!props.enabled) return
  try {
    const response = await fetch(props.summaryUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
    if (response.ok) unread.value = Number((await response.json()).unread) || 0
  } catch (_) {
    unread.value = 0
  }
})
</script>

<template>
  <a v-if="enabled" :href="centerUrl" class="relative rounded-lg p-2 text-ink-muted hover:bg-surface-muted hover:text-ink" :aria-label="unread ? `${unread} notificaciones sin leer` : 'Notificaciones'">
    <BellIcon class="size-6" aria-hidden="true" />
    <span v-if="unread" class="absolute right-0 top-0 min-w-4 rounded-full bg-danger px-1 text-center text-[10px] font-bold leading-4 text-white">{{ unread > 99 ? '99+' : unread }}</span>
  </a>
</template>
