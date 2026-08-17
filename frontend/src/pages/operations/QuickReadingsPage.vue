<script setup>
import { computed, onBeforeUnmount, reactive, ref } from 'vue'
import { ArrowPathIcon, CheckCircleIcon, ExclamationTriangleIcon, MagnifyingGlassIcon } from '@heroicons/vue/24/outline'
import CsrfInput from './components/CsrfInput.vue'
import EmptyState from './components/EmptyState.vue'
import PageHeading from './components/PageHeading.vue'
import PaginationBar from './components/PaginationBar.vue'
import PanelCard from './components/PanelCard.vue'
import {
  fieldClass,
  formatHours,
  formatKilometers,
  kilometersDelta,
  normalizeDecimalInput,
  nowLocal,
  parseFlexibleNumber,
  parseKilometers,
  primaryButton,
  readingDelta,
  secondaryButton,
} from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })
const results = ref([...props.data.results])
const saving = ref(false)
const totalToSave = ref(0)
const currentSavingIndex = ref(0)
const commonRecordedAt = ref(props.data.recordedAtDefault || nowLocal())
const filters = reactive({
  q: props.data.filters.q || '',
  branchId: props.data.filters.branchId || '',
  typeId: props.data.filters.typeId || '',
})
const rows = reactive(Object.fromEntries(props.data.equipment.items.map((equipment) => [equipment.id, {
  kilometers: '',
  hours: '',
  recordedAt: commonRecordedAt.value,
  currentKm: equipment.currentKm,
  currentHours: equipment.currentHours,
  status: 'pending',
  message: '',
}])))
const csrf = reactive({ ...props.data.csrf })
let filterTimer = null

