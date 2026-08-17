<script setup>
import { computed, onBeforeUnmount, reactive, ref } from 'vue'
import { ArrowPathIcon, CheckCircleIcon, ExclamationTriangleIcon, MagnifyingGlassIcon, PrinterIcon, WrenchScrewdriverIcon } from '@heroicons/vue/24/outline'
import CsrfInput from './components/CsrfInput.vue'
import EmptyState from './components/EmptyState.vue'
import PageHeading from './components/PageHeading.vue'
import PaginationBar from './components/PaginationBar.vue'
import PanelCard from './components/PanelCard.vue'
import { fieldClass, formatHours, formatKilometers, kilometersDelta, normalizeDecimalInput, nowLocal, parseFlexibleNumber, parseKilometers, primaryButton, readingDelta, secondaryButton } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })
const results = ref([...props.data.results])
const saving = ref(false)
const totalToSave = ref(0)
const currentSavingIndex = ref(0)
const commonRecordedAt = ref(props.data.recordedAtDefault || nowLocal())
const statusFilter = ref('')
const filters = reactive({ q: props.data.filters.q || '', branchId: props.data.filters.branchId || '', typeId: props.data.filters.typeId || '' })
const rows = reactive(Object.fromEntries(props.data.equipment.items.map((equipment) => [equipment.id, { kilometers: '', hours: '', recordedAt: commonRecordedAt.value, currentKm: equipment.currentKm, currentHours: equipment.currentHours, status: 'pending', message: '' }])))
const maintenance = reactive(Object.fromEntries(props.data.equipment.items.map((equipment) => [equipment.id, equipment.maintenance || { state: 'SIN_PLAN', primaryPlan: null, plans: [], planCount: 0 }])))
const orderSaving = reactive({})
const orderErrors = reactive({})
const csrf = reactive({ ...props.data.csrf })
let filterTimer = null

