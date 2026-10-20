<script setup>
import { computed, reactive } from 'vue'
import { ArrowPathIcon, MagnifyingGlassIcon, XCircleIcon } from '@heroicons/vue/24/outline'
import AdminPageHeading from './components/AdminPageHeading.vue'
import CsrfField from './components/CsrfField.vue'
import PanelCard from '../operations/components/PanelCard.vue'
import StatusBadge from '../operations/components/StatusBadge.vue'
import { fieldClass, primaryButton, secondaryButton } from '../operations/helpers.js'

const props = defineProps({ data: { type: Object, required: true } })

const items = computed(() => props.data.items ?? [])
const pagination = computed(() => props.data.pagination ?? { page: 1, pages: 1, total: 0 })
const actions = computed(() => props.data.actions ?? {})
const status = computed(() => props.data.status ?? '')
const searchTerms = reactive({})
const searchResults = reactive({})
const searching = reactive({})

const fmt = (value) => Number(value ?? 0).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const fmtDate = (value) => {
  const match = String(value ?? '').match(/^(\d{4})-(\d{2})-(\d{2})/)
  return match ? `${match[3]}/${match[2]}/${match[1]}` : 'Sin fecha'
}
const pageUrl = (page) => {
  const query = new URLSearchParams(window.location.search)
  if (page <= 1) query.delete('page')
  else query.set('page', String(page))
  const suffix = query.toString()
  return `${actions.value.review}${suffix ? `?${suffix}` : ''}`
}
const search = async (item) => {
  const term = (searchTerms[item.id] ?? '').trim()
  if (term === '') {
    searchResults[item.id] = []
    return
  }
  searching[item.id] = true
  try {
    const response = await fetch(`${actions.value.search}?q=${encodeURIComponent(term)}`, {
      headers: { Accept: 'application/json' },
    })
    const payload = await response.json()
    searchResults[item.id] = payload.trips ?? []
  } catch {
    searchResults[item.id] = []
  } finally {
    searching[item.id] = false
  }
}
</script>

