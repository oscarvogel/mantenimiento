<script setup>
defineProps({
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
</script>

<template>
  <div class="rounded-xl border border-border bg-surface-raised px-4 py-3 shadow-card">
    <p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">{{ label }}</p>
    <p class="mt-1 text-2xl font-bold tracking-tight" :class="textTones[tone] || 'text-ink'">{{ value }}</p>
  </div>
</template>