const enteredRows = computed(() => props.data.equipment.items.filter(({ id }) => rows[id].kilometers !== '' || rows[id].hours !== ''))
const kilometerError = (equipment) => {
  const value = rows[equipment.id].kilometers
  if (value === '') return null
  const next = parseKilometers(value)
  if (next === null) return 'Ingresá kilómetros enteros, sin puntos ni comas.'
  const current = parseKilometers(rows[equipment.id].currentKm)
  return current !== null && next < current ? `No puede ser menor a ${formatKilometers(current)}.` : null
}
const hoursError = (equipment) => {
  const value = rows[equipment.id].hours
  if (value === '') return null
  const next = parseFlexibleNumber(value)
  if (next === null) return 'Ingresá horas con un decimal como máximo.'
  const current = parseFlexibleNumber(rows[equipment.id].currentHours)
  return current !== null && next < current ? `No puede ser menor a ${formatHours(current)}.` : null
}
const rowHasError = (equipment) => Boolean(kilometerError(equipment) || hoursError(equipment))
const readyRows = computed(() => enteredRows.value.filter((equipment) => !rowHasError(equipment)))
const invalidRows = computed(() => enteredRows.value.filter((equipment) => rowHasError(equipment)))
const maintenanceCounts = computed(() => {
  const counts = { OK: 0, PROXIMO: 0, VENCIDO: 0, PROBLEMA: 0, SIN_PLAN: 0 }
  props.data.equipment.items.forEach((equipment) => {
    const state = maintenance[equipment.id]?.state || 'SIN_PLAN'
    if (Object.hasOwn(counts, state)) counts[state] += 1
  })
  return counts
})
const visibleEquipment = computed(() => {
  const query = filters.q.trim().toLocaleLowerCase('es')
  return props.data.equipment.items.filter((equipment) => {
    const matchesQuery = !query || [equipment.code, equipment.plate, equipment.chassis, equipment.typeName, equipment.branchName].some((value) => String(value ?? '').toLocaleLowerCase('es').includes(query))
    return matchesQuery && (!statusFilter.value || (maintenance[equipment.id]?.state || 'SIN_PLAN') === statusFilter.value)
  })
})
const saveButtonLabel = computed(() => saving.value ? `Guardando ${currentSavingIndex.value} de ${totalToSave.value}…` : readyRows.value.length ? `Guardar ${readyRows.value.length} lectura${readyRows.value.length === 1 ? '' : 's'}` : invalidRows.value.length ? 'Corregí las lecturas marcadas' : 'Ingresá al menos una lectura')
const equipmentFor = (equipmentId) => props.data.equipment.items.find((item) => item.id === equipmentId)
const rowStatusLabel = (equipment) => rowHasError(equipment) ? 'Revisar' : ({ pending: '', saving: 'Guardando…', saved: 'Guardada', error: 'Error' }[rows[equipment.id].status] ?? '')
const rowStatusClass = (equipment) => rowHasError(equipment) || rows[equipment.id].status === 'error' ? 'text-danger-strong' : rows[equipment.id].status === 'saved' ? 'text-success-strong' : rows[equipment.id].status === 'saving' ? 'text-primary' : 'text-ink-muted'
const formatDelta = (delta, unit) => {
  if (delta === null || delta === undefined || delta === 0) return null
  const formatted = unit === 'km' ? Math.round(Math.abs(delta)).toLocaleString('es-AR') : Math.abs(delta).toLocaleString('es-AR', { minimumFractionDigits: 1, maximumFractionDigits: 1 })
  return `${delta > 0 ? '+' : '-'}${formatted} ${unit}`
}
const inputDelta = (equipment, key) => formatDelta(key === 'kilometers' ? kilometersDelta(rows[equipment.id].currentKm, rows[equipment.id].kilometers) : readingDelta(rows[equipment.id].currentHours, rows[equipment.id].hours), key === 'kilometers' ? 'km' : 'h')
const currentReadingText = (equipment) => {
  const values = []
  if (equipment.controlsKm) values.push(formatKilometers(rows[equipment.id].currentKm))
  if (equipment.controlsHours) values.push(formatHours(rows[equipment.id].currentHours))
  return values.join(' · ') || 'Sin contador configurado'
}
const primaryPlan = (equipment) => maintenance[equipment.id]?.primaryPlan || null
const formatPlanPoint = (plan, prefix) => {
  if (!plan) return '—'
  const values = []
  const km = plan[`${prefix}Km`]
  const hours = plan[`${prefix}Hours`]
  const date = plan[`${prefix}Date`]
  if (km !== null && km !== undefined) values.push(Number(km).toLocaleString('es-AR') + ' km')
  if (hours !== null && hours !== undefined) values.push(Number(hours).toLocaleString('es-AR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + ' h')
  if (date) values.push(date.split('-').reverse().join('/'))
  return values.join(' · ') || 'Sin base'
}
const preventiveLabel = (equipment) => {
  const snapshot = maintenance[equipment.id] || { state: 'SIN_PLAN' }
  const critical = snapshot.primaryPlan?.critical
  if (snapshot.state === 'SIN_PLAN') return 'Sin plan'
  if (snapshot.state === 'PROBLEMA') return 'Faltan datos'
  if (!critical) return snapshot.state === 'OK' ? 'Al día' : snapshot.state
  const raw = Math.abs(Number(critical.value))
  const value = critical.unit === 'km' ? Math.round(raw).toLocaleString('es-AR') : raw.toLocaleString('es-AR', { minimumFractionDigits: critical.unit === 'h' ? 1 : 0, maximumFractionDigits: 1 })
  return snapshot.state === 'VENCIDO' ? `Vencido ${value} ${critical.unit}` : `Faltan ${value} ${critical.unit}`
}
const preventiveClass = (equipment) => ({ OK: 'border-success/30 bg-success-subtle/50 text-success-strong', PROXIMO: 'border-warning/40 bg-warning-subtle/60 text-warning-strong', VENCIDO: 'border-danger/30 bg-danger-subtle/60 text-danger-strong', PROBLEMA: 'border-danger/30 bg-danger-subtle/40 text-danger-strong', SIN_PLAN: 'border-border bg-surface-muted text-ink-muted' }[maintenance[equipment.id]?.state || 'SIN_PLAN'])
const workOrderPrintUrl = (orderId) => `${props.data.routes.workOrderBase}/${orderId}/imprimir`
const applyCommonRecordedAt = () => props.data.equipment.items.forEach(({ id }) => { rows[id].recordedAt = commonRecordedAt.value })
const refreshTimestamp = () => { commonRecordedAt.value = nowLocal(); applyCommonRecordedAt() }
const navigateFilters = () => {
  const url = new URL(props.data.routes.index, window.location.origin)
  if (filters.q.trim()) url.searchParams.set('q', filters.q.trim())
  if (filters.branchId) url.searchParams.set('sucursal_id', filters.branchId)
  if (filters.typeId) url.searchParams.set('tipo_id', filters.typeId)
  url.searchParams.set('per_page', String(props.data.filters.perPage || 25))
  window.location.assign(url.toString())
}
const scheduleSearch = () => { window.clearTimeout(filterTimer); filterTimer = window.setTimeout(navigateFilters, 450) }
const applyCatalogFilter = () => { window.clearTimeout(filterTimer); navigateFilters() }
onBeforeUnmount(() => window.clearTimeout(filterTimer))

const responsePayload = async (response) => {
  const contentType = response.headers?.get?.('content-type') || ''
  const body = await response.text()
  let payload = null
  if (body.trim() && (contentType.includes('json') || body.trim().startsWith('{'))) { try { payload = JSON.parse(body) } catch { payload = null } }
  if (payload) return { payload, error: null, kind: response.status >= 500 ? 'server' : response.status === 401 || response.status === 403 ? 'session' : response.status >= 400 ? 'validation' : null }
  if (response.status >= 500) return { payload: null, error: 'El servidor no pudo completar la operación. Intentá nuevamente.', kind: 'server' }
  if (response.url?.includes('/login') || contentType.includes('text/html')) return { payload: null, error: 'La sesión pudo haber vencido. Volvé a iniciar sesión.', kind: 'session' }
  return { payload: null, error: !response.ok ? `La validación del servidor rechazó la operación (HTTP ${response.status}).` : 'El servidor devolvió una respuesta inesperada.', kind: 'validation' }
}
const resultError = (equipment, message, kind = 'network') => ({ rowNumber: results.value.length + 1, equipmentId: equipment.id, success: false, message, errorKind: kind, plansEvaluated: 0, overduePlans: 0 })
const submitRows = async () => {
  if (!readyRows.value.length || saving.value) return
  const batch = [...readyRows.value]
  totalToSave.value = batch.length; currentSavingIndex.value = 0; saving.value = true; results.value = []
  for (const equipment of batch) {
    currentSavingIndex.value += 1
    const values = rows[equipment.id]
    const previous = { kilometers: values.currentKm, hours: values.currentHours }
    const submitted = { kilometers: values.kilometers !== '', hours: values.hours !== '' }
    values.status = 'saving'; values.message = ''
    const body = new FormData()
    body.append(csrf.name, csrf.hash); body.append('equipmentId', String(equipment.id)); body.append('kilometers', values.kilometers); body.append('hours', normalizeDecimalInput(values.hours)); body.append('recordedAt', values.recordedAt); body.append('notes', '')
    try {
      const parsed = await responsePayload(await fetch(props.data.routes.submitRow, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } }))
      if (parsed.payload?.csrf) Object.assign(csrf, parsed.payload.csrf)
      if (!parsed.payload?.result) { const error = resultError(equipment, parsed.payload?.error || parsed.error || 'No se pudo guardar la fila.', parsed.kind || 'validation'); results.value.push(error); values.status = 'error'; values.message = error.message; continue }
      const result = parsed.payload.result
      result.submittedKilometers = submitted.kilometers; result.submittedHours = submitted.hours; result.previousKilometers = previous.kilometers; result.previousHours = previous.hours
      result.kilometersDelta = submitted.kilometers ? kilometersDelta(previous.kilometers, result.currentKilometers) : null; result.hoursDelta = submitted.hours ? readingDelta(previous.hours, result.currentHours) : null
      results.value.push(result); values.status = result.success ? 'saved' : 'error'; values.message = result.message || (result.success ? 'Lectura guardada.' : 'No se pudo guardar la lectura.')
      if (result.success) { values.currentKm = result.currentKilometers; values.currentHours = result.currentHours; values.kilometers = ''; values.hours = ''; if (parsed.payload?.maintenance) maintenance[equipment.id] = parsed.payload.maintenance }
    } catch { const error = resultError(equipment, 'No se pudo conectar con el servidor. Revisá tu conexión e intentá nuevamente.'); values.status = 'error'; values.message = error.message; results.value.push(error) }
  }
  saving.value = false
}
const generateOrder = async (equipment) => {
  const plan = primaryPlan(equipment)
  if (!plan?.noticeId || orderSaving[equipment.id]) return
  orderSaving[equipment.id] = true; orderErrors[equipment.id] = ''
  const body = new FormData(); body.append(csrf.name, csrf.hash); body.append('equipmentId', String(equipment.id))
  try {
    const parsed = await responsePayload(await fetch(`${props.data.routes.generateOrderBase}/${plan.noticeId}/orden`, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } }))
    if (parsed.payload?.csrf) Object.assign(csrf, parsed.payload.csrf)
    if (!parsed.payload?.orderId) { orderErrors[equipment.id] = parsed.payload?.error || parsed.error || 'No se pudo generar la OT.'; return }
    if (parsed.payload.maintenance) maintenance[equipment.id] = parsed.payload.maintenance
  } catch { orderErrors[equipment.id] = 'No se pudo conectar con el servidor para generar la OT.' } finally { orderSaving[equipment.id] = false }
}
const focusNextReadingInput = (event) => {
  const form = event.target?.form
  if (!form) return
  const inputs = [...form.querySelectorAll('[data-reading-input="true"]')].filter((input) => !input.disabled && input.offsetParent !== null)
  const next = inputs[inputs.indexOf(event.target) + 1]
  if (next) { next.focus(); next.select?.() }
}
</script>

