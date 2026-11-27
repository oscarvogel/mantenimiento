<script setup>
import { computed, reactive, ref } from 'vue'
import { ArrowPathIcon, CheckCircleIcon, ChevronDownIcon, ExclamationTriangleIcon, MagnifyingGlassIcon, PrinterIcon, WrenchScrewdriverIcon } from '@heroicons/vue/24/outline'
import CsrfInput from './components/CsrfInput.vue'
import EmptyState from './components/EmptyState.vue'
import PageHeading from './components/PageHeading.vue'
import { isReportFailureMode } from '../../ui/fieldShortcuts.js'
import PaginationBar from './components/PaginationBar.vue'
import PanelCard from './components/PanelCard.vue'
import FilterChip from '../../components/ui/FilterChip.vue'
import { fieldClass, formatHours, formatKilometers, kilometersDelta, normalizeDecimalInput, nowLocal, parseFlexibleNumber, parseKilometers, primaryButton, readingDelta, secondaryButton } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })
const results = ref([...props.data.results])
const saving = ref(false)
const totalToSave = ref(0)
const currentSavingIndex = ref(0)
const commonRecordedAt = ref(props.data.recordedAtDefault || nowLocal())
const search = ref(props.data.filters.q || '')
const showMobileOptions = ref(Boolean(props.data.filters.branchId || props.data.filters.typeId))

// "Informar falla" (spec §6.2) reuses this fleet search: pick the unit, then report on
// its field page, where the incident form already lives.
const reportFailure = isReportFailureMode(window.location.search)
const baseUrl = document.body?.dataset?.baseUrl ?? '/'
const reportUrl = (equipment) => `${baseUrl}mantenimiento/equipos/${equipment.id}/operar#reportar-problema`
const statusFilter = ref('')
const rows = reactive(Object.fromEntries(props.data.equipment.items.map((equipment) => [equipment.id, {
  value: '', recordedAt: commonRecordedAt.value, currentKm: equipment.currentKm, currentHours: equipment.currentHours, status: 'pending', message: '',
}])))
const maintenance = reactive(Object.fromEntries(props.data.equipment.items.map((equipment) => [equipment.id, equipment.maintenance || { state: 'SIN_PLAN', primaryPlan: null, plans: [], planCount: 0 }])))
const orderSaving = reactive({})
const orderErrors = reactive({})
const csrf = reactive({ ...props.data.csrf })

