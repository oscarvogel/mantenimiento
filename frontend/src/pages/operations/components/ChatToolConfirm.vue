<template>
  <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 text-sm">
    <div class="flex items-center gap-2 mb-2">
      <svg class="w-4 h-4 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
      </svg>
      <span class="font-medium text-amber-800">Confirmar acción</span>
    </div>
    <div class="text-amber-700 mb-3">
      <div class="font-mono text-xs bg-amber-100 rounded p-2 mb-2">{{ toolName }}</div>
      <pre class="text-xs whitespace-pre-wrap">{{ formattedArgs }}</pre>
    </div>
    <div class="flex gap-2">
      <button
        @click="$emit('confirm')"
        class="px-3 py-1 bg-amber-600 text-white text-xs rounded-lg hover:bg-amber-700"
      >
        Confirmar
      </button>
      <button
        @click="$emit('cancel')"
        class="px-3 py-1 bg-gray-200 text-gray-700 text-xs rounded-lg hover:bg-gray-300"
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