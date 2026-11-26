<script setup>
import * as format from '../../ui/format.js'
import { computed, ref } from 'vue'
import { ArrowPathIcon, ChartBarSquareIcon, PlusIcon, TruckIcon } from '@heroicons/vue/24/outline'
import EmptyState from './components/EmptyState.vue'
import PageHeading from './components/PageHeading.vue'
import PanelCard from './components/PanelCard.vue'
import AutocompleteSelect from './components/AutocompleteSelect.vue'
import { fieldClass, primaryButton, secondaryButton, tripRouteCounts, tripRouteLabel } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })

const filters = computed(() => props.data.filters ?? {})
const options = computed(() => props.data.options ?? { branches: [], statuses: [], equipment: [] })
const trips = computed(() => props.data.trips ?? [])
const pagination = computed(() => ({
  page: 1,
  perPage: 25,
  perPageOptions: [10, 25, 50, 100],
  total: 0,
  pages: 1,
  from: 0,
  to: 0,
  ...(props.data.pagination ?? {}),
}))
const numberFormat = new Intl.NumberFormat('es-AR')
const fmtCount = (value) => numberFormat.format(Number(value ?? 0))
const isFirstPage = computed(() => pagination.value.page <= 1)
const isLastPage = computed(() => pagination.value.page >= pagination.value.pages)
const pageNumbers = computed(() => Array.from({ length: pagination.value.pages }, (_, index) => index + 1))
const links = computed(() => props.data.links ?? { index: '/viajes/operativos', dashboard: '/viajes/operativos/dashboard', create: '/viajes/operativos/nuevo' })
const statusTone = {
  PLANIFICADO: 'bg-info-subtle text-info-strong',
  EN_CURSO: 'bg-success-subtle text-success-strong',
  FINALIZADO: 'bg-surface-muted text-ink-muted',
  CANCELADO: 'bg-danger-subtle text-danger-strong',
}

// El filtro de patente y el orden viajan en la URL: el formulario es GET.
const plate = ref(props.data.filters?.plate ?? '')
const order = ref(props.data.filters?.order ?? 'desc')

const fmtDate = (value) => format.formatDate(value)
// Filtros, orden y tamaño viajan en la URL: navegar entre páginas los conserva
// y volver desde el detalle de un viaje recupera la misma página (#532).
const listUrl = (changes) => {
  const query = new URLSearchParams(window.location.search)
  for (const [key, value] of Object.entries(changes)) {
    if (value === null) query.delete(key)
    else query.set(key, String(value))
  }
  const suffix = query.toString()
  return `${links.value.index}${suffix ? `?${suffix}` : ''}`
}
const pageUrl = (page) => listUrl({ pagina: page <= 1 ? null : page })
// Cambiar el tamaño vuelve a la primera página: la posición anterior deja de tener sentido.
const pageSizeUrl = (size) => listUrl({ pagina: null, por_pagina: Number(size) === 25 ? null : size })
const goToPage = (event) => window.location.assign(pageUrl(Number(event.target.value)))
const changePageSize = (event) => window.location.assign(pageSizeUrl(event.target.value))
</script>