const readingKey = (equipment) => equipment.controlsHours && !equipment.controlsKm ? 'hours' : 'kilometers'
const readingUnit = (equipment) => readingKey(equipment) === 'hours' ? 'h' : 'km'
const currentValue = (equipment) => readingKey(equipment) === 'hours' ? rows[equipment.id].currentHours : rows[equipment.id].currentKm
const formattedCurrent = (equipment) => readingKey(equipment) === 'hours' ? formatHours(rows[equipment.id].currentHours) : formatKilometers(rows[equipment.id].currentKm)
const parsedValue = (equipment) => readingKey(equipment) === 'hours' ? parseFlexibleNumber(rows[equipment.id].value) : parseKilometers(rows[equipment.id].value)
const parsedCurrent = (equipment) => readingKey(equipment) === 'hours' ? parseFlexibleNumber(currentValue(equipment)) : parseKilometers(currentValue(equipment))
const rowError = (equipment) => {
  if (rows[equipment.id].value === '') return ''
  const next = parsedValue(equipment)
  if (next === null || next < 0) return readingKey(equipment) === 'hours' ? 'Ingresá un horómetro válido.' : 'Ingresá un kilometraje entero válido.'
  const current = parsedCurrent(equipment)
  return current !== null && next < current ? `No puede ser menor a ${formattedCurrent(equipment)}.` : ''
}
const inputDelta = (equipment) => {
  if (rows[equipment.id].value === '' || rowError(equipment)) return ''
  const delta = readingKey(equipment) === 'hours'
    ? readingDelta(rows[equipment.id].currentHours, rows[equipment.id].value)
    : kilometersDelta(rows[equipment.id].currentKm, rows[equipment.id].value)
  if (delta === null || delta === undefined || delta === 0) return ''
  const abs = Math.abs(delta)
  const number = readingKey(equipment) === 'hours'
    ? abs.toLocaleString('es-AR', { minimumFractionDigits: 1, maximumFractionDigits: 1 })
    : Math.round(abs).toLocaleString('es-AR')
  return `${delta > 0 ? '+' : '-'}${number} ${readingUnit(equipment)}`
}
const enteredRows = computed(() => props.data.equipment.items.filter(({ id }) => rows[id].value !== ''))
const readyRows = computed(() => enteredRows.value.filter((equipment) => !rowError(equipment)))
const invalidRows = computed(() => enteredRows.value.filter((equipment) => Boolean(rowError(equipment))))
const keepSaveVisible = computed(() => saving.value || readyRows.value.length > 0)
const maintenanceCounts = computed(() => {
  const counts = { OK: 0, PROXIMO: 0, VENCIDO: 0, PROBLEMA: 0, SIN_PLAN: 0 }
  props.data.equipment.items.forEach(({ id }) => { const state = maintenance[id]?.state || 'SIN_PLAN'; if (Object.hasOwn(counts, state)) counts[state] += 1 })
  return counts
})
const statusFilters = computed(() => [
  { key: '', label: 'Todos', count: props.data.equipment.items.length },
  { key: 'VENCIDO', label: 'Vencidos', count: maintenanceCounts.value.VENCIDO },
  { key: 'PROXIMO', label: 'Próximos', count: maintenanceCounts.value.PROXIMO },
  { key: 'PROBLEMA', label: 'Problemas', count: maintenanceCounts.value.PROBLEMA },
  { key: 'SIN_PLAN', label: 'Sin plan', count: maintenanceCounts.value.SIN_PLAN },
])
const normalizeSearch = (value) => String(value ?? '')
  .trim()
  .toLocaleLowerCase('es')
  .normalize('NFD')
  .replace(/[\u0300-\u036f]/g, '')
  .replace(/[\s-]+/g, '')
