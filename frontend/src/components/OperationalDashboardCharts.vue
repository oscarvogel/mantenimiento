<script setup>
import { computed } from 'vue'

const props = defineProps({
  preventiveByState: { type: Array, default: () => [] },
  openOrdersByState: { type: Array, default: () => [] },
})

const circumference = 2 * Math.PI * 42
const preventiveTotal = computed(() => props.preventiveByState.reduce((total, row) => total + row.count, 0))
const segments = computed(() => {
  let offset = 0
  return props.preventiveByState.map((row) => {
    const length = preventiveTotal.value > 0 ? (row.count / preventiveTotal.value) * circumference : 0
    const segment = { ...row, length, offset }
    offset += length
    return segment
  })
})
const largestOrderGroup = computed(() => Math.max(0, ...props.openOrdersByState.map((row) => row.count)))
const visibleOrderStates = computed(() => props.openOrdersByState.filter((row) => row.count > 0))

const statusColor = (status) => ({
  AL_DIA: 'rgb(var(--maintenance-ok))',
  PROXIMO: 'rgb(var(--maintenance-due))',
  VENCIDO: 'rgb(var(--maintenance-overdue))',
  SIN_DATOS: 'rgb(var(--maintenance-inactive))',
}[status] ?? 'rgb(var(--maintenance-scheduled))')

const barTone = (status) => ({
  BORRADOR: 'bg-ink-subtle',
  EMITIDA: 'bg-primary/70',
  EN_PROCESO: 'bg-primary',
  EN_ESPERA_REPUESTOS: 'bg-warning',
  ESPERA_REPUESTOS: 'bg-warning',
}[status] ?? 'bg-primary')
</script>

<template>
  <section aria-label="Indicadores gráficos de mantenimiento" class="mt-6 grid gap-4 xl:grid-cols-2">
    <article class="rounded-xl border border-border bg-surface-raised p-5 shadow-card sm:p-6">
      <div class="flex items-start justify-between gap-3">
        <div>
          <h2 class="text-base font-bold text-ink sm:text-lg">Estado del mantenimiento preventivo</h2>
          <p class="mt-1 text-sm text-ink-muted">Planes activos evaluados con los datos disponibles.</p>
        </div>
        <span class="rounded-lg bg-primary-subtle px-3 py-1.5 text-sm font-bold text-primary">{{ preventiveTotal }}</span>
      </div>

      <div v-if="preventiveTotal > 0" class="mt-5 flex flex-col items-center gap-5 sm:flex-row sm:justify-center sm:gap-8">
        <div class="relative size-36 shrink-0" role="img" :aria-label="`${preventiveTotal} planes preventivos distribuidos por estado`">
          <svg class="size-full -rotate-90" viewBox="0 0 100 100" aria-hidden="true">
            <circle cx="50" cy="50" r="42" fill="none" stroke="rgb(var(--surface-muted))" stroke-width="12" />
            <circle
              v-for="segment in segments"
              :key="segment.status"
              cx="50"
              cy="50"
              r="42"
              fill="none"
              :stroke="statusColor(segment.status)"
              stroke-width="12"
              :stroke-dasharray="`${segment.length} ${circumference - segment.length}`"
              :stroke-dashoffset="-segment.offset"
              stroke-linecap="butt"
            />
          </svg>
          <div class="absolute inset-0 flex flex-col items-center justify-center">
            <span class="text-3xl font-bold tracking-tight text-ink">{{ preventiveTotal }}</span>
            <span class="text-xs text-ink-muted">planes</span>
          </div>
        </div>
        <ul class="grid w-full gap-2 sm:max-w-xs">
          <li v-for="row in preventiveByState" :key="row.status" class="flex items-center justify-between gap-4 text-sm">
            <span class="inline-flex min-w-0 items-center gap-2 text-ink-muted">
              <span class="size-2.5 shrink-0 rounded-full" :style="{ backgroundColor: statusColor(row.status) }" aria-hidden="true"></span>
              <span class="truncate">{{ row.label }}</span>
            </span>
            <span class="font-semibold tabular-nums text-ink">{{ row.count }}</span>
          </li>
        </ul>
      </div>
      <p v-else class="mt-5 rounded-lg bg-surface-subtle px-4 py-5 text-sm text-ink-muted">Todavía no hay planes preventivos con datos suficientes para graficar.</p>
    </article>

    <article class="rounded-xl border border-border bg-surface-raised p-5 shadow-card sm:p-6">
      <div class="flex items-start justify-between gap-3">
        <div>
          <h2 class="text-base font-bold text-ink sm:text-lg">Órdenes abiertas por estado</h2>
          <p class="mt-1 text-sm text-ink-muted">Trabajo pendiente dentro de tus sucursales habilitadas.</p>
        </div>
        <span class="rounded-lg bg-primary-subtle px-3 py-1.5 text-sm font-bold text-primary">{{ openOrdersByState.reduce((sum, row) => sum + row.count, 0) }}</span>
      </div>

      <ul v-if="visibleOrderStates.length" class="mt-6 space-y-5">
        <li v-for="row in visibleOrderStates" :key="row.status">
          <div class="mb-2 flex items-center justify-between gap-4 text-sm">
            <span class="truncate text-ink-muted">{{ row.label }}</span>
            <span class="font-semibold tabular-nums text-ink">{{ row.count }}</span>
          </div>
          <div class="h-2.5 overflow-hidden rounded-full bg-surface-muted">
            <div class="h-full rounded-full transition-[width] duration-500" :class="barTone(row.status)" :style="{ width: `${(row.count / largestOrderGroup) * 100}%` }"></div>
          </div>
        </li>
      </ul>
      <p v-else class="mt-5 rounded-lg bg-surface-subtle px-4 py-5 text-sm text-ink-muted">No hay órdenes abiertas para mostrar en tu alcance.</p>
    </article>
  </section>
</template>