<template>
  <div>
    <PageHeading eyebrow="Viajes" title="Viajes operativos" description="Planificá recorridos, asigná equipo y choferes, y seguí el estado de cada viaje desde que sale hasta que regresa.">
      <template #actions>
        <a :href="links.dashboard ?? '/viajes/operativos/dashboard'" :class="secondaryButton"><ChartBarSquareIcon class="mr-2 size-4" aria-hidden="true" />Tablero</a>
        <a v-if="data.canOperate" :href="links.create" :class="primaryButton"><PlusIcon class="mr-2 size-4" aria-hidden="true" />Nuevo viaje</a>
      </template>
    </PageHeading>

    <p class="mb-5 rounded-xl border border-border bg-surface-raised px-4 py-3 text-sm text-ink-muted">
      Este listado muestra solo viajes operativos planificados en el sistema. Los viajes importados del ERP anterior se consultan en
      <a :href="links.history ?? '/viajes'" class="font-semibold text-primary hover:text-primary-hover">Historial</a>.
    </p>

    <PanelCard title="Filtros" class="mb-5">
      <form method="get" class="grid gap-4 md:grid-cols-2 xl:grid-cols-4 xl:items-end">
        <!-- Aplicar filtros vuelve a la primera página y conserva el tamaño elegido. -->
        <input v-if="pagination.perPage !== 25" type="hidden" name="por_pagina" :value="pagination.perPage" />
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
          <span class="mb-1.5 block text-sm font-semibold text-ink">Patente</span>
          <AutocompleteSelect v-model="plate" name="patente" :options="options.equipment ?? []" placeholder="Elegí tractor o acoplado" />
          <span class="mt-1 block text-xs text-ink-muted">Trae todos los viajes del equipo, del más nuevo al más viejo.</span>
        </label>
        <label class="block">
          <span class="mb-1.5 block text-sm font-semibold text-ink">Orden</span>
          <select name="orden" :class="fieldClass">
            <option value="desc" :selected="order === 'desc'">Más recientes primero</option>
            <option value="asc" :selected="order === 'asc'">Más antiguos primero</option>
          </select>
          <span class="mt-1 block text-xs text-ink-muted">Para ver la historia de una patente, ordená de más antigua a más nueva.</span>
        </label>
        <label class="block">
          <span class="mb-1.5 block text-sm font-semibold text-ink">Buscar</span>
          <input type="search" name="buscar" :value="filters.search ?? ''" placeholder="Número u observaciones" :class="fieldClass" />
        </label>
        <div class="flex flex-wrap gap-2 md:col-span-2 xl:col-span-4">
          <button type="submit" :class="primaryButton"><ArrowPathIcon class="mr-2 size-4" aria-hidden="true" />Aplicar filtros</button>
          <a :href="links.index" :class="secondaryButton">Limpiar</a>
        </div>
      </form>
    </PanelCard>

    <PanelCard v-if="trips.length === 0 && data.hasActiveFilters" title="Viajes">
      <EmptyState title="Ningún viaje cumple estos filtros" description="Probá con otro período, estado o patente, o limpiá los filtros para ver todos los viajes." />
      <div class="mt-2 text-center">
        <a :href="links.index" :class="secondaryButton">Limpiar filtros</a>
      </div>
    </PanelCard>

    <PanelCard v-else-if="trips.length === 0" title="Viajes">
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
              <td class="px-4 py-3">
                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold" :class="statusTone[trip.estado] ?? 'bg-surface-muted text-ink-muted'">{{ trip.estado_label }}</span>
                <span v-if="trip.recorrido_cerrado && trip.estado === 'EN_CURSO'" class="mt-1 block">
                  <span class="inline-flex rounded-full bg-warning-subtle px-2.5 py-1 text-xs font-semibold text-warning-strong">Recorrido cerrado</span>
                  <span class="mt-0.5 block text-xs text-ink-muted">Falta cerrar el viaje</span>
                </span>
              </td>
              <td class="whitespace-nowrap px-4 py-3 text-ink-muted">{{ fmtDate(trip.fecha_salida_real || trip.fecha_salida_planificada) }}</td>
              <td class="px-4 py-3">
                <p class="font-semibold text-ink">{{ tripRouteLabel(trip) }}</p>
                <p class="mt-0.5 text-xs text-ink-muted">{{ tripRouteCounts(trip) }}</p>
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
      <nav aria-label="Paginación de viajes operativos" class="mt-4 flex flex-col gap-3 border-t border-border-subtle pt-4 lg:flex-row lg:items-center lg:justify-between">
        <p class="text-sm text-ink-muted" aria-live="polite">
          Mostrando <span class="font-semibold text-ink">{{ fmtCount(pagination.from) }}–{{ fmtCount(pagination.to) }}</span>
          de <span class="font-semibold text-ink">{{ fmtCount(pagination.total) }}</span> {{ Number(pagination.total) === 1 ? 'viaje' : 'viajes' }}
          · Página {{ pagination.page }} de {{ pagination.pages }}
        </p>
        <div class="flex flex-wrap items-center gap-2">
          <label class="flex items-center gap-2 text-sm font-semibold text-ink-muted">
            Mostrar
            <select :value="pagination.perPage" class="min-h-10 rounded-lg border border-border bg-white px-2 text-ink" aria-label="Viajes por página" @change="changePageSize">
              <option v-for="size in pagination.perPageOptions" :key="size" :value="size">{{ size }}</option>
            </select>
          </label>
          <template v-if="pagination.pages > 1">
            <span v-if="isFirstPage" :class="[secondaryButton, 'cursor-not-allowed opacity-50']" aria-disabled="true">Primera</span>
            <a v-else :href="pageUrl(1)" :class="secondaryButton">Primera</a>
            <span v-if="isFirstPage" :class="[secondaryButton, 'cursor-not-allowed opacity-50']" aria-disabled="true">Anterior</span>
            <a v-else :href="pageUrl(pagination.page - 1)" :class="secondaryButton" rel="prev">Anterior</a>
            <label class="flex items-center gap-2 text-sm font-semibold text-ink-muted">
              Página
              <select :value="pagination.page" class="min-h-10 rounded-lg border border-border bg-white px-2 text-ink" aria-label="Ir a la página" @change="goToPage">
                <option v-for="number in pageNumbers" :key="number" :value="number">{{ number }}</option>
              </select>
            </label>
            <span v-if="isLastPage" :class="[secondaryButton, 'cursor-not-allowed opacity-50']" aria-disabled="true">Siguiente</span>
            <a v-else :href="pageUrl(pagination.page + 1)" :class="secondaryButton" rel="next">Siguiente</a>
            <span v-if="isLastPage" :class="[secondaryButton, 'cursor-not-allowed opacity-50']" aria-disabled="true">Última</span>
            <a v-else :href="pageUrl(pagination.pages)" :class="secondaryButton">Última</a>
          </template>
        </div>
      </nav>
    </PanelCard>
  </div>
</template>