const visibleEquipment = computed(() => {
  const query = normalizeSearch(search.value)
  return props.data.equipment.items.filter((equipment) => {
    const matches = !query || [equipment.code, equipment.plate, equipment.chassis, equipment.typeName, equipment.branchName].some((value) => normalizeSearch(value).includes(query))
    return matches && (!statusFilter.value || (maintenance[equipment.id]?.state || 'SIN_PLAN') === statusFilter.value)
  })
})
const saveButtonLabel = computed(() => saving.value ? `Guardando ${currentSavingIndex.value} de ${totalToSave.value}…` : readyRows.value.length ? `Guardar ${readyRows.value.length} lectura${readyRows.value.length === 1 ? '' : 's'}` : 'Guardar lecturas')
const primaryPlan = (equipment) => maintenance[equipment.id]?.primaryPlan || null
const formatPlanPoint = (plan, prefix) => {
  if (!plan) return '—'
  const values = []
  if (plan[`${prefix}Km`] !== null && plan[`${prefix}Km`] !== undefined) values.push(`${Number(plan[`${prefix}Km`]).toLocaleString('es-AR')} km`)
  if (plan[`${prefix}Hours`] !== null && plan[`${prefix}Hours`] !== undefined) values.push(`${Number(plan[`${prefix}Hours`]).toLocaleString('es-AR', { minimumFractionDigits: 1, maximumFractionDigits: 1 })} h`)
  if (plan[`${prefix}Date`]) values.push(plan[`${prefix}Date`].split('-').reverse().join('/'))
  return values.join(' · ') || 'Sin base'
}
const missingCriterionLabel = (criterion, plan, equipment) => {
  if (criterion === 'KILOMETRAJE') {
    if (plan.baseKm === null || plan.baseKm === undefined) return 'Falta última realización en km'
    if (rows[equipment.id]?.currentKm === null || rows[equipment.id]?.currentKm === undefined) return 'Falta lectura de kilometraje'
    return 'Falta dato de kilometraje'
  }
  if (criterion === 'HOROMETRO') {
    if (plan.baseHours === null || plan.baseHours === undefined) return 'Falta última realización en horas'
    if (rows[equipment.id]?.currentHours === null || rows[equipment.id]?.currentHours === undefined) return 'Falta lectura de horómetro'
    return 'Falta dato de horómetro'
  }
  if (criterion === 'FECHA') return 'Falta fecha de última realización'
  return 'Falta información del plan'
}
const missingPlanDetails = (equipment) => (maintenance[equipment.id]?.plans || []).flatMap((plan) =>
  (plan.missingCriteria || []).map((criterion) => `${plan.serviceName}: ${missingCriterionLabel(criterion, plan, equipment)}`),
)
const preventiveLabel = (equipment) => {
  const snapshot = maintenance[equipment.id] || { state: 'SIN_PLAN' }
  const critical = snapshot.primaryPlan?.critical
  if (snapshot.state === 'SIN_PLAN') return 'Sin plan'
  if (snapshot.state === 'PROBLEMA') return missingPlanDetails(equipment)[0]?.split(': ').slice(1).join(': ') || 'Faltan datos'
  if (!critical) return snapshot.state === 'OK' ? 'Al día' : snapshot.state
  const absolute = Math.abs(Number(critical.value))
  const formatted = critical.unit === 'km' ? Math.round(absolute).toLocaleString('es-AR') : absolute.toLocaleString('es-AR', { minimumFractionDigits: critical.unit === 'h' ? 1 : 0, maximumFractionDigits: 1 })
  return snapshot.state === 'VENCIDO' ? `Vencido ${formatted} ${critical.unit}` : `Faltan ${formatted} ${critical.unit}`
}
const preventiveClass = (equipment) => ({ OK: 'border-success/30 bg-success-subtle/50 text-success-strong', PROXIMO: 'border-warning/40 bg-warning-subtle text-warning-foreground', VENCIDO: 'border-danger/30 bg-danger-subtle/60 text-danger-strong', PROBLEMA: 'border-danger/30 bg-danger-subtle/40 text-danger-strong', SIN_PLAN: 'border-border bg-surface-muted text-ink-muted' }[maintenance[equipment.id]?.state || 'SIN_PLAN'])
const applyCommonRecordedAt = () => props.data.equipment.items.forEach(({ id }) => { rows[id].recordedAt = commonRecordedAt.value })
const refreshTimestamp = () => { commonRecordedAt.value = nowLocal(); applyCommonRecordedAt() }
const changeCatalogFilter = (key, value) => {
  const url = new URL(props.data.routes.index, window.location.origin)
  if (key === 'branch' && value) url.searchParams.set('sucursal_id', value)
  if (key === 'type' && value) url.searchParams.set('tipo_id', value)
  const otherKey = key === 'branch' ? 'tipo_id' : 'sucursal_id'
  const otherValue = key === 'branch' ? props.data.filters.typeId : props.data.filters.branchId
  if (otherValue) url.searchParams.set(otherKey, otherValue)
  url.searchParams.set('per_page', String(props.data.filters.perPage || 50))
  window.location.assign(url.toString())
}
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
const submitRows = async () => {
  if (!readyRows.value.length || saving.value) return
  const batch = [...readyRows.value]
  totalToSave.value = batch.length; currentSavingIndex.value = 0; saving.value = true; results.value = []
  for (const equipment of batch) {
    currentSavingIndex.value += 1
    const row = rows[equipment.id]
    const previous = currentValue(equipment)
    row.status = 'saving'; row.message = ''
    const body = new FormData()
    body.append(csrf.name, csrf.hash)
    body.append('equipmentId', String(equipment.id))
    body.append('kilometers', readingKey(equipment) === 'kilometers' ? row.value : '')
    body.append('hours', readingKey(equipment) === 'hours' ? normalizeDecimalInput(row.value) : '')
    body.append('recordedAt', row.recordedAt)
    body.append('notes', '')
    try {
      const parsed = await responsePayload(await fetch(props.data.routes.submitRow, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } }))
      if (parsed.payload?.csrf) Object.assign(csrf, parsed.payload.csrf)
      if (!parsed.payload?.result) { row.status = 'error'; row.message = parsed.payload?.error || parsed.error || 'No se pudo guardar la lectura.'; results.value.push({ rowNumber: currentSavingIndex.value, equipmentId: equipment.id, success: false, message: row.message }); continue }
      const result = parsed.payload.result
      result.submittedKilometers = readingKey(equipment) === 'kilometers'
      result.submittedHours = readingKey(equipment) === 'hours'
      result.previousKilometers = result.submittedKilometers ? previous : null
      result.previousHours = result.submittedHours ? previous : null
      results.value.push(result)
      row.status = result.success ? 'saved' : 'error'; row.message = result.message || (result.success ? 'Lectura guardada.' : 'No se pudo guardar la lectura.')
      if (result.success) {
        row.currentKm = result.currentKilometers; row.currentHours = result.currentHours; row.value = ''
        if (parsed.payload?.maintenance) maintenance[equipment.id] = parsed.payload.maintenance
      }
    } catch {
      row.status = 'error'; row.message = 'No se pudo conectar con el servidor.'; results.value.push({ rowNumber: currentSavingIndex.value, equipmentId: equipment.id, success: false, message: row.message })
    }
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
  const inputs = [...event.target.form.querySelectorAll('[data-reading-input="true"]')].filter((input) => !input.disabled && input.offsetParent !== null)
  const next = inputs[inputs.indexOf(event.target) + 1]
  if (next) { next.focus(); next.select?.() }
  if (next) return
  const equipment = props.data.equipment.items.find((item) => String(item.id) === String(event.target.dataset.equipmentId))
  if (equipment && !rowError(equipment) && readyRows.value.length && !saving.value) event.target.form?.requestSubmit()
}
</script>