<template>
  <main class="space-y-6">
    <nav class="flex flex-wrap gap-4 text-sm font-semibold text-ink-muted" aria-label="Volver">
      <a :href="actions.superadmin" class="text-primary hover:text-primary-hover">Administración global</a>
      <a :href="actions.import" class="text-primary hover:text-primary-hover">Volver a la importación</a>
    </nav>
    <AdminPageHeading
      eyebrow="Administración global"
      title="Revisar facturas de viajes"
      description="El sistema propone un viaje por unidad, fecha, ruta e importe. Confirmá los vínculos que correspondan: solo las facturas confirmadas o automáticas se usan para calcular rentabilidad."
    />
    <p v-if="data.flash?.error" role="alert" class="rounded-xl border border-danger/30 bg-danger-subtle p-4 text-danger-strong">{{ data.flash.error }}</p>
    <p v-if="data.flash?.success" role="status" class="rounded-xl border border-success/30 bg-success-subtle p-4 text-success-strong">{{ data.flash.success }}</p>

    <PanelCard title="Filtros" class="mb-5">
      <form method="get" :action="actions.review" class="flex flex-wrap items-end gap-3">
        <label class="block min-w-56">
          <span class="mb-1.5 block text-sm font-semibold text-ink">Estado del vínculo</span>
          <select name="estado" :class="fieldClass">
            <option v-for="option in data.statuses" :key="option.value" :value="option.value" :selected="option.value === status">{{ option.label }}</option>
          </select>
        </label>
        <button type="submit" :class="primaryButton"><ArrowPathIcon class="mr-2 size-4" aria-hidden="true" />Aplicar</button>
      </form>
    </PanelCard>

    <p class="text-sm text-ink-muted">{{ data.import?.company }} · importación #{{ data.import?.id }} · {{ pagination.total }} facturas</p>

    <section class="space-y-4">
      <article v-for="item in items" :key="item.id" class="rounded-2xl border border-border bg-surface-raised p-5 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-4">
          <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Factura #{{ item.clave_legacy }} · {{ item.tipo || 'sin tipo' }} · {{ fmtDate(item.fecha) }}</p>
            <h2 class="mt-1 text-lg font-bold text-ink">{{ item.unit || 'Sin unidad' }} · $ {{ fmt(item.total_ars ?? item.total) }}</h2>
            <p class="mt-1 text-sm text-ink-muted">{{ item.origin || 'Origen sin dato' }} → {{ item.destination || 'Destino sin dato' }}</p>
            <p v-if="item.remitos?.length" class="mt-0.5 text-xs text-ink-muted">Remitos: {{ item.remitos.join(' · ') }}</p>
          </div>
          <div class="text-right">
            <StatusBadge v-if="item.link?.estado" :status="item.link.estado" />
            <p v-else class="text-xs font-semibold text-warning-strong">Sin vínculo</p>
            <p v-if="item.link?.trip_number" class="mt-2 text-sm font-semibold text-ink">Viaje #{{ item.link.trip_number }} · {{ item.link.trip_domain }}</p>
            <p v-if="item.link?.trip_date" class="text-xs text-ink-muted">{{ fmtDate(item.link.trip_date) }}</p>
          </div>
        </div>

        <div class="mt-4 grid gap-4 lg:grid-cols-2">
          <div class="rounded-xl border border-border-subtle bg-surface p-4">
            <p class="text-sm font-bold text-ink">Sugerencias del sistema</p>
            <p v-if="!item.candidates.length" class="mt-1 text-sm text-ink-muted">Sin candidatos automáticos. Buscá el viaje manualmente.</p>
            <ul v-else class="mt-3 space-y-2">
              <li v-for="candidate in item.candidates" :key="candidate.trip_id" class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border-subtle bg-surface-raised px-3 py-2">
                <div>
                  <p class="text-sm font-semibold text-ink">Viaje #{{ candidate.trip_number }} · {{ candidate.trip_domain }}</p>
                  <p class="text-xs text-ink-muted">{{ fmtDate(candidate.trip_date) }} · puntaje {{ candidate.score }} · {{ candidate.confidence }}</p>
                </div>
                <form method="post" :action="`${actions.review}/${item.id}/viaje`">
                  <CsrfField :csrf="data.csrf" />
                  <input type="hidden" name="viaje_id" :value="candidate.trip_id" />
                  <button type="submit" :class="secondaryButton">Vincular</button>
                </form>
              </li>
            </ul>
          </div>

          <div class="rounded-xl border border-border-subtle bg-surface p-4">
            <p class="text-sm font-bold text-ink">Buscar otro viaje</p>
            <div class="mt-2 flex flex-wrap items-end gap-2">
              <label class="block min-w-48 flex-1">
                <span class="mb-1 block text-xs font-semibold text-ink-muted">Dominio o número de viaje</span>
                <input v-model="searchTerms[item.id]" type="search" :class="fieldClass" @keyup.enter="search(item)" />
              </label>
              <button type="button" :class="secondaryButton" :disabled="searching[item.id]" @click="search(item)">
                <MagnifyingGlassIcon class="mr-1.5 size-4" aria-hidden="true" />{{ searching[item.id] ? 'Buscando…' : 'Buscar' }}
              </button>
            </div>
            <ul v-if="searchResults[item.id]?.length" class="mt-3 space-y-2">
              <li v-for="trip in searchResults[item.id]" :key="trip.id" class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border-subtle bg-surface-raised px-3 py-2">
                <div>
                  <p class="text-sm font-semibold text-ink">Viaje #{{ trip.number }} · {{ trip.domain }}</p>
                  <p class="text-xs text-ink-muted">{{ fmtDate(trip.date) }}</p>
                </div>
                <form method="post" :action="`${actions.review}/${item.id}/viaje`">
                  <CsrfField :csrf="data.csrf" />
                  <input type="hidden" name="viaje_id" :value="trip.id" />
                  <button type="submit" :class="secondaryButton">Vincular</button>
                </form>
              </li>
            </ul>
            <p v-else-if="searchResults[item.id]" class="mt-2 text-sm text-ink-muted">Sin viajes para esa búsqueda.</p>
          </div>
        </div>

        <div v-if="item.link" class="mt-4 border-t border-border-subtle pt-4">
          <form method="post" :action="`${actions.review}/${item.id}/desvincular`">
            <CsrfField :csrf="data.csrf" />
            <button type="submit" :class="secondaryButton"><XCircleIcon class="mr-1.5 size-4" aria-hidden="true" />Quitar vínculo</button>
          </form>
        </div>
      </article>
    </section>

    <nav v-if="pagination.pages > 1" class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-surface-raised px-4 py-3">
      <p class="text-xs text-ink-muted">Página {{ pagination.page }} de {{ pagination.pages }}</p>
      <div class="flex gap-2">
        <a :href="pageUrl(pagination.page - 1)" :class="[secondaryButton, pagination.page <= 1 ? 'pointer-events-none opacity-50' : '']">Anterior</a>
        <a :href="pageUrl(pagination.page + 1)" :class="[secondaryButton, pagination.page >= pagination.pages ? 'pointer-events-none opacity-50' : '']">Siguiente</a>
      </div>
    </nav>
  </main>
</template>
