<template>
  <div class="rounded-xl border border-warning/40 bg-warning-subtle p-3 text-sm">
    <div class="flex items-center gap-2 mb-2">
      <svg class="size-4 text-warning-strong" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
      </svg>
      <span class="font-medium text-amber-800">Confirmar acción</span>
    </div>
    <div class="mb-3 text-warning-foreground">
      <div class="mb-2 rounded bg-warning/15 p-2 font-mono text-xs">{{ toolName }}</div>
      <pre class="text-xs whitespace-pre-wrap">{{ formattedArgs }}</pre>
    </div>
    <div class="flex gap-2">
      <button
        @click="$emit('confirm')"
        class="ui-interactive rounded-lg bg-warning px-3 py-1 text-xs text-warning-foreground hover:bg-warning-strong"
      >
        Confirmar
      </button>
      <button
        @click="$emit('cancel')"
        class="ui-interactive rounded-lg bg-surface-muted px-3 py-1 text-xs text-ink hover:bg-border-strong"
      >
        Cancelar
      </button>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  toolCall: { type: Object, required: true },
})

defineEmits(['confirm', 'cancel'])

const toolName = computed(() => props.toolCall.name || props.toolCall.function?.name || 'unknown')
const formattedArgs = computed(() => {
  const args = props.toolCall.arguments || props.toolCall.function?.arguments || {}
  return JSON.stringify(args, null, 2)
})
</script>
