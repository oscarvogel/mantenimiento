<script setup>
// Estado de la flota: dona de disponibilidad y los equipos detenidos y en taller más antiguos.
import { ArrowRightIcon, TruckIcon, WrenchScrewdriverIcon } from '@heroicons/vue/24/outline'
import { computed } from 'vue'
import { formatNumber, formatRelativeDays } from '../../../ui/format.js'

const props = defineProps({
  fleet: { type: Object, required: true },
})

const RADIUS = 78
const CIRCUMFERENCE = 2 * Math.PI * RADIUS
const GAP = 3

const parts = computed(() => [
  { key: 'operative', tone: 'operative', label: 'Operativos', value: props.fleet.counts?.operative ?? 0, stroke: 'stroke-primary-300', swatch: 'bg-primary-300' },
  { key: 'inShop', tone: 'in-shop', label: 'En taller', value: props.fleet.counts?.inShop ?? 0, stroke: 'stroke-accent-400', swatch: 'bg-accent-400' },
  { key: 'stopped', tone: 'stopped', label: 'Detenidos', value: props.fleet.counts?.stopped ?? 0, stroke: 'stroke-danger', swatch: 'bg-danger' },
])
const total = computed(() => props.fleet.total ?? 0)

// Tramos consecutivos alrededor del círculo; los vacíos no se dibujan.
const segments = computed(() => {
  if (total.value <= 0) return []
  let offset = 0
  return parts.value.flatMap((part) => {
    const length = (CIRCUMFERENCE * part.value) / total.value
    const start = offset
    offset += length
    if (part.value <= 0) return []
    return [{ ...part, dash: `${Math.max(length - GAP, 1)} ${CIRCUMFERENCE}`, dashOffset: -start }]
  })
})

const summary = computed(() => {
  const [operative, inShop, stopped] = parts.value.map((part) => formatNumber(part.value))
  return `${formatNumber(total.value)} equipos activos: ${operative} operativos, ${inShop} en taller y ${stopped} detenidos.`
})

const lists = computed(() => [
  {
    key: 'stopped',
    test: 'stopped',
    title: 'Detenidos',
    count: props.fleet.counts?.stopped ?? 0,
    items: props.fleet.stopped ?? [],
    empty: 'No hay equipos detenidos.',
    icon: TruckIcon,
    iconTone: 'bg-danger-subtle text-danger-strong',
    sinceLabel: 'parado',
  },
  {
    key: 'inShop',
    test: 'in-shop',
    title: 'En taller',
    count: props.fleet.counts?.inShop ?? 0,
    items: props.fleet.inShop ?? [],
    empty: 'No hay equipos en taller.',
    icon: WrenchScrewdriverIcon,
    iconTone: 'bg-accent-subtle text-accent-800 dark:text-accent-300',
    sinceLabel: 'en taller',
  },
])

const submitOnChange = (event) => event.target.form?.requestSubmit()
</script>

<template>
  <aside aria-labelledby="fleet-heading" class="self-start rounded-panel bg-surface-raised p-6 shadow-card">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h2 id="fleet-heading" class="text-lg font-semibold text-ink">Estado de la flota</h2>
      <form v-if="fleet.branches?.length > 1" method="get" :action="fleet.formUrl">
        <label class="sr-only" for="fleet-branch">Sucursal</label>
        <select
          id="fleet-branch"
          name="sucursal"
          class="rounded-pill border border-border-subtle bg-surface-raised py-1 pl-3 pr-8 text-xs text-ink-muted"
          @change="submitOnChange"
        >
          <option value="" :selected="fleet.branchId === null">Todas las sucursales</option>
          <option v-for="branch in fleet.branches" :key="branch.id" :value="String(branch.id)" :selected="fleet.branchId === branch.id">{{ branch.name }}</option>
        </select>
        <button type="submit" class="sr-only">Ver sucursal</button>
      </form>
    </div>

    <p data-test="donut-summary" class="sr-only">{{ total > 0 ? summary : 'Sin datos de equipos activos.' }}</p>
    <div class="relative mx-auto mt-3 size-[250px]">
      <svg viewBox="0 0 200 200" class="size-full -rotate-90" aria-hidden="true" focusable="false">
        <circle cx="100" cy="100" :r="RADIUS" fill="none" class="stroke-surface-muted" stroke-width="26" />
        <circle
          v-for="segment in segments"
          :key="segment.key"
          data-test="donut-segment"
          :data-tone="segment.tone"
          cx="100"
          cy="100"
          :r="RADIUS"
          fill="none"
          stroke-width="26"
          :class="segment.stroke"
          :stroke-dasharray="segment.dash"
          :stroke-dashoffset="segment.dashOffset"
        />
      </svg>
      <div data-test="donut-total" class="absolute inset-0 grid place-content-center text-center" aria-hidden="true">
        <span class="text-sm text-ink-muted">Equipos activos</span>
        <span v-if="total > 0" class="text-3xl font-semibold tabular-nums tracking-tight text-ink">{{ formatNumber(total) }}</span>
        <span v-else class="text-lg font-semibold text-ink-muted">Sin datos</span>
      </div>
    </div>

    <ul data-test="donut-legend" class="mt-4 flex flex-wrap justify-between gap-2 text-xs text-ink-muted" aria-hidden="true">
      <li v-for="part in parts" :key="part.key" class="flex items-center gap-1.5">
        <span class="size-3 rounded" :class="part.swatch"></span>{{ part.label }} {{ formatNumber(part.value) }}
      </li>
    </ul>

    <section v-for="list in lists" :key="list.key" class="mt-5">
      <div class="mb-2 flex items-baseline justify-between">
        <h3 :data-test="`${list.test}-heading`" class="text-sm font-semibold text-ink">{{ list.title }} ({{ formatNumber(list.count) }})</h3>
        <a v-if="list.count > 0" :href="fleet.allUrl" class="text-xs text-ink-muted hover:text-ink">Ver todos</a>
      </div>
      <ul v-if="list.items.length" :data-test="`${list.test}-list`" class="space-y-1">
        <li v-for="item in list.items" :key="item.equipmentId">
          <a :href="item.href" class="ui-interactive group -mx-2 flex items-center gap-3 rounded-xl px-2 py-2 hover:bg-surface-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-border-focus">
            <span class="flex size-11 shrink-0 items-center justify-center rounded-[14px]" :class="list.iconTone" aria-hidden="true">
              <component :is="list.icon" class="size-5" />
            </span>
            <span class="min-w-0 flex-1">
              <span class="block truncate text-sm font-semibold text-ink">{{ item.label }}</span>
              <span class="block truncate text-xs text-ink-subtle">{{ [item.branchName, item.reason].filter(Boolean).join(' · ') }}</span>
            </span>
            <span class="shrink-0 text-right text-xs">
              <span class="block font-semibold text-ink">{{ item.orderNumber }}</span>
              <span class="block text-ink-subtle">{{ list.sinceLabel }} {{ formatRelativeDays(item.since, new Date(), 'sin fecha') }}</span>
            </span>
            <ArrowRightIcon class="size-4 shrink-0 text-ink-subtle opacity-0 transition-opacity group-hover:opacity-100" aria-hidden="true" />
          </a>
        </li>
      </ul>
      <p v-else class="text-sm text-ink-muted">{{ list.empty }}</p>
    </section>
  </aside>
</template>
