<script setup>
// Bloques grises con la forma de una lista mientras llegan los datos. El texto queda para
// el lector de pantalla; con movimiento reducido los bloques no laten (transitions.css).
defineProps({
  label: { type: String, required: true },
  rows: { type: Number, default: 3 },
})

// Anchos distintos por renglón para que no parezca una grilla vacía.
const widths = [['w-2/5', 'w-3/4'], ['w-1/3', 'w-2/3'], ['w-1/2', 'w-4/5']]
</script>

<template>
  <div role="status" aria-live="polite" class="animate-pulse" data-test="loading-blocks">
    <span class="sr-only">{{ label }}</span>
    <div v-for="index in rows" :key="index" class="space-y-2 py-3" aria-hidden="true">
      <div class="h-3.5 rounded bg-surface-muted" :class="widths[(index - 1) % widths.length][0]"></div>
      <div class="h-3 rounded bg-surface-muted" :class="widths[(index - 1) % widths.length][1]"></div>
    </div>
  </div>
</template>