const enteredRows = computed(() => props.data.equipment.items.filter(({ id }) => rows[id].kilometers !== '' || rows[id].hours !== ''))
const kilometerError = (equipment) => {
  const value = rows[equipment.id].kilometers
  if (value === '') return null
  const next = parseKilometers(value)
  if (next === null) return 'Ingresá kilómetros enteros, sin puntos ni comas.'
  const current = parseKilometers(rows[equipment.id].currentKm)
  if (current !== null && next < current) return `No puede ser menor a ${formatKilometers(current)}.`
  return null
}
const hoursError = (equipment) => {
  const value = rows[equipment.id].hours
  if (value === '') return null
  const next = parseFlexibleNumber(value)
  if (next === null) return 'Ingresá horas con un decimal como máximo.'
  const current = parseFlexibleNumber(rows[equipment.id].currentHours)
  if (current !== null && next < current) return `No puede ser menor a ${formatHours(current)}.`
  return null
}
const rowHasError = (equipment) => Boolean(kilometerError(equipment) || hoursError(equipment))
const readyRows = computed(() => enteredRows.value.filter((equipment) => !rowHasError(equipment)))
const invalidRows = computed(() => enteredRows.value.filter((equipment) => rowHasError(equipment)))
const visibleEquipment = computed(() => {
  const query = filters.q.trim().toLocaleLowerCase('es')
  if (!query) return props.data.equipment.items
  return props.data.equipment.items.filter((equipment) => [equipment.code, equipment.plate, equipment.typeName, equipment.branchName]
    .some((value) => String(value ?? '').toLocaleLowerCase('es').includes(query)))
})
const saveButtonLabel = computed(() => {
  if (saving.value) return `Guardando ${currentSavingIndex.value} de ${totalToSave.value}…`
  if (readyRows.value.length) return `Guardar ${readyRows.value.length} lectura${readyRows.value.length === 1 ? '' : 's'}`
  return invalidRows.value.length ? 'Corregí las lecturas marcadas' : 'Ingresá al menos una lectura'
})
const equipmentFor = (equipmentId) => props.data.equipment.items.find((item) => item.id === equipmentId)
const rowStatusLabel = (equipment) => {
  if (rowHasError(equipment)) return 'Revisar'
  return ({ pending: 'Sin cambios', saving: 'Guardando…', saved: 'Guardada', error: 'Error' }[rows[equipment.id].status] ?? 'Sin cambios')
}
const rowStatusClass = (equipment) => {
  if (rowHasError(equipment) || rows[equipment.id].status === 'error') return 'text-danger-strong'
  if (rows[equipment.id].status === 'saved') return 'text-success-strong'
  if (rows[equipment.id].status === 'saving') return 'text-primary'
  return enteredRows.value.some(({ id }) => id === equipment.id) ? 'text-warning-strong' : 'text-ink-muted'
}
const formatDelta = (delta, unit) => {
  if (delta === null || delta === undefined || delta === 0) return null
  const formatted = unit === 'km'
    ? Math.round(Math.abs(delta)).toLocaleString('es-AR')
    : Math.abs(delta).toLocaleString('es-AR', { minimumFractionDigits: 1, maximumFractionDigits: 1 })
  return `${delta > 0 ? '+' : '-'}${formatted} ${unit}`
}
const inputDelta = (equipment, key) => {
  const values = rows[equipment.id]
  const delta = key === 'kilometers'
    ? kilometersDelta(values.currentKm, values.kilometers)
    : readingDelta(values.currentHours, values.hours)
  return formatDelta(delta, key === 'kilometers' ? 'km' : 'h')
}
const currentReadingText = (equipment) => {
  const values = []
  if (equipment.controlsKm) values.push(formatKilometers(rows[equipment.id].currentKm))
  if (equipment.controlsHours) values.push(formatHours(rows[equipment.id].currentHours))
  return values.join(' · ') || 'Sin contador configurado'
}
const applyCommonRecordedAt = () => {
  props.data.equipment.items.forEach(({ id }) => { rows[id].recordedAt = commonRecordedAt.value })
}
const refreshTimestamp = () => {
  commonRecordedAt.value = nowLocal()
  applyCommonRecordedAt()
}
const navigateFilters = () => {
  const url = new URL(props.data.routes.index, window.location.origin)
  if (filters.q.trim()) url.searchParams.set('q', filters.q.trim())
  if (filters.branchId) url.searchParams.set('sucursal_id', filters.branchId)
  if (filters.typeId) url.searchParams.set('tipo_id', filters.typeId)
  url.searchParams.set('per_page', String(props.data.filters.perPage || 25))
  window.location.assign(url.toString())
}
const scheduleSearch = () => {
  window.clearTimeout(filterTimer)
  filterTimer = window.setTimeout(navigateFilters, 450)
}
const applyCatalogFilter = () => {
  window.clearTimeout(filterTimer)
  navigateFilters()
}
onBeforeUnmount(() => window.clearTimeout(filterTimer))

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
  if (response.url?.includes('/login') || contentType.includes('text/html')) {
    return { payload: null, error: 'La sesión pudo haber vencido. Volvé a iniciar sesión.', kind: 'session' }
  }
  if (!response.ok) return { payload: null, error: `La validación del servidor rechazó esta lectura (HTTP ${response.status}).`, kind: 'validation' }
  return { payload: null, error: 'El servidor devolvió una respuesta inesperada.', kind: 'server' }
}
const resultError = (equipment, message, kind = 'network') => ({
  rowNumber: results.value.length + 1,
  equipmentId: equipment.id,
  success: false,
  message,
  errorKind: kind,
  plansEvaluated: 0,
  overduePlans: 0,
})
const submitRows = async () => {
  if (!readyRows.value.length || saving.value) return
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
    values.message = ''
    const body = new FormData()
    body.append(csrf.name, csrf.hash)
    body.append('equipmentId', String(equipment.id))
    body.append('kilometers', values.kilometers)
    body.append('hours', normalizeDecimalInput(values.hours))
    body.append('recordedAt', values.recordedAt)
    body.append('notes', '')
    try {
      const response = await fetch(props.data.routes.submitRow, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } })
      const parsed = await responsePayload(response)
      if (parsed.payload?.csrf) Object.assign(csrf, parsed.payload.csrf)
      if (!parsed.payload?.result) {
        const error = resultError(equipment, parsed.payload?.error || parsed.error || 'No se pudo guardar la fila.', parsed.kind || 'validation')
        results.value.push(error)
        values.status = 'error'
        values.message = error.message
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
      values.message = result.message || (result.success ? 'Lectura guardada.' : 'No se pudo guardar la lectura.')
      if (result.success) {
        values.currentKm = result.currentKilometers
        values.currentHours = result.currentHours
        values.kilometers = ''
        values.hours = ''
      }
    } catch {
      const error = resultError(equipment, 'No se pudo conectar con el servidor. Revisá tu conexión e intentá nuevamente.')
      values.status = 'error'
      values.message = error.message
      results.value.push(error)
    }
  }
  saving.value = false
}
const focusNextReadingInput = (event) => {
  const form = event.target?.form
  if (!form) return
  const inputs = [...form.querySelectorAll('[data-reading-input="true"]')].filter((input) => !input.disabled && input.offsetParent !== null)
  const current = inputs.indexOf(event.target)
  const next = inputs[current + 1]
  if (next) {
    next.focus()
    next.select?.()
  }
}
</script>

