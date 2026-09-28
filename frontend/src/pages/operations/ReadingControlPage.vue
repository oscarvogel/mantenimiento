<script setup>
import { computed } from 'vue'
import {
  ArrowPathIcon,
  ArrowTopRightOnSquareIcon,
  ClockIcon,
  ExclamationTriangleIcon,
  MagnifyingGlassIcon,
} from '@heroicons/vue/24/outline'
import EmptyState from './components/EmptyState.vue'
import FormField from './components/FormField.vue'
import PageHeading from './components/PageHeading.vue'
import PaginationBar from './components/PaginationBar.vue'
import PanelCard from './components/PanelCard.vue'
import { fieldClass, formatNumberEs, primaryButton, secondaryButton } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })

const data = computed(() => ({
  ...props.data,
  results: props.data.results ?? [],
  catalogs: props.data.catalogs ?? {},
  routes: props.data.routes ?? {},
  summary: props.data.summary ?? { total: 0, sinLectura: 0, antiguos: 0, alDia: 0 },
  pagination: props.data.pagination ?? { page: 1, perPage: 25, total: 0, totalPages: 1 },
  filters: props.data.filters ?? { q: '', branchId: '', typeId: '', filter: 'all', sort: 'age_asc' },
}))

const statusOptions = computed(() => data.value.catalogs.statusOptions ?? [])
const sortOptions = computed(() => data.value.catalogs.sortOptions ?? [])
const branches = computed(() => data.value.catalogs.branches ?? [])
const types = computed(() => data.value.catalogs.types ?? [])

// Antigüedad: el peor caso (nunca leído) se distingue de "hace muchos días"
// porque para el operador son dos problemas distintos.
const statusFor = (item) => {
  if (!item.hasReading) return 'SIN_LECTURA'
  const days = Number(item.daysSinceLastReading ?? 0)
  if (days === 0) return 'HOY'
  if (days > 7) return 'ANTIGUO'
  if (days > 3) return 'REVISAR'
  return 'AL_DIA'
}

const antiquityClass = (item) => {
  const status = statusFor(item)
  if (status === 'SIN_LECTURA') return 'bg-danger-subtle text-danger-strong'
  if (status === 'ANTIGUO') return 'bg-danger-subtle text-danger-strong'
  if (status === 'REVISAR') return 'bg-warning-subtle text-warning-foreground'
  return 'bg-success-subtle text-success-strong'
}

const antiquityLabel = (item) => {
  if (!item.hasReading) return 'Sin lectura'
  const days = Number(item.daysSinceLastReading ?? 0)
  if (days === 0) return 'Hoy'
  return `hace ${days} ${days === 1 ? 'día' : 'días'}`
}

const kmLabel = (item) => (item.lastKm === null || item.lastKm === undefined ? '—' : formatNumberEs(item.lastKm, 0))

const readingLabel = (item) => {
  if (!item.lastReadingAt) return 'Nunca registrado'
  return String(item.lastReadingAt).replace('T', ' ').slice(0, 16)
}
</script>