<template>
  <template v-if="reportFailure">
    <PageHeading title="Informar una falla" eyebrow="Solicitudes" description="Elegí el equipo que tiene el problema para describirlo." />
    <PanelCard title="Equipos" :count="data.equipment.total">
      <label class="block text-sm font-semibold text-ink">Buscar por código, patente o chasis
        <span class="relative mt-1 block">
          <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-1/2 size-5 -translate-y-1/2 text-ink-subtle" aria-hidden="true" />
          <input v-model="search" type="search" autocomplete="off" :class="`${fieldClass} pl-10`" />
        </span>
      </label>
      <EmptyState v-if="visibleEquipment.length === 0" class="mt-4" title="No hay coincidencias" description="Probá con otro código, patente o chasis." />
      <ul v-else class="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
        <li v-for="equipment in visibleEquipment" :key="equipment.id">
          <a :href="reportUrl(equipment)" class="ui-interactive flex min-h-16 items-center justify-between gap-3 rounded-card bg-surface-raised px-4 py-3 hover:border-primary/40 hover:bg-primary-subtle/40 shadow-card">
            <span class="min-w-0">
              <span class="block truncate font-bold text-ink">{{ equipment.code }}<span v-if="equipment.plate" class="font-medium text-ink-muted"> · {{ equipment.plate }}</span></span>
              <span class="block truncate text-sm text-ink-muted">{{ equipment.typeName }}<template v-if="equipment.branchName"> · {{ equipment.branchName }}</template></span>
            </span>
            <span class="shrink-0 text-sm font-semibold text-primary">Informar</span>
          </a>
        </li>
      </ul>
    </PanelCard>
  </template>
  <PageHeading v-if="!reportFailure" eyebrow="Campo" title="Registrar km/horas" description="Buscá el equipo, ingresá la lectura y guardá las que estén listas." />
  <form v-if="!reportFailure" method="post" :action="data.routes.submit" @submit.prevent="submitRows">
    <CsrfInput :csrf="data.csrf" />
    <PanelCard title="Equipos activos" :count="data.equipment.total" allow-overflow flush>
      <div class="space-y-4 p-3 sm:p-6">
        <div class="grid grid-cols-2 gap-2 sm:gap-3 xl:grid-cols-[minmax(0,1.5fr)_repeat(2,minmax(0,1fr))_minmax(0,1.5fr)] xl:items-end">
          <label class="col-span-2 min-w-0 text-sm font-semibold text-ink xl:col-span-1">
            Buscar equipo
            <span class="relative mt-1 block">
              <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-1/2 size-5 -translate-y-1/2 text-ink-subtle" aria-hidden="true" />
              <input v-model="search" data-quick-search type="search" autocomplete="off" placeholder="Código, patente o chasis…" :class="[fieldClass, 'pl-10']" />
            </span>
          </label>
          <button
            type="button"
            class="ui-interactive col-span-2 flex min-h-11 items-center justify-between gap-2 rounded-md px-1 text-left text-sm font-semibold text-primary hover:text-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-border-focus lg:hidden"
            :aria-expanded="showMobileOptions"
            aria-controls="quick-reading-options"
            @click="showMobileOptions = !showMobileOptions"
          >
            Filtros y fecha de lectura
            <ChevronDownIcon class="size-4 shrink-0" :class="{ 'rotate-180': showMobileOptions }" aria-hidden="true" />
          </button>
          <div
            id="quick-reading-options"
            class="col-span-2 grid-cols-2 gap-3 lg:grid xl:contents"
            :class="showMobileOptions ? 'grid' : 'hidden'"
          >
            <label class="min-w-0 text-sm font-semibold text-ink">
              Sucursal
              <select :value="data.filters.branchId" :class="[fieldClass, 'mt-1']" @change="changeCatalogFilter('branch', $event.target.value)">
                <option value="">Todas</option>
                <option v-for="branch in data.catalogs.branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
              </select>
            </label>
            <label class="min-w-0 text-sm font-semibold text-ink">
              Tipo
              <select :value="data.filters.typeId" :class="[fieldClass, 'mt-1']" @change="changeCatalogFilter('type', $event.target.value)">
                <option value="">Todos</option>
                <option v-for="type in data.catalogs.types" :key="type.id" :value="type.id">{{ type.name }}</option>
              </select>
            </label>
            <div class="col-span-2 min-w-0 xl:col-span-1">
              <div class="flex min-w-0 flex-wrap items-center justify-between gap-x-2">
                <label for="quick-reading-common-date" class="text-sm font-semibold text-ink">Fecha de lectura</label>
                <button type="button" class="ui-interactive inline-flex min-h-11 items-center whitespace-nowrap rounded-md px-1 text-xs font-semibold text-primary underline underline-offset-4 hover:text-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-border-focus" aria-label="Usar fecha y hora actual" @click="refreshTimestamp">Usar ahora</button>
              </div>
              <input id="quick-reading-common-date" v-model="commonRecordedAt" type="datetime-local" :class="[fieldClass, 'min-w-0']" @change="applyCommonRecordedAt" />
            </div>
            <div role="group" aria-label="Estado del plan preventivo" class="col-span-2 flex flex-wrap gap-2 text-xs font-semibold xl:col-span-4">
              <FilterChip v-for="item in statusFilters" :key="item.key" :label="item.label" :count="item.count" :active="statusFilter === item.key" @select="statusFilter = item.key" />
            </div>
          </div>
        </div>

        <p v-if="data.canRegister && visibleEquipment.length" class="hidden text-xs text-ink-muted lg:block">Con Enter podés pasar al siguiente equipo y guardar las lecturas válidas desde la última fila.</p>

        <div v-if="results.length" class="flex flex-wrap items-center gap-2 text-xs" aria-live="polite">
          <span class="font-semibold text-ink-muted">Última carga:</span>
          <span v-for="result in results" :key="`${result.rowNumber}-${result.equipmentId}`" class="inline-flex min-w-0 items-center gap-1.5 rounded-md bg-surface-muted px-2 py-1" :class="result.success ? 'text-success-strong' : 'text-danger-strong'">
            <CheckCircleIcon v-if="result.success" class="size-4 shrink-0" aria-hidden="true" />
            <ExclamationTriangleIcon v-else class="size-4 shrink-0" aria-hidden="true" />
            <span class="[overflow-wrap:anywhere]"><span class="sr-only">{{ result.success ? 'Guardada:' : 'Error:' }} </span>{{ props.data.equipment.items.find((item) => item.id === result.equipmentId)?.code }}</span>
          </span>
        </div>

        <p v-if="invalidRows.length" class="text-sm font-semibold text-danger-strong">{{ invalidRows.length }} lectura{{ invalidRows.length === 1 ? '' : 's' }} para corregir. Las válidas se pueden guardar.</p>
      </div>

      <div
        v-if="data.canRegister && data.equipment.items.length"
        class="mx-3 grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3 rounded-2xl bg-surface-muted px-4 py-2.5 sm:mx-6"
        :class="{ 'sticky top-[4.5rem] z-10': keepSaveVisible }"
      >
        <p aria-live="polite" class="text-xs text-ink-muted">
          <template v-if="readyRows.length || saving">{{ readyRows.length }} lectura{{ readyRows.length === 1 ? '' : 's' }} lista{{ readyRows.length === 1 ? '' : 's' }} para guardar</template>
          <template v-else-if="invalidRows.length">Corregí las lecturas marcadas.</template>
          <template v-else>Ingresá una lectura para guardar.</template>
        </p>
        <button type="submit" :disabled="saving || !readyRows.length" :class="[primaryButton, 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-border-focus']">
          <ArrowPathIcon v-if="saving" class="size-5 shrink-0 animate-spin" aria-hidden="true" />
          {{ saveButtonLabel }}
        </button>
      </div>

      <EmptyState v-if="data.equipment.items.length === 0" class="m-4 sm:m-6" title="No hay equipos para los filtros seleccionados" />
      <EmptyState v-else-if="visibleEquipment.length === 0" class="m-4 sm:m-6" title="No hay coincidencias" description="Probá con otro código, patente o chasis, o cambiá el filtro de estado." />
      <template v-else>
        <!-- Fila redondeada por equipo: ficha apilada en el celular, tabla desde xl (patrón Carga). -->
        <table role="table" aria-label="Lecturas por equipo" class="block w-full text-sm xl:table xl:table-fixed xl:border-separate xl:border-spacing-y-1.5">
          <thead role="rowgroup" class="sr-only text-left text-xs font-semibold text-ink-subtle xl:not-sr-only xl:table-header-group">
            <tr role="row">
              <th role="columnheader" scope="col" class="w-[16%] px-4 py-3 sm:pl-6">Equipo</th>
              <th role="columnheader" scope="col" class="w-[15%] px-3 py-3">Última lectura</th>
              <th role="columnheader" scope="col" class="w-[23%] px-3 py-3">Nueva lectura</th>
              <th role="columnheader" scope="col" class="w-[28%] px-3 py-3">Mantenimiento preventivo</th>
              <th role="columnheader" scope="col" class="w-[18%] px-3 py-3">Acciones del plan</th>
            </tr>
          </thead>
          <tbody role="rowgroup" class="block space-y-2 px-3 pt-3 sm:px-6 xl:table-row-group xl:space-y-0">
            <tr
              v-for="equipment in visibleEquipment"
              :key="equipment.id"
              role="row"
              class="grid min-w-0 grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)] gap-3 rounded-[18px] bg-surface-subtle px-4 py-3 sm:grid-cols-2 sm:py-4 xl:table-row xl:bg-transparent xl:[&>td:first-child]:rounded-l-[14px] xl:[&>td:last-child]:rounded-r-[14px] xl:[&>td]:bg-surface-subtle"
            >
              <td role="cell" class="block min-w-0 [overflow-wrap:anywhere] sm:col-span-2 xl:table-cell xl:px-4 xl:py-4 xl:pl-6 xl:align-top">
                <strong class="block text-base text-ink xl:text-sm">{{ equipment.code }}</strong>
                <span class="block text-xs text-ink-muted">{{ equipment.plate || 'Sin patente' }}</span>
              </td>
              <td role="cell" class="block min-w-0 [overflow-wrap:anywhere] xl:table-cell xl:px-3 xl:py-4 xl:align-top">
                <span :id="`quick-reading-current-${equipment.id}`">
                  <span class="block text-xs font-semibold text-ink-muted xl:sr-only">Última lectura </span>
                  <strong class="font-semibold tabular-nums text-ink">{{ formattedCurrent(equipment) }}</strong>
                </span>
              </td>
              <td role="cell" class="col-span-2 block min-w-0 sm:col-span-1 xl:table-cell xl:px-3 xl:py-3 xl:align-top">
                <label :for="`quick-reading-${equipment.id}`" class="mb-1 block text-xs font-semibold text-ink-muted xl:sr-only">
                  Nueva lectura <span class="sr-only">de {{ equipment.code }} en {{ readingUnit(equipment) === 'h' ? 'horas' : 'kilómetros' }}</span>
                </label>
                <div class="relative">
                  <input
                    :id="`quick-reading-${equipment.id}`"
                    v-model="rows[equipment.id].value"
                    data-reading-input="true"
                    :data-equipment-id="equipment.id"
                    type="text"
                    :inputmode="readingKey(equipment) === 'hours' ? 'decimal' : 'numeric'"
                    autocomplete="off"
                    placeholder="Ingresar lectura"
                    :disabled="!data.canRegister || saving"
                    :aria-invalid="Boolean(rowError(equipment))"
                    :aria-describedby="`quick-reading-current-${equipment.id} quick-reading-feedback-${equipment.id}`"
                    :class="[fieldClass, 'pr-10 font-semibold tabular-nums']"
                    @keydown.enter.prevent="focusNextReadingInput"
                  />
                  <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-xs font-bold text-ink-muted" aria-hidden="true">{{ readingUnit(equipment) }}</span>
                </div>
                <div :id="`quick-reading-feedback-${equipment.id}`" aria-live="polite" class="[overflow-wrap:anywhere]">
                  <p v-if="rowError(equipment)" class="mt-1 text-xs font-semibold text-danger-strong">{{ rowError(equipment) }}</p>
                  <p v-else-if="inputDelta(equipment)" class="mt-1 text-xs text-ink-muted">{{ inputDelta(equipment) }} desde la última</p>
                  <p v-if="rows[equipment.id].message" class="mt-1 text-xs" :class="rows[equipment.id].status === 'error' ? 'text-danger-strong' : 'text-success-strong'">{{ rows[equipment.id].message }}</p>
                </div>
              </td>
              <td role="cell" class="col-span-2 block min-w-0 [overflow-wrap:anywhere] sm:col-span-1 xl:table-cell xl:px-3 xl:py-4 xl:align-top">
                <a v-if="maintenance[equipment.id]?.state === 'SIN_PLAN' && equipment.assignPlanUrl" :href="equipment.assignPlanUrl" class="ui-interactive inline-flex min-h-11 items-center rounded-md px-1 text-xs font-semibold text-primary underline underline-offset-4 hover:text-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-border-focus" title="Asignar un plan preventivo">Sin plan · Asignar</a>
                <span v-else class="inline-flex rounded-pill border px-2.5 py-1 text-xs font-semibold" :class="preventiveClass(equipment)">{{ preventiveLabel(equipment) }}</span>

                <template v-if="primaryPlan(equipment)">
                  <p class="mt-2 font-medium text-ink">{{ primaryPlan(equipment).serviceName }}</p>
                  <dl class="mt-1 grid grid-cols-2 gap-2 text-xs">
                    <div class="min-w-0">
                      <dt class="text-ink-muted">Último service</dt>
                      <dd class="font-medium tabular-nums text-ink">{{ formatPlanPoint(primaryPlan(equipment), 'base') }}</dd>
                    </div>
                    <div class="min-w-0">
                      <dt class="text-ink-muted">Próximo service</dt>
                      <dd class="font-medium tabular-nums text-ink">{{ formatPlanPoint(primaryPlan(equipment), 'next') }}</dd>
                    </div>
                  </dl>
                </template>

                <details v-if="missingPlanDetails(equipment).length" class="mt-1 text-xs text-danger-strong">
                  <summary class="cursor-pointer rounded-md py-3 font-semibold underline underline-offset-4 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-border-focus">Ver faltantes</summary>
                  <ul class="space-y-1">
                    <li v-for="detail in missingPlanDetails(equipment)" :key="detail">{{ detail }}</li>
                  </ul>
                </details>
                <p v-else-if="maintenance[equipment.id]?.planCount > 1" class="mt-1 text-xs text-ink-muted">+{{ maintenance[equipment.id].planCount - 1 }} planes</p>
              </td>
              <td
                role="cell"
                class="col-span-2 min-w-0 [overflow-wrap:anywhere] sm:col-span-1 xl:table-cell xl:px-3 xl:py-4 xl:pr-6 xl:align-top"
                :class="primaryPlan(equipment)?.order || (data.canGenerateOrder && primaryPlan(equipment)?.noticeId) || orderErrors[equipment.id] ? 'block' : 'hidden'"
              >
                <template v-if="primaryPlan(equipment)?.order">
                  <p class="text-xs font-semibold text-success-strong">{{ primaryPlan(equipment).order.number }}</p>
                  <a :href="`${data.routes.workOrderBase}/${primaryPlan(equipment).order.id}/imprimir`" target="_blank" rel="noopener" :class="[secondaryButton, 'mt-1.5']">
                    <PrinterIcon class="size-4 shrink-0" aria-hidden="true" />Imprimir
                  </a>
                </template>
                <button v-else-if="data.canGenerateOrder && primaryPlan(equipment)?.noticeId" type="button" :disabled="orderSaving[equipment.id]" :class="secondaryButton" @click="generateOrder(equipment)">
                  <ArrowPathIcon v-if="orderSaving[equipment.id]" class="size-4 shrink-0 animate-spin" aria-hidden="true" />
                  <WrenchScrewdriverIcon v-else class="size-4 shrink-0" aria-hidden="true" />
                  {{ orderSaving[equipment.id] ? 'Generando…' : 'Generar OT' }}
                </button>
                <span v-else class="sr-only">Sin acciones disponibles</span>
                <p v-if="orderErrors[equipment.id]" role="alert" class="mt-1 text-xs text-danger-strong">{{ orderErrors[equipment.id] }}</p>
              </td>
            </tr>
          </tbody>
        </table>
      </template>
      <template #footer>
        <div class="px-4 py-3 sm:px-6">
          <PaginationBar :pagination="data.equipment.pagination" embedded compact-single-page />
        </div>
      </template>
    </PanelCard>
  </form>
</template>