<template>
  <PageHeading title="Lecturas rápidas" eyebrow="Carga y mantenimiento preventivo" description="Cargá la flota con Enter, guardá y resolvé los vencimientos desde la misma pantalla." />
  <form method="post" :action="data.routes.submit" @submit.prevent="submitRows">
    <CsrfInput :csrf="data.csrf" />
    <PanelCard title="Equipos activos" :count="data.equipment.total">
      <div class="mb-3 grid gap-3 border-b border-border-subtle pb-3 xl:grid-cols-[minmax(18rem,1fr)_13rem_13rem_auto] xl:items-end">
        <label class="block text-sm font-semibold text-ink">Buscar móvil<span class="relative mt-1 block"><MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-1/2 size-5 -translate-y-1/2 text-ink-subtle" aria-hidden="true" /><input v-model="filters.q" type="search" autocomplete="off" placeholder="Código, patente o chasis…" :class="`${fieldClass} pl-10`" @input="scheduleSearch" /></span></label>
        <label class="block text-sm font-semibold text-ink">Sucursal<select v-model="filters.branchId" :class="`${fieldClass} mt-1`" @change="applyCatalogFilter"><option value="">Todas</option><option v-for="branch in data.catalogs.branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option></select></label>
        <label class="block text-sm font-semibold text-ink">Tipo<select v-model="filters.typeId" :class="`${fieldClass} mt-1`" @change="applyCatalogFilter"><option value="">Todos</option><option v-for="type in data.catalogs.types" :key="type.id" :value="type.id">{{ type.name }}</option></select></label>
        <div class="flex items-end gap-2 xl:justify-end"><label class="min-w-0 flex-1 text-sm font-semibold text-ink xl:w-52 xl:flex-none">Fecha y hora<input v-model="commonRecordedAt" type="datetime-local" :class="`${fieldClass} mt-1`" @change="applyCommonRecordedAt" /></label><button type="button" :class="`${secondaryButton} shrink-0`" @click="refreshTimestamp">Ahora</button></div>
      </div>
      <div class="mb-3 flex flex-wrap items-center gap-2 text-xs font-semibold">
        <button type="button" class="rounded-full border px-3 py-1.5" :class="statusFilter === '' ? 'border-primary bg-primary-subtle text-primary' : 'border-border text-ink-muted'" @click="statusFilter = ''">Todos {{ data.equipment.items.length }}</button>
        <button type="button" class="rounded-full border px-3 py-1.5" :class="statusFilter === 'VENCIDO' ? 'border-danger bg-danger-subtle text-danger-strong' : 'border-border text-ink-muted'" @click="statusFilter = 'VENCIDO'">Vencidos {{ maintenanceCounts.VENCIDO }}</button>
        <button type="button" class="rounded-full border px-3 py-1.5" :class="statusFilter === 'PROXIMO' ? 'border-warning bg-warning-subtle text-warning-strong' : 'border-border text-ink-muted'" @click="statusFilter = 'PROXIMO'">Próximos {{ maintenanceCounts.PROXIMO }}</button>
        <button type="button" class="rounded-full border px-3 py-1.5" :class="statusFilter === 'PROBLEMA' ? 'border-danger bg-danger-subtle text-danger-strong' : 'border-border text-ink-muted'" @click="statusFilter = 'PROBLEMA'">Problemas {{ maintenanceCounts.PROBLEMA }}</button>
        <button type="button" class="rounded-full border px-3 py-1.5" :class="statusFilter === 'SIN_PLAN' ? 'border-border bg-surface-muted text-ink' : 'border-border text-ink-muted'" @click="statusFilter = 'SIN_PLAN'">Sin plan {{ maintenanceCounts.SIN_PLAN }}</button>
      </div>
      <div v-if="results.length" class="mb-3 flex flex-wrap gap-2 text-xs" aria-live="polite"><span class="font-semibold text-ink-muted">Última carga:</span><span v-for="row in results" :key="`${row.rowNumber}-${row.equipmentId}`" class="inline-flex items-center gap-1 rounded-full border border-border px-2 py-1" :class="row.success ? 'text-success-strong' : 'text-danger-strong'"><CheckCircleIcon v-if="row.success" class="size-4" aria-hidden="true" /><ExclamationTriangleIcon v-else class="size-4" aria-hidden="true" />{{ equipmentFor(row.equipmentId)?.code || 'Equipo' }}</span></div>
      <EmptyState v-if="data.equipment.items.length === 0" title="No hay equipos para los filtros seleccionados" /><EmptyState v-else-if="visibleEquipment.length === 0" title="No hay coincidencias en esta página" />
      <div v-else class="overflow-x-auto rounded-xl border border-border">
        <table class="w-full min-w-[1380px] border-collapse text-sm">
          <thead class="bg-surface-muted text-left text-xs font-bold uppercase tracking-wide text-ink-muted"><tr><th class="sticky left-0 z-10 w-48 bg-surface-muted px-3 py-2.5">Equipo</th><th class="w-40 px-3 py-2.5">Última lectura</th><th class="w-60 px-3 py-2.5">Nueva lectura</th><th class="w-48 px-3 py-2.5">Último service</th><th class="w-48 px-3 py-2.5">Próximo service</th><th class="w-44 px-3 py-2.5">Estado</th><th class="w-40 px-3 py-2.5">Acción</th></tr></thead>
          <tbody class="divide-y divide-border-subtle bg-white">
            <tr v-for="equipment in visibleEquipment" :key="equipment.id" class="align-middle hover:bg-surface-muted/50">
              <td class="sticky left-0 z-[1] bg-white px-3 py-2.5"><div class="font-bold text-primary">{{ equipment.code }}</div><div class="text-xs text-ink-muted">{{ equipment.plate || 'Sin patente' }}</div><div class="truncate text-[11px] text-ink-subtle">{{ equipment.typeName }} · {{ equipment.branchName }}</div></td>
              <td class="px-3 py-2.5"><div class="font-semibold tabular-nums text-ink">{{ currentReadingText(equipment) }}</div><div class="mt-0.5 text-[11px] text-ink-subtle">{{ equipment.lastReadingAt || 'Sin lectura previa' }}</div></td>
              <td class="px-3 py-2"><div class="flex gap-2"><div v-if="equipment.controlsKm" class="min-w-0 flex-1"><div class="relative"><input :id="`quick-${equipment.id}-km`" v-model="rows[equipment.id].kilometers" data-reading-input="true" type="text" inputmode="numeric" autocomplete="off" placeholder="Kilómetros" :disabled="!data.canRegister || saving" :class="`${fieldClass} pr-9 font-semibold tabular-nums ${kilometerError(equipment) ? 'border-danger focus:border-danger focus:ring-danger/15' : ''}`" @keydown.enter.prevent="focusNextReadingInput" /><span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-xs font-bold text-ink-muted">km</span></div><p v-if="kilometerError(equipment)" class="mt-1 text-xs font-medium text-danger-strong">{{ kilometerError(equipment) }}</p><p v-else-if="inputDelta(equipment, 'kilometers')" class="mt-1 text-xs text-ink-muted">{{ inputDelta(equipment, 'kilometers') }}</p></div><div v-if="equipment.controlsHours" class="min-w-0 flex-1"><div class="relative"><input :id="`quick-${equipment.id}-hours`" v-model="rows[equipment.id].hours" data-reading-input="true" type="text" inputmode="decimal" autocomplete="off" placeholder="Horómetro" :disabled="!data.canRegister || saving" :class="`${fieldClass} pr-7 font-semibold tabular-nums ${hoursError(equipment) ? 'border-danger focus:border-danger focus:ring-danger/15' : ''}`" @keydown.enter.prevent="focusNextReadingInput" /><span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-xs font-bold text-ink-muted">h</span></div><p v-if="hoursError(equipment)" class="mt-1 text-xs font-medium text-danger-strong">{{ hoursError(equipment) }}</p><p v-else-if="inputDelta(equipment, 'hours')" class="mt-1 text-xs text-ink-muted">{{ inputDelta(equipment, 'hours') }}</p></div></div><p v-if="rowStatusLabel(equipment)" class="mt-1 text-[11px] font-semibold" :class="rowStatusClass(equipment)">{{ rowStatusLabel(equipment) }}<span v-if="rows[equipment.id].message"> · {{ rows[equipment.id].message }}</span></p></td>
              <td class="px-3 py-2.5"><template v-if="primaryPlan(equipment)"><div class="font-medium text-ink">{{ primaryPlan(equipment).serviceName }}</div><div class="mt-0.5 text-xs tabular-nums text-ink-muted">{{ formatPlanPoint(primaryPlan(equipment), 'base') }}</div></template><span v-else class="text-ink-muted">—</span></td>
              <td class="px-3 py-2.5"><template v-if="primaryPlan(equipment)"><div class="font-medium text-ink">{{ primaryPlan(equipment).serviceName }}</div><div class="mt-0.5 text-xs tabular-nums text-ink-muted">{{ formatPlanPoint(primaryPlan(equipment), 'next') }}</div></template><span v-else class="text-ink-muted">—</span></td>
              <td class="px-3 py-2.5"><span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-bold" :class="preventiveClass(equipment)">{{ preventiveLabel(equipment) }}</span><p v-if="maintenance[equipment.id]?.planCount > 1" class="mt-1 text-[11px] text-ink-subtle">+{{ maintenance[equipment.id].planCount - 1 }} plan{{ maintenance[equipment.id].planCount - 1 === 1 ? '' : 'es' }}</p></td>
              <td class="px-3 py-2.5"><template v-if="primaryPlan(equipment)?.order"><div class="text-xs font-bold text-success-strong">{{ primaryPlan(equipment).order.number }}</div><a :href="workOrderPrintUrl(primaryPlan(equipment).order.id)" target="_blank" rel="noopener" :class="`${secondaryButton} mt-1 px-2.5 py-1.5 text-xs`"><PrinterIcon class="mr-1 size-4" aria-hidden="true" />Imprimir</a></template><button v-else-if="data.canGenerateOrder && primaryPlan(equipment)?.noticeId" type="button" :disabled="orderSaving[equipment.id]" :class="`${primaryButton} px-2.5 py-1.5 text-xs`" @click="generateOrder(equipment)"><ArrowPathIcon v-if="orderSaving[equipment.id]" class="mr-1 size-4 animate-spin" aria-hidden="true" /><WrenchScrewdriverIcon v-else class="mr-1 size-4" aria-hidden="true" />{{ orderSaving[equipment.id] ? 'Generando…' : 'Generar OT' }}</button><span v-else-if="maintenance[equipment.id]?.state === 'PROXIMO'" class="text-xs font-semibold text-warning-strong">Preparar service</span><span v-else class="text-xs text-ink-subtle">—</span><p v-if="orderErrors[equipment.id]" class="mt-1 text-xs font-medium text-danger-strong">{{ orderErrors[equipment.id] }}</p></td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="invalidRows.length" class="mt-3 text-sm font-medium text-danger-strong" aria-live="polite">{{ invalidRows.length }} lectura{{ invalidRows.length === 1 ? '' : 's' }} con datos para revisar. Las válidas se pueden guardar igualmente.</div>
      <template #footer><div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"><PaginationBar :pagination="data.equipment.pagination" /><div class="flex flex-col items-stretch gap-2 sm:items-end"><span class="text-xs text-ink-muted">{{ enteredRows.length }} cargada{{ enteredRows.length === 1 ? '' : 's' }} · {{ readyRows.length }} lista{{ readyRows.length === 1 ? '' : 's' }} para guardar</span><button v-if="data.canRegister && data.equipment.items.length" type="submit" :disabled="saving || readyRows.length === 0" :class="primaryButton"><ArrowPathIcon class="mr-2 size-5" :class="saving ? 'animate-spin' : ''" aria-hidden="true" />{{ saveButtonLabel }}</button></div></div></template>
    </PanelCard>
  </form>
</template>
