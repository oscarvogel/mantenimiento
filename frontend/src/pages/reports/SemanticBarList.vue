<script setup>
import { computed } from 'vue'

const props = defineProps({
  items: { type: Array, required: true },
  valueKey: { type: String, required: true },
  labelKey: { type: String, required: true },
  valueFormatter: { type: Function, default: (value) => String(value) },
  emptyText: { type: String, default: 'Sin datos suficientes para este período.' },
})

const maximum = computed(() => Math.max(0, ...props.items.map((item) => Number(item[props.valueKey]) || 0)))
const widthFor = (value) => {
  if (maximum.value <= 0 || Number(value) <= 0) return 0
  return Math.max(4, Math.round(((Number(value) || 0) / maximum.value) * 100))
}
</script>

<template>
  <p v-if="items.length === 0" role="status" class="rounded-lg bg-surface-muted px-4 py-6 text-center text-sm text-ink-muted">
    {{ emptyText }}
  </p>
  <ul v-else class="space-y-4">
    <li v-for="item in items" :key="item[labelKey]" class="space-y-1.5">
      <div class="flex items-center justify-between gap-3 text-sm">
        <span class="min-w-0 truncate font-medium text-ink">{{ item[labelKey] }}</span>
        <span class="shrink-0 tabular-nums text-ink-muted">{{ valueFormatter(item[valueKey]) }}</span>
      </div>
      <div
        class="h-2.5 overflow-hidden rounded-full bg-surface-muted"
        role="meter"
        :aria-label="`${item[labelKey]}: ${valueFormatter(item[valueKey])}`"
        aria-valuemin="0"
        :aria-valuemax="maximum"
        :aria-valuenow="Number(item[valueKey]) || 0"
      >
        <div class="h-full rounded-full bg-primary" :style="{ width: `${widthFor(item[valueKey])}%` }"></div>
      </div>
    </li>
  </ul>
</template>
