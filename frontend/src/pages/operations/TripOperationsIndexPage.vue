<script setup>
import { computed } from 'vue'
import { ArrowPathIcon, PlusIcon, TruckIcon } from '@heroicons/vue/24/outline'
import EmptyState from './components/EmptyState.vue'
import PageHeading from './components/PageHeading.vue'
import PanelCard from './components/PanelCard.vue'
import { fieldClass, primaryButton, secondaryButton } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })

const filters = computed(() => props.data.filters ?? {})
const options = computed(() => props.data.options ?? { branches: [], statuses: [] })
const trips = computed(() => props.data.trips ?? [])
const pagination = computed(() => props.data.pagination ?? { page: 1, perPage: 20, total: 0, pages: 1 })
const links = computed(() => props.data.links ?? { index: '/viajes/operativos', create: '/viajes/operativos/nuevo' })
const statusTone = {
  PLANIFICADO: 'bg-info-subtle text-info-strong',
  EN_CURSO: 'bg-success-subtle text-success-strong',
  FINALIZADO: 'bg-surface-muted text-ink-muted',
  CANCELADO: 'bg-danger-subtle text-danger-strong',
}

const fmtDate = (value) => {
  const match = String(value ?? '').match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/)
  return match ? `${match[3]}/${match[2]}/${match[1]} ${match[4]}:${match[5]}` : 'Sin fecha'
}
const pageUrl = (page) => {
  const query = new URLSearchParams(window.location.search)
  if (page <= 1) query.delete('pagina')
  else query.set('pagina', String(page))
  const suffix = query.toString()
  return `${links.value.index}${suffix ? `?${suffix}` : ''}`
}
</script>

