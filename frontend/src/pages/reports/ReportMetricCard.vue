<script setup>
import { computed } from 'vue'
import CountUp from '../../components/CountUp.vue'

const props = defineProps({
  label: { type: String, required: true },
  metric: { type: Object, required: true },
  hint: { type: String, default: '' },
  tone: { type: String, default: 'primary' },
  // La cifra principal del reporte va en la tarjeta oscura.
  emphasis: { type: Boolean, default: false },
})

const tones = {
  primary: 'bg-primary',
  success: 'bg-success',
  warning: 'bg-warning',
  danger: 'bg-danger',
}

const numericValue = computed(() => {
  if (!props.metric.available || props.metric.value === null || props.metric.value === undefined || props.metric.value === '') return null
  return Number.isFinite(Number(props.metric.value)) ? Number(props.metric.value) : null
})
</script>

<template>
  <article class="rounded-card p-5" :class="emphasis ? 'ui-card-emphasis' : 'bg-surface-raised shadow-card'">
    <span class="mb-3 block h-1 w-8 rounded-pill" :class="emphasis ? 'bg-accent-400' : (tones[tone] ?? tones.primary)" aria-hidden="true"></span>
    <p class="text-sm font-semibold text-ink-muted">{{ label }}</p>
    <p v-if="metric.available" class="mt-2 text-2xl font-bold tracking-tight text-ink">
      <CountUp v-if="numericValue !== null" :value="numericValue" :formatter="metric.formatter" />
      <template v-else>{{ metric.displayValue }}</template>
    </p>
    <p v-else class="mt-2 text-base font-semibold text-ink-subtle">Sin datos suficientes</p>
    <p class="mt-2 text-xs leading-5 text-ink-subtle">
      {{ hint || `${metric.sampleSize ?? 0} registros válidos` }}
    </p>
  </article>
</template>
