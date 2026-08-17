<script setup>
import { computed, reactive, ref } from 'vue'
import { ArrowPathIcon, CheckCircleIcon, ExclamationTriangleIcon, MagnifyingGlassIcon } from '@heroicons/vue/24/outline'
import CsrfInput from './components/CsrfInput.vue'
import EmptyState from './components/EmptyState.vue'
import PageHeading from './components/PageHeading.vue'
import PaginationBar from './components/PaginationBar.vue'
import { fieldClass, formatHours, formatKilometers, kilometersDelta, normalizeDecimalInput, nowLocal, parseFlexibleNumber, parseKilometers, primaryButton, readingDelta, secondaryButton } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })
const results = ref([...props.data.results])
const saving = ref(false)
const totalToSave = ref(0)
const currentSavingIndex = ref(0)
const commonRecordedAt = ref(props.data.recordedAtDefault || nowLocal())
const searchQuery = ref(props.data.filters.q || '')
const rows = reactive(Object.fromEntries(props.data.equipment.items.map((equipment) => [equipment.id, {
  kilometers: '', hours: '', recordedAt: commonRecordedAt.value, notes: '', currentKm: equipment.currentKm,
  currentHours: equipment.currentHours, status: 'pending', dateCustomized: false,
}])))
const csrf = reactive({ ...props.data.csrf })

const normalizeText = (value) => String(value ?? '').trim().toLowerCase()
const readingKey = (equipment) => equipment.controlsHours && !equipment.controlsKm ? 'hours' : 'kilometers'
const readingUnit = (equipment) => readingKey(equipment) === 'hours' ? 'h' : 'km'
const currentReading = (equipment) => readingKey(equipment) === 'hours'
  ? formatHours(rows[equipment.id].currentHours)
  : formatKilometers(rows[equipment.id].currentKm)
const rowValue = (equipment) => rows[equipment.id][readingKey(equipment)]
const setRowValue = (equipment, value) => { rows[equipment.id][readingKey(equipment)] = value }
const rowInputName = (equipment) => `readings[${equipment.id}][${readingKey(equipment)}]`
const rowInputId = (equipment) => `quick-${equipment.id}-${readingKey(equipment)}`
const rowSearchText = (equipment) => [equipment.code, equipment.plate, equipment.chassis, equipment.typeName, equipment.branchName].map(normalizeText).join(' ')