<template>
  <div>
    <PageHeading eyebrow="Viajes" title="Viajes operativos" description="Planificá recorridos, asigná equipo y choferes, y seguí el estado de cada viaje desde que sale hasta que regresa.">
      <template #actions>
        <a v-if="data.canOperate" :href="links.create" :class="primaryButton"><PlusIcon class="mr-2 size-4" aria-hidden="true" />Nuevo viaje</a>
      </template>
    </PageHeading>

    <p class="mb-5 rounded-xl border border-border bg-surface-raised px-4 py-3 text-sm text-ink-muted">
      Este listado muestra solo viajes operativos planificados en el sistema. Los viajes importados del ERP anterior se consultan en
      <a :href="links.history ?? '/viajes'" class="font-semibold text-primary hover:text-primary-hover">Historial</a>.
    </p>

    <PanelCard title="Filtros" class="mb-5">
      <form method="get" class="grid gap-4 md:grid-cols-2 xl:grid-cols-5 xl:items-end">
        <label class="block">
          <span class="mb-1.5 block text-sm font-semibold text-ink">Desde</span>
          <input type="date" name="desde" :value="filters.from ?? ''" :class="fieldClass" />
        </label>
        <label class="block">
          <span class="mb-1.5 block text-sm font-semibold text-ink">Hasta</span>
          <input type="date" name="hasta" :value="filters.to ?? ''" :class="fieldClass" />
        </label>
        <label class="block">
          <span class="mb-1.5 block text-sm font-semibold text-ink">Sucursal</span>
          <select name="sucursal" :class="fieldClass">
            <option value="">Todas</option>
            <option v-for="branch in options.branches" :key="branch.id" :value="branch.id" :selected="String(branch.id) === String(filters.branchId ?? '')">{{ branch.code }} · {{ branch.name }}</option>
          </select>
        </label>
        <label class="block">
          <span class="mb-1.5 block text-sm font-semibold text-ink">Estado</span>
          <select name="estado" :class="fieldClass">
            <option value="">Todos</option>
            <option v-for="status in options.statuses" :key="status.value" :value="status.value" :selected="status.value === filters.status">{{ status.label }}</option>
          </select>
        </label>
        <label class="block">
          <span class="mb-1.5 block text-sm font-semibold text-ink">Buscar</span>
          <input type="search" name="buscar" :value="filters.search ?? ''" placeholder="Número u observaciones" :class="fieldClass" />
        </label>
        <div class="flex flex-wrap gap-2 md:col-span-2 xl:col-span-5">
          <button type="submit" :class="primaryButton"><ArrowPathIcon class="mr-2 size-4" aria-hidden="true" />Aplicar filtros</button>
          <a :href="links.index" :class="secondaryButton">Limpiar</a>
        </div>
      </form>
    </PanelCard>

    <PanelCard v-if="trips.length === 0" title="Viajes">
      <EmptyState title="Todavía no hay viajes operativos para mostrar" description="Cuando planifiques un viaje vas a ver acá su recorrido, equipo, choferes y estado. Los viajes importados se consultan en Historial." />
      <div v-if="data.canOperate" class="mt-2 text-center">
        <a :href="links.create" :class="primaryButton"><PlusIcon class="mr-2 size-4" aria-hidden="true" />Planificar el primer viaje</a>
      </div>
    </PanelCard>

    <PanelCard v-else title="Viajes" :count="pagination.total">
      <div class="overflow-x-auto">
        <table class="w-full min-w-[64rem] text-left text-sm">
          <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted">
            <tr>
              <th class="px-4 py-3">Viaje</th>
              <th class="px-4 py-3">Estado</th>
              <th class="px-4 py-3">Salida</th>
              <th class="px-4 py-3">Recorrido</th>
              <th class="px-4 py-3">Tractor / acoplado</th>
              <th class="px-4 py-3">Choferes</th>
              <th class="px-4 py-3 text-right">Paradas</th>
              <th class="px-4 py-3 text-right">Detalle</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border-subtle">
            <tr v-for="trip in trips" :key="trip.id">
              <td class="whitespace-nowrap px-4 py-3">
                <span class="flex items-center gap-2 font-semibold text-ink"><TruckIcon class="size-4 text-ink-muted" aria-hidden="true" />#{{ trip.numero }}</span>
                <span class="mt-0.5 block text-xs text-ink-muted">{{ trip.estado_label }}</span>
              </td>
              <td class="px-4 py-3"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold" :class="statusTone[trip.estado] ?? 'bg-surface-muted text-ink-muted'">{{ trip.estado_label }}</span></td>
              <td class="whitespace-nowrap px-4 py-3 text-ink-muted">{{ fmtDate(trip.fecha_salida_real || trip.fecha_salida_planificada) }}</td>
              <td class="px-4 py-3">
                <p class="font-semibold text-ink">{{ trip.origen ?? 'Sin origen' }} → {{ trip.destino ?? 'Sin destino' }}</p>
                <p class="mt-0.5 text-xs text-ink-muted">{{ trip.paradas }} paradas</p>
              </td>
              <td class="px-4 py-3 text-ink-muted">
                <p>{{ trip.tractor ?? 'Sin tractor' }}</p>
                <p v-if="trip.acoplado" class="text-xs">{{ (trip.acoplados ?? [trip.acoplado]).join(' · ') }}</p>
              </td>
              <td class="px-4 py-3 text-ink-muted">{{ (trip.choferes ?? []).join(' · ') || 'Sin chofer' }}</td>
              <td class="px-4 py-3 text-right text-ink-muted">{{ trip.paradas }}</td>
              <td class="px-4 py-3 text-right"><a :href="`${links.index}/${trip.id}`" class="font-semibold text-primary hover:text-primary-hover">Ver viaje</a></td>
            </tr>
          </tbody>
        </table>
      </div>
      <nav v-if="pagination.pages > 1" aria-label="Paginación de viajes operativos" class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-border-subtle pt-4">
        <p class="text-xs text-ink-muted">Página {{ pagination.page }} de {{ pagination.pages }} · {{ pagination.total }} viajes</p>
        <div class="flex gap-2">
          <a :href="pageUrl(pagination.page - 1)" :class="[secondaryButton, pagination.page <= 1 ? 'pointer-events-none opacity-50' : '']">Anterior</a>
          <a :href="pageUrl(pagination.page + 1)" :class="[secondaryButton, pagination.page >= pagination.pages ? 'pointer-events-none opacity-50' : '']">Siguiente</a>
        </div>
      </nav>
    </PanelCard>
  </div>
</template>
