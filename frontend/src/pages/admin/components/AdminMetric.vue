<script setup>
import { computed } from 'vue'
import CountUp from '../../../components/CountUp.vue'

const props = defineProps({
  label: {
    type: String,
    required: true,
  },
  value: {
    type: [String, Number],
    required: true,
  },
  tone: {
    type: String,
    default: 'primary',
  },
})

const textTones = {
  primary: 'text-primary',
  success: 'text-success-strong',
  muted: 'text-ink-muted',
  warning: 'text-warning-strong',
}

const isNumericValue = computed(() => (
  props.value !== null
  && props.value !== undefined
  && props.value !== ''
  && Number.isFinite(Number(props.value))
))
</script>

<template>
  <div class="rounded-xl border border-border bg-surface-raised px-4 py-3 shadow-card">
    <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ label }}</p>
    <p class="mt-1 text-2xl font-bold tracking-tight" :class="textTones[tone] || 'text-ink'">
      <CountUp v-if="isNumericValue" :value="Number(value)" />
      <template v-else>{{ value }}</template>
    </p>
  </div>
</template>