const visibleEquipment = computed(() => {
  const query = normalizeText(searchQuery.value)
  if (!query) return props.data.equipment.items
  return props.data.equipment.items.filter((equipment) => rowSearchText(equipment).includes(query))
})
const readyRows = computed(() => props.data.equipment.items.filter(({ id }) => rows[id].kilometers !== '' || rows[id].hours !== ''))
const saveButtonLabel = computed(() => {
  if (saving.value) return `Guardando ${currentSavingIndex.value} de ${totalToSave.value}…`
  if (readyRows.value.length) return `Guardar ${readyRows.value.length} lectura${readyRows.value.length === 1 ? '' : 's'}`
  return 'Sin lecturas pendientes'
})
const pendingLabel = computed(() => `${readyRows.value.length} lectura${readyRows.value.length === 1 ? '' : 's'} pendiente${readyRows.value.length === 1 ? '' : 's'}`)
const statusLabel = (status) => ({ pending: '', saving: 'Guardando…', saved: 'Guardada', error: 'Error' }[status] ?? '')
const equipmentFor = (equipmentId) => props.data.equipment.items.find((item) => item.id === equipmentId)
const formatDelta = (delta, unit) => {
  if (delta === null || delta === undefined) return null
  if (delta === 0) return 'Sin variación'
  const formatted = unit === 'km'
    ? Math.round(Math.abs(delta)).toLocaleString('es-AR')
    : Math.abs(delta).toLocaleString('es-AR', { minimumFractionDigits: 1, maximumFractionDigits: 1 })
  return `${delta > 0 ? '+' : '-'}${formatted} ${unit}`
}
const successFeedback = (row) => {
  const values = []
  if (row.submittedHours === true && row.currentHours !== null && row.currentHours !== undefined) values.push(`Horómetro actualizado a ${formatHours(row.currentHours)}`)
  if (row.submittedKilometers === true && row.currentKilometers !== null && row.currentKilometers !== undefined) values.push(`Kilometraje actualizado a ${formatKilometers(row.currentKilometers)}`)
  return values.join(' · ') || row.message || 'Lectura guardada.'
}
const deltaFeedback = (row) => {
  const values = []
  if (row.submittedHours === true && row.hoursDelta !== null && row.hoursDelta !== undefined) values.push(`${formatDelta(row.hoursDelta, 'h')} desde la lectura anterior`)
  if (row.submittedKilometers === true && row.kilometersDelta !== null && row.kilometersDelta !== undefined) values.push(`${formatDelta(row.kilometersDelta, 'km')} desde la lectura anterior`)
  return values.join(' · ')
}
const overdueFeedback = (row) => {
  if (!row.overduePlans) return ''
  return `${row.overduePlans} mantenimiento${row.overduePlans === 1 ? '' : 's'} quedó${row.overduePlans === 1 ? '' : 'aron'} vencido${row.overduePlans === 1 ? '' : 's'}.`
}
const parsedRowValue = (equipment) => readingKey(equipment) === 'hours' ? parseFlexibleNumber(rowValue(equipment)) : parseKilometers(rowValue(equipment))
const currentNumeric = (equipment) => readingKey(equipment) === 'hours' ? parseFlexibleNumber(rows[equipment.id].currentHours) : parseKilometers(rows[equipment.id].currentKm)
const rowError = (equipment) => {
  const value = rowValue(equipment)
  if (value === '') return ''
  const parsed = parsedRowValue(equipment)
  if (parsed === null || parsed < 0) return readingKey(equipment) === 'hours' ? 'Ingresá un horómetro válido.' : 'Ingresá un kilometraje entero válido.'
  const current = currentNumeric(equipment)
  if (current !== null && parsed < current) return `No puede ser menor a ${currentReading(equipment)}.`
  return ''
}
const rowDelta = (equipment) => {
  if (rowError(equipment) || rowValue(equipment) === '') return ''
  const current = currentNumeric(equipment)
  const next = parsedRowValue(equipment)
  if (current === null || next === null) return ''
  return formatDelta(next - current, readingUnit(equipment))
}
const canSubmit = computed(() => readyRows.value.length > 0 && readyRows.value.every((equipment) => !rowError(equipment)))
const applyCommonRecordedAt = () => {
  props.data.equipment.items.forEach(({ id }) => {
    if (!rows[id].dateCustomized) rows[id].recordedAt = commonRecordedAt.value
  })
}
const refreshTimestamp = () => {
  commonRecordedAt.value = nowLocal()
  applyCommonRecordedAt()
}
const responsePayload = async (response) => {
  const contentType = response.headers?.get?.('content-type') || ''
  const body = await response.text()
  let payload = null
  if (body.trim() && (contentType.includes('json') || body.trim().startsWith('{'))) {
    try { payload = JSON.parse(body) } catch { payload = null }
  }
  if (payload) {
    const kind = response.status >= 500 ? 'server' : response.status === 401 || response.status === 403 ? 'session' : response.status >= 400 ? 'validation' : null
    return { payload, error: null, kind }
  }
  if (response.status >= 500) return { payload: null, error: 'El servidor no pudo guardar la lectura. Intentá nuevamente.', kind: 'server' }
  if (response.url?.includes('/login') || contentType.includes('text/html')) return { payload: null, error: 'La sesión pudo haber vencido o el servidor devolvió una página inesperada. Volvé a iniciar sesión.', kind: 'session' }
  if (!response.ok) return { payload: null, error: `La validación del servidor rechazó esta lectura (HTTP ${response.status}).`, kind: 'validation' }
  return { payload: null, error: 'El servidor devolvió una respuesta inesperada.', kind: 'server' }
}
const resultError = (equipment, message, kind = 'network') => ({ rowNumber: results.value.length + 1, equipmentId: equipment.id, success: false, message, errorKind: kind, plansEvaluated: 0, overduePlans: 0 })
const submitRows = async () => {
  if (!canSubmit.value || saving.value) return
  const batch = [...readyRows.value]
  totalToSave.value = batch.length
  currentSavingIndex.value = 0
  saving.value = true
  results.value = []
  for (const equipment of batch) {
    currentSavingIndex.value += 1
    const values = rows[equipment.id]
    const previous = { kilometers: values.currentKm, hours: values.currentHours }
    const submitted = { kilometers: values.kilometers !== '', hours: values.hours !== '' }
    values.status = 'saving'
    const body = new FormData()
    body.append(csrf.name, csrf.hash)
    body.append('equipmentId', String(equipment.id))
    body.append('kilometers', values.kilometers)
    body.append('hours', normalizeDecimalInput(values.hours))
    body.append('recordedAt', values.recordedAt)
    body.append('notes', values.notes)
    try {
      const response = await fetch(props.data.routes.submitRow, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } })
      const parsed = await responsePayload(response)
      if (parsed.payload?.csrf) Object.assign(csrf, parsed.payload.csrf)
      if (!parsed.payload?.result) {
        const error = resultError(equipment, parsed.payload?.error || parsed.error || 'No se pudo guardar la fila.', parsed.kind || 'validation')
        results.value.push(error)
        values.status = 'error'
        continue
      }
      const result = parsed.payload.result
      result.submittedKilometers = submitted.kilometers
      result.submittedHours = submitted.hours
      result.previousKilometers = previous.kilometers
      result.previousHours = previous.hours
      result.kilometersDelta = submitted.kilometers ? kilometersDelta(previous.kilometers, result.currentKilometers) : null
      result.hoursDelta = submitted.hours ? readingDelta(previous.hours, result.currentHours) : null
      results.value.push(result)
      values.status = result.success ? 'saved' : 'error'
      if (result.success) {
        values.currentKm = result.currentKilometers
        values.currentHours = result.currentHours
        values.kilometers = ''
        values.hours = ''
        values.notes = ''
      }
    } catch {
      values.status = 'error'
      results.value.push(resultError(equipment, 'No se pudo conectar con el servidor. Revisá tu conexión e intentá nuevamente.'))
    }
  }
  saving.value = false
}
const focusNextReadingInput = (event) => {
  const inputs = [...document.querySelectorAll('[data-reading-input="true"]')].filter((input) => !input.disabled && input.offsetParent !== null)
  const next = inputs[inputs.indexOf(event.target) + 1]
  if (next) next.focus()
}
</script>