<template>
  <div>
    <PageHeading
      eyebrow="Lecturas"
      title="Control de lecturas de kilometraje"
      description="Consultá la última lectura registrada de cada equipo y detectá cuáles tienen los registros más antiguos. Esta pantalla es solo de consulta: no envía mensajes ni genera reclamos."
    >
      <template #actions>
        <span
          class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-border bg-surface-muted px-3.5 py-2 text-sm font-semibold text-ink-muted"
        >
          <ArrowPathIcon class="size-4" aria-hidden="true" />
          Solo consulta
        </span>
        <a v-if="data.routes.quickReadings" :href="data.routes.quickReadings" :class="secondaryButton">
          Registrar lectura
        </a>
      </template>
    </PageHeading>

    <section class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Resumen de lecturas">
      <div class="rounded-xl border border-border bg-surface-raised p-5">
        <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Equipos</p>
        <p class="mt-2 text-3xl font-bold text-ink">{{ data.summary.total }}</p>
      </div>
      <div class="rounded-xl border border-danger/20 bg-danger-subtle p-5">
        <p class="text-xs font-semibold uppercase tracking-wide text-danger-strong">Sin lectura</p>
        <p class="mt-2 text-3xl font-bold text-danger-strong">{{ data.summary.sinLectura }}</p>
      </div>
      <div class="rounded-xl border border-warning/25 bg-warning-subtle p-5">
        <p class="text-xs font-semibold uppercase tracking-wide text-warning-foreground">Más de 7 días</p>
        <p class="mt-2 text-3xl font-bold text-warning-foreground">{{ data.summary.antiguos }}</p>
      </div>
      <div class="rounded-xl border border-border bg-surface-raised p-5">
        <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">En esta página</p>
        <p class="mt-2 text-3xl font-bold text-ink">{{ data.results.length }}</p>
      </div>
    </section>

    <PanelCard title="Buscar y filtrar" class="mb-6">
      <form method="get" :action="data.routes.index" class="grid gap-3 lg:grid-cols-[minmax(15rem,1fr)_11rem_11rem_13rem_13rem_auto] lg:items-end">
        <FormField label="Equipo, patente, chasis o chofer" for-id="reading-control-search">
          <div class="relative">
            <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-ink-muted" aria-hidden="true" />
            <input
              id="reading-control-search"
              name="q"
              :value="data.filters.q"
              placeholder="Ej.: BEN4G47 o_PEREZ"
              :class="`${fieldClass} pl-9`"
            />
          </div>
        </FormField>

        <FormField label="Sucursal" for-id="reading-control-branch">
          <select id="reading-control-branch" name="sucursal_id" :class="fieldClass">
            <option value="">Todas</option>
            <option
              v-for="branch in branches"
              :key="branch.id"
              :value="branch.id"
              :selected="String(data.filters.branchId ?? '') === String(branch.id)"
            >
              {{ branch.name }}
            </option>
          </select>
        </FormField>

        <FormField label="Tipo de equipo" for-id="reading-control-type">
          <select id="reading-control-type" name="tipo_id" :class="fieldClass">
            <option value="">Todos</option>
            <option
              v-for="type in types"
              :key="type.id"
              :value="type.id"
              :selected="String(data.filters.typeId ?? '') === String(type.id)"
            >
              {{ type.name }}
            </option>
          </select>
        </FormField>

        <FormField label="Antigüedad" for-id="reading-control-filter">
          <select id="reading-control-filter" name="filter" :class="fieldClass">
            <option
              v-for="option in statusOptions"
              :key="option.key"
              :value="option.key"
              :selected="data.filters.filter === option.key"
            >
              {{ option.label }}
            </option>
          </select>
        </FormField>

        <FormField label="Orden" for-id="reading-control-sort">
          <select id="reading-control-sort" name="sort" :class="fieldClass">
            <option
              v-for="option in sortOptions"
              :key="option.key"
              :value="option.key"
              :selected="(data.filters.sort ?? 'age_asc') === option.key"
            >
              {{ option.label }}
            </option>
          </select>
        </FormField>

        <button type="submit" :class="primaryButton">Aplicar</button>
      </form>
    </PanelCard>

    <PanelCard title="Lecturas por equipo" :count="data.results.length" flush>
      <EmptyState
        v-if="data.results.length === 0"
        title="No hay equipos para estos filtros"
        description="Probá cambiando la antigüedad, la sucursal, el tipo o la búsqueda."
      />

      <template v-else>
        <div class="hidden overflow-x-auto md:block">
          <table class="w-full min-w-[64rem] text-left text-sm">
            <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted">
              <tr>
                <th class="px-5 py-3">Equipo</th>
                <th class="px-5 py-3">Tipo</th>
                <th class="px-5 py-3">Sucursal</th>
                <th class="px-5 py-3">Chofer</th>
                <th class="px-5 py-3 text-right">Último KM</th>
                <th class="px-5 py-3">Última lectura</th>
                <th class="px-5 py-3">Antigüedad</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border-subtle">
              <tr v-for="item in data.results" :key="item.equipmentId" class="hover:bg-brand-50/50">
                <td class="px-5 py-4">
                  <div class="font-semibold text-ink">{{ item.equipmentCode }}</div>
                  <div class="mt-1 text-xs text-ink-muted">{{ item.equipmentPlate || 'Sin patente' }}</div>
                </td>
                <td class="px-5 py-4 text-ink-muted">{{ item.typeName }}</td>
                <td class="px-5 py-4 text-ink-muted">{{ item.branchName || '—' }}</td>
                <td class="px-5 py-4">
                  <div class="font-medium text-ink">{{ item.driverName }}</div>
                  <div class="mt-1 text-xs text-ink-muted">{{ item.driverPhone || 'Sin teléfono' }}</div>
                </td>
                <td class="px-5 py-4 text-right font-medium text-ink">{{ kmLabel(item) }}</td>
                <td class="px-5 py-4 text-ink-muted">{{ readingLabel(item) }}</td>
                <td class="px-5 py-4">
                  <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold" :class="antiquityClass(item)">
                    {{ antiquityLabel(item) }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="divide-y divide-border-subtle md:hidden">
          <article v-for="item in data.results" :key="item.equipmentId" class="space-y-3 p-4">
            <div class="flex items-start justify-between gap-3">
              <div>
                <p class="font-semibold text-ink">{{ item.equipmentCode }}</p>
                <p class="mt-1 text-sm text-ink-muted">{{ item.equipmentPlate || 'Sin patente' }} · {{ item.typeName }}</p>
              </div>
              <span class="inline-flex shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold" :class="antiquityClass(item)">
                {{ antiquityLabel(item) }}
              </span>
            </div>
            <p class="text-sm text-ink-muted">Sucursal: {{ item.branchName || '—' }}</p>
            <p class="text-sm text-ink-muted">
              Chofer: {{ item.driverName }}<template v-if="item.driverPhone"> · {{ item.driverPhone }}</template>
            </p>
            <div class="flex flex-wrap items-center gap-2 text-sm">
              <ExclamationTriangleIcon v-if="!item.hasReading" class="size-4 text-danger" aria-hidden="true" />
              <ClockIcon v-else class="size-4 text-ink-muted" aria-hidden="true" />
              <span class="font-medium text-ink">{{ kmLabel(item) }} km</span>
              <span class="text-ink-muted">· {{ readingLabel(item) }}</span>
            </div>
            <a v-if="item.equipmentUrl" :href="item.equipmentUrl" :class="secondaryButton" class="w-full justify-center">
              Ver equipo
              <ArrowTopRightOnSquareIcon class="ml-2 size-4" aria-hidden="true" />
            </a>
          </article>
        </div>
      </template>

      <template v-if="data.pagination.totalPages > 1 || data.pagination.perPage" #footer>
        <PaginationBar :pagination="data.pagination" />
      </template>
    </PanelCard>

    <p v-if="data.readOnly" class="mt-4 text-xs text-ink-subtle">
      Pantalla de solo consulta: no envía mensajes, no genera reclamos y no modifica datos.
    </p>
  </div>
</template>