<template>
  <PageHeading
    title="Lecturas rápidas"
    eyebrow="Kilómetros y horas"
    description="Buscá un móvil, ingresá su lectura y seguí con Enter. Guardá todas las lecturas cargadas al final."
  />

  <form method="post" :action="data.routes.submit" @submit.prevent="submitRows">
    <CsrfInput :csrf="data.csrf" />
    <PanelCard title="Equipos activos" :count="data.equipment.total">
      <div class="mb-4 grid gap-3 border-b border-border-subtle pb-4 xl:grid-cols-[minmax(18rem,1fr)_14rem_14rem_auto] xl:items-end">
        <label class="block text-sm font-semibold text-ink">
          Buscar móvil
          <span class="relative mt-1 block">
            <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-1/2 size-5 -translate-y-1/2 text-ink-subtle" aria-hidden="true" />
            <input
              v-model="filters.q"
              type="search"
              autocomplete="off"
              placeholder="Código, patente o chasis…"
              :class="`${fieldClass} pl-10`"
              @input="scheduleSearch"
            />
          </span>
        </label>
        <label class="block text-sm font-semibold text-ink">
          Sucursal
          <select v-model="filters.branchId" :class="`${fieldClass} mt-1`" @change="applyCatalogFilter">
            <option value="">Todas</option>
            <option v-for="branch in data.catalogs.branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
          </select>
        </label>
        <label class="block text-sm font-semibold text-ink">
          Tipo
          <select v-model="filters.typeId" :class="`${fieldClass} mt-1`" @change="applyCatalogFilter">
            <option value="">Todos</option>
            <option v-for="type in data.catalogs.types" :key="type.id" :value="type.id">{{ type.name }}</option>
          </select>
        </label>
        <div class="flex items-end gap-2 xl:justify-end">
          <label class="min-w-0 flex-1 text-sm font-semibold text-ink xl:w-52 xl:flex-none">
            Fecha y hora
            <input v-model="commonRecordedAt" type="datetime-local" :class="`${fieldClass} mt-1`" @change="applyCommonRecordedAt" />
          </label>
          <button type="button" :class="`${secondaryButton} shrink-0`" title="Usar fecha y hora actual" @click="refreshTimestamp">Ahora</button>
        </div>
      </div>

      <div v-if="results.length" class="mb-4 flex flex-wrap gap-2 text-xs" aria-live="polite">
        <span class="font-semibold text-ink-muted">Última carga:</span>
        <span v-for="row in results" :key="`${row.rowNumber}-${row.equipmentId}`" class="inline-flex items-center gap-1 rounded-full border border-border px-2 py-1" :class="row.success ? 'text-success-strong' : 'text-danger-strong'">
          <CheckCircleIcon v-if="row.success" class="size-4" aria-hidden="true" />
          <ExclamationTriangleIcon v-else class="size-4" aria-hidden="true" />
          {{ equipmentFor(row.equipmentId)?.code || 'Equipo' }}
        </span>
      </div>

      <EmptyState v-if="data.equipment.items.length === 0" title="No hay equipos para los filtros seleccionados" />
      <EmptyState v-else-if="visibleEquipment.length === 0" title="No hay coincidencias en esta página" />

      <div v-else class="overflow-x-auto rounded-xl border border-border">
        <table class="w-full min-w-[860px] border-collapse text-sm">
          <thead class="bg-surface-muted text-left text-xs font-bold uppercase tracking-wide text-ink-muted">
            <tr>
              <th class="sticky left-0 z-10 w-52 bg-surface-muted px-4 py-3">Equipo</th>
              <th class="w-44 px-4 py-3">Tipo / sucursal</th>
              <th class="w-44 px-4 py-3">Última lectura</th>
              <th class="px-4 py-3">Nueva lectura</th>
              <th class="w-36 px-4 py-3">Estado</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border-subtle bg-white">
            <tr v-for="equipment in visibleEquipment" :key="equipment.id" class="align-top hover:bg-surface-muted/50">
              <td class="sticky left-0 z-[1] bg-white px-4 py-3 group-hover:bg-surface-muted">
                <div class="font-bold text-primary">{{ equipment.code }}</div>
                <div class="mt-0.5 text-xs text-ink-muted">{{ equipment.plate || 'Sin patente' }}</div>
              </td>
              <td class="px-4 py-3">
                <div class="font-medium text-ink">{{ equipment.typeName }}</div>
                <div class="mt-0.5 text-xs text-ink-muted">{{ equipment.branchName }}</div>
              </td>
              <td class="px-4 py-3">
                <div class="font-semibold tabular-nums text-ink">{{ currentReadingText(equipment) }}</div>
                <div class="mt-0.5 text-xs text-ink-subtle">{{ equipment.lastReadingAt || 'Sin lectura previa' }}</div>
              </td>
              <td class="px-4 py-2.5">
                <div class="flex flex-wrap gap-3">
                  <div v-if="equipment.controlsKm" class="min-w-48 flex-1">
                    <div class="relative">
                      <input
                        :id="`quick-${equipment.id}-km`"
                        v-model="rows[equipment.id].kilometers"
                        data-reading-input="true"
                        type="text"
                        inputmode="numeric"
                        autocomplete="off"
                        placeholder="Kilómetros"
                        :disabled="!data.canRegister || saving"
                        :class="`${fieldClass} pr-10 font-semibold tabular-nums ${kilometerError(equipment) ? 'border-danger focus:border-danger focus:ring-danger/15' : ''}`"
                        @keydown.enter.prevent="focusNextReadingInput"
                      />
                      <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-xs font-bold text-ink-muted">km</span>
                    </div>
                    <p v-if="kilometerError(equipment)" class="mt-1 text-xs font-medium text-danger-strong">{{ kilometerError(equipment) }}</p>
                    <p v-else-if="inputDelta(equipment, 'kilometers')" class="mt-1 text-xs text-ink-muted">{{ inputDelta(equipment, 'kilometers') }}</p>
                  </div>
                  <div v-if="equipment.controlsHours" class="min-w-48 flex-1">
                    <div class="relative">
                      <input
                        :id="`quick-${equipment.id}-hours`"
                        v-model="rows[equipment.id].hours"
                        data-reading-input="true"
                        type="text"
                        inputmode="decimal"
                        autocomplete="off"
                        placeholder="Horómetro"
                        :disabled="!data.canRegister || saving"
                        :class="`${fieldClass} pr-8 font-semibold tabular-nums ${hoursError(equipment) ? 'border-danger focus:border-danger focus:ring-danger/15' : ''}`"
                        @keydown.enter.prevent="focusNextReadingInput"
                      />
                      <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-xs font-bold text-ink-muted">h</span>
                    </div>
                    <p v-if="hoursError(equipment)" class="mt-1 text-xs font-medium text-danger-strong">{{ hoursError(equipment) }}</p>
                    <p v-else-if="inputDelta(equipment, 'hours')" class="mt-1 text-xs text-ink-muted">{{ inputDelta(equipment, 'hours') }}</p>
                  </div>
                </div>
              </td>
              <td class="px-4 py-3">
                <span class="font-semibold" :class="rowStatusClass(equipment)">{{ rowStatusLabel(equipment) }}</span>
                <p v-if="rows[equipment.id].message" class="mt-1 max-w-44 text-xs text-ink-muted">{{ rows[equipment.id].message }}</p>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="invalidRows.length" class="mt-3 text-sm font-medium text-danger-strong" aria-live="polite">
        {{ invalidRows.length }} lectura{{ invalidRows.length === 1 ? '' : 's' }} con datos para revisar. Las válidas se pueden guardar igualmente.
      </div>

      <template #footer>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <PaginationBar :pagination="data.equipment.pagination" />
          <div class="flex flex-col items-stretch gap-2 sm:items-end">
            <span class="text-xs text-ink-muted">
              {{ enteredRows.length }} cargada{{ enteredRows.length === 1 ? '' : 's' }} · {{ readyRows.length }} lista{{ readyRows.length === 1 ? '' : 's' }} para guardar
            </span>
            <button v-if="data.canRegister && data.equipment.items.length" type="submit" :disabled="saving || readyRows.length === 0" :class="primaryButton">
              <ArrowPathIcon class="mr-2 size-5" :class="saving ? 'animate-spin' : ''" aria-hidden="true" />
              {{ saveButtonLabel }}
            </button>
          </div>
        </div>
      </template>
    </PanelCard>
  </form>
</template>
