<script setup>
import { computed } from 'vue'
import CountUp from '../../components/CountUp.vue'

const props = defineProps({
  label: { type: String, required: true },
  metric: { type: Object, required: true },
  hint: { type: String, default: '' },
  tone: { type: String, default: 'primary' },
})

const tones = {
  primary: 'border-l-primary',
  success: 'border-l-success',
  warning: 'border-l-warning',
  danger: 'border-l-danger',
}

const numericValue = computed(() => {
  if (!props.metric.available || props.metric.value === null || props.metric.value === undefined || props.metric.value === '') return null
  return Number.isFinite(Number(props.metric.value)) ? Number(props.metric.value) : null
})
</script>

<template>
  <article class="rounded-xl border border-border border-l-4 bg-surface-raised p-5 shadow-card" :class="tones[tone] ?? tones.primary">
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