<template>
  <PageHeading title="Lecturas rápidas" eyebrow="Medición de uso" description="Cargá kilómetros u horas como en una planilla: buscá, escribí, Enter y seguí." />

  <section class="mb-4 rounded-xl border border-border-subtle bg-white p-3 shadow-sm">
    <form method="get" :action="data.routes.index" class="grid gap-3 md:grid-cols-[minmax(260px,1fr)_220px_220px]">
      <label class="relative block">
        <span class="sr-only">Buscar equipo, patente o chasis</span>
        <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-ink-subtle" aria-hidden="true" />
        <input v-model="searchQuery" type="search" autocomplete="off" placeholder="Buscar equipo, patente o chasis..." :class="fieldClass + ' pl-9'" />
      </label>
      <label class="sr-only" for="quick-branch">Sucursal</label>
      <select id="quick-branch" name="sucursal_id" :value="data.filters.branchId" :class="fieldClass" @change="$event.target.form.requestSubmit()"><option value="">Todas las sucursales</option><option v-for="branch in data.catalogs.branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option></select>
      <label class="sr-only" for="quick-type">Tipo de equipo</label>
      <select id="quick-type" name="tipo_id" :value="data.filters.typeId" :class="fieldClass" @change="$event.target.form.requestSubmit()"><option value="">Todos los tipos</option><option v-for="type in data.catalogs.types" :key="type.id" :value="type.id">{{ type.name }}</option></select>
    </form>
  </section>

  <section class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border-subtle bg-surface-muted px-3 py-2 text-sm">
    <div class="flex flex-wrap items-center gap-3">
      <label class="font-semibold text-ink">Fecha de lectura <input v-model="commonRecordedAt" type="datetime-local" :class="fieldClass + ' ml-2 w-auto py-1.5'" @change="applyCommonRecordedAt" /></label>
      <button type="button" :class="secondaryButton" @click="refreshTimestamp">Usar hora actual</button>
    </div>
    <div class="font-semibold" :class="readyRows.length ? 'text-primary' : 'text-ink-muted'">{{ pendingLabel }}</div>
  </section>

  <section v-if="results.length" class="mb-4 overflow-hidden rounded-xl border border-border-subtle bg-white" aria-live="polite">
    <div class="border-b border-border-subtle px-3 py-2 text-sm font-bold text-ink">Resultado de la última carga</div>
    <ul class="divide-y divide-border-subtle">
      <li v-for="row in results" :key="`${row.rowNumber}-${row.equipmentId}`" class="flex gap-3 px-3 py-2 text-sm">
        <CheckCircleIcon v-if="row.success" class="size-5 shrink-0 text-success" aria-hidden="true" /><ExclamationTriangleIcon v-else class="size-5 shrink-0 text-danger" aria-hidden="true" />
        <span><strong>{{ equipmentFor(row.equipmentId)?.code || 'Equipo' }}:</strong><template v-if="row.success"><span> {{ successFeedback(row) }}</span><span v-if="deltaFeedback(row)" class="text-ink-muted"> · {{ deltaFeedback(row) }}</span><span v-if="overdueFeedback(row)" class="font-semibold text-warning-strong"> · {{ overdueFeedback(row) }}</span></template><span v-else> {{ row.message }}</span></span>
      </li>
    </ul>
  </section>

  <form method="post" :action="data.routes.submit" @submit.prevent="submitRows">
    <CsrfInput :csrf="data.csrf" />
    <div class="overflow-hidden rounded-xl border border-border-subtle bg-white shadow-sm">
      <div class="hidden grid-cols-[minmax(190px,1.4fr)_minmax(120px,.8fr)_minmax(130px,.8fr)_minmax(145px,.85fr)_minmax(180px,1fr)_110px] gap-3 border-b border-border-subtle bg-surface-muted px-3 py-2 text-xs font-bold uppercase tracking-wide text-ink-muted md:grid">
        <span>Equipo</span><span>Tipo</span><span>Sucursal</span><span>Última lectura</span><span>Nueva lectura</span><span>Estado</span>
      </div>

      <EmptyState v-if="data.equipment.items.length === 0" title="No hay equipos para los filtros seleccionados" />
      <EmptyState v-else-if="visibleEquipment.length === 0" title="No hay coincidencias" description="Probá con otro código, patente o chasis." />

      <div v-else class="divide-y divide-border-subtle">
        <div v-for="equipment in visibleEquipment" :key="equipment.id" class="grid gap-2 px-3 py-2.5 md:grid-cols-[minmax(190px,1.4fr)_minmax(120px,.8fr)_minmax(130px,.8fr)_minmax(145px,.85fr)_minmax(180px,1fr)_110px] md:items-center md:gap-3">
          <div class="min-w-0"><a :href="equipment.detailUrl" class="font-bold text-primary hover:underline">{{ equipment.code }}</a><span class="ml-2 text-xs text-ink-muted">{{ equipment.plate || 'Sin patente' }}</span><p v-if="equipment.chassis" class="truncate text-xs text-ink-subtle">{{ equipment.chassis }}</p></div>
          <div class="text-sm text-ink-muted"><span class="md:hidden font-semibold text-ink">Tipo: </span>{{ equipment.typeName }}</div>
          <div class="text-sm text-ink-muted"><span class="md:hidden font-semibold text-ink">Sucursal: </span>{{ equipment.branchName }}</div>
          <div class="text-sm font-semibold text-ink"><span class="md:hidden font-semibold">Última: </span>{{ currentReading(equipment) }}</div>
          <div>
            <label :for="rowInputId(equipment)" class="sr-only">Nueva lectura para {{ equipment.code }}</label>
            <div class="flex items-center gap-2">
              <input :id="rowInputId(equipment)" data-reading-input="true" :name="rowInputName(equipment)" type="text" :inputmode="readingKey(equipment) === 'hours' ? 'decimal' : 'numeric'" autocomplete="off" :value="rowValue(equipment)" placeholder="Ingresar lectura" :disabled="!data.canRegister || saving" :class="fieldClass" @input="setRowValue(equipment, $event.target.value)" @keydown.enter.prevent="focusNextReadingInput" />
              <span class="w-7 shrink-0 text-xs font-bold text-ink-muted">{{ readingUnit(equipment) }}</span>
            </div>
            <p v-if="rowError(equipment)" class="mt-1 text-xs font-semibold text-danger-strong">{{ rowError(equipment) }}</p>
            <p v-else-if="rowDelta(equipment)" class="mt-1 text-xs text-ink-muted">{{ rowDelta(equipment) }} desde la última</p>
          </div>
          <div class="text-xs font-semibold" :class="rows[equipment.id].status === 'error' ? 'text-danger-strong' : rows[equipment.id].status === 'saved' ? 'text-success-strong' : 'text-ink-muted'">{{ statusLabel(rows[equipment.id].status) }}</div>
        </div>
      </div>

      <div class="flex flex-col gap-3 border-t border-border-subtle bg-surface-muted px-3 py-3 sm:flex-row sm:items-center sm:justify-between">
        <PaginationBar :pagination="data.equipment.pagination" />
        <button v-if="data.canRegister && data.equipment.items.length" type="submit" :disabled="saving || !canSubmit" :class="primaryButton"><ArrowPathIcon class="mr-2 size-5" :class="saving ? 'animate-spin' : ''" aria-hidden="true" />{{ saveButtonLabel }}</button>
      </div>
    </div>
  </form>
</template>
