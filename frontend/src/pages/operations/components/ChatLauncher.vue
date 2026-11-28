<script setup>
import { primaryButton } from '../../../ui/buttons.js'
import ChatRobot from './ChatRobot.vue'

defineProps({
  target: { type: Object, default: null },
  unread: { type: Number, default: 0 },
  state: { type: String, default: 'idle' },
})

const emit = defineEmits(['open'])
</script>

<template>
  <Teleport :to="target || 'body'" :disabled="!target">
    <button
      type="button"
      aria-label="Abrir asistente IA"
      title="Abrir asistente IA"
      :class="target
        ? 'ui-interactive relative grid size-11 place-items-center rounded-lg bg-primary text-primary-foreground hover:bg-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-border-focus'
        : [primaryButton, 'group fixed bottom-24 right-6 z-50 size-14 shadow-lg lg:bottom-6']"
      @click="emit('open')"
    >
      <ChatRobot variant="fab" :state="state" :class="target ? 'scale-75' : undefined" />
      <span
        v-if="unread > 0"
        class="absolute -right-1 -top-1 flex min-h-5 min-w-5 items-center justify-center rounded-full bg-danger px-1 text-xs font-bold text-danger-foreground"
        aria-label="Alertas pendientes"
      >{{ unread > 99 ? '99+' : unread }}</span>
    </button>
  </Teleport>
</template>
