<script setup>
import { computed, reactive, ref } from 'vue'
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
const search = ref(props.data.filters.q || '')
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
const maintenanceCounts = computed(() => {
  const counts = { OK: 0, PROXIMO: 0, VENCIDO: 0, PROBLEMA: 0, SIN_PLAN: 0 }
  props.data.equipment.items.forEach(({ id }) => { const state = maintenance[id]?.state || 'SIN_PLAN'; if (Object.hasOwn(counts, state)) counts[state] += 1 })
  return counts
})
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
const saveButtonLabel = computed(() => saving.value ? `Guardando ${currentSavingIndex.value} de ${totalToSave.value}…` : readyRows.value.length ? `Guardar ${readyRows.value.length} lectura${readyRows.value.length === 1 ? '' : 's'}` : invalidRows.value.length ? 'Corregí las lecturas marcadas' : 'Ingresá al menos una lectura')
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
const preventiveClass = (equipment) => ({ OK: 'border-success/30 bg-success-subtle/50 text-success-strong', PROXIMO: 'border-warning/40 bg-warning-subtle/60 text-warning-strong', VENCIDO: 'border-danger/30 bg-danger-subtle/60 text-danger-strong', PROBLEMA: 'border-danger/30 bg-danger-subtle/40 text-danger-strong', SIN_PLAN: 'border-border bg-surface-muted text-ink-muted' }[maintenance[equipment.id]?.state || 'SIN_PLAN'])
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
}
</script>

<template>
  <PageHeading title="Lecturas rápidas" eyebrow="Carga y mantenimiento preventivo" description="Cargá la flota con Enter, guardá y resolvé los vencimientos desde la misma pantalla." />
  <form method="post" :action="data.routes.submit" @submit.prevent="submitRows">
    <CsrfInput :csrf="data.csrf" />
    <PanelCard title="Equipos activos" :count="data.equipment.total">
      <div class="mb-3 grid gap-3 border-b border-border-subtle pb-3 xl:grid-cols-[minmax(18rem,1fr)_13rem_13rem_auto] xl:items-end">
        <label class="block text-sm font-semibold text-ink">Buscar móvil<span class="relative mt-1 block"><MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-1/2 size-5 -translate-y-1/2 text-ink-subtle" /><input v-model="search" data-quick-search type="search" autocomplete="off" placeholder="Código, patente o chasis…" :class="`${fieldClass} pl-10`" /></span></label>
        <label class="block text-sm font-semibold text-ink">Sucursal<select :value="data.filters.branchId" :class="`${fieldClass} mt-1`" @change="changeCatalogFilter('branch', $event.target.value)"><option value="">Todas</option><option v-for="branch in data.catalogs.branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option></select></label>
        <label class="block text-sm font-semibold text-ink">Tipo<select :value="data.filters.typeId" :class="`${fieldClass} mt-1`" @change="changeCatalogFilter('type', $event.target.value)"><option value="">Todos</option><option v-for="type in data.catalogs.types" :key="type.id" :value="type.id">{{ type.name }}</option></select></label>
        <div class="flex items-end gap-2"><label class="text-sm font-semibold text-ink">Fecha y hora<input v-model="commonRecordedAt" type="datetime-local" :class="`${fieldClass} mt-1`" @change="applyCommonRecordedAt" /></label><button type="button" :class="secondaryButton" @click="refreshTimestamp">Ahora</button></div>
      </div>

      <div class="mb-3 flex flex-wrap gap-2 text-xs font-semibold">
        <button v-for="item in [{key:'',label:'Todos',count:data.equipment.items.length},{key:'VENCIDO',label:'Vencidos',count:maintenanceCounts.VENCIDO},{key:'PROXIMO',label:'Próximos',count:maintenanceCounts.PROXIMO},{key:'PROBLEMA',label:'Problemas',count:maintenanceCounts.PROBLEMA},{key:'SIN_PLAN',label:'Sin plan',count:maintenanceCounts.SIN_PLAN}]" :key="item.key" type="button" class="rounded-full border border-border px-3 py-1.5" :class="statusFilter === item.key ? 'bg-primary-subtle text-primary' : 'text-ink-muted'" @click="statusFilter = item.key">{{ item.label }} {{ item.count }}</button>
      </div>

      <div v-if="results.length" class="mb-3 flex flex-wrap gap-2 text-xs" aria-live="polite"><span class="font-semibold text-ink-muted">Última carga:</span><span v-for="result in results" :key="`${result.rowNumber}-${result.equipmentId}`" class="inline-flex items-center gap-1 rounded-full border border-border px-2 py-1" :class="result.success ? 'text-success-strong' : 'text-danger-strong'"><CheckCircleIcon v-if="result.success" class="size-4" /><ExclamationTriangleIcon v-else class="size-4" />{{ props.data.equipment.items.find((item) => item.id === result.equipmentId)?.code }}</span></div>

      <EmptyState v-if="data.equipment.items.length === 0" title="No hay equipos para los filtros seleccionados" />
      <EmptyState v-else-if="visibleEquipment.length === 0" title="No hay coincidencias" description="Probá con otro código, patente o chasis." />
      <div v-else class="overflow-x-auto rounded-xl border border-border">
        <table class="w-full min-w-[1240px] border-collapse text-sm">
          <thead class="bg-surface-muted text-left text-xs font-bold uppercase tracking-wide text-ink-muted"><tr><th class="px-3 py-2">Equipo</th><th class="px-3 py-2">Última lectura</th><th class="px-3 py-2">Nueva lectura</th><th class="px-3 py-2">Último service</th><th class="px-3 py-2">Próximo service</th><th class="px-3 py-2">Estado</th><th class="px-3 py-2">Acción</th></tr></thead>
          <tbody class="divide-y divide-border-subtle bg-white">
            <tr v-for="equipment in visibleEquipment" :key="equipment.id" class="hover:bg-surface-muted/40">
              <td class="px-3 py-2"><strong class="text-primary">{{ equipment.code }}</strong><div class="text-xs text-ink-muted">{{ equipment.plate || 'Sin patente' }}</div></td>
              <td class="px-3 py-2 font-semibold tabular-nums">{{ formattedCurrent(equipment) }}</td>
              <td class="px-3 py-2"><div class="relative max-w-52"><input :id="`quick-reading-${equipment.id}`" v-model="rows[equipment.id].value" data-reading-input="true" :data-equipment-id="equipment.id" type="text" :inputmode="readingKey(equipment) === 'hours' ? 'decimal' : 'numeric'" autocomplete="off" placeholder="Ingresar lectura" :disabled="!data.canRegister || saving" :class="`${fieldClass} pr-10 font-semibold tabular-nums ${rowError(equipment) ? 'border-danger' : ''}`" @keydown.enter.prevent="focusNextReadingInput" /><span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-bold text-ink-muted">{{ readingUnit(equipment) }}</span></div><p v-if="rowError(equipment)" class="mt-1 text-xs font-semibold text-danger-strong">{{ rowError(equipment) }}</p><p v-else-if="inputDelta(equipment)" class="mt-1 text-xs text-ink-muted">{{ inputDelta(equipment) }} desde la última</p><p v-if="rows[equipment.id].message" class="mt-1 text-xs" :class="rows[equipment.id].status === 'error' ? 'text-danger-strong' : 'text-success-strong'">{{ rows[equipment.id].message }}</p></td>
              <td class="px-3 py-2"><template v-if="primaryPlan(equipment)"><div class="font-medium">{{ primaryPlan(equipment).serviceName }}</div><div class="text-xs text-ink-muted">{{ formatPlanPoint(primaryPlan(equipment), 'base') }}</div></template><span v-else>—</span></td>
              <td class="px-3 py-2"><template v-if="primaryPlan(equipment)"><div class="font-medium">{{ primaryPlan(equipment).serviceName }}</div><div class="text-xs text-ink-muted">{{ formatPlanPoint(primaryPlan(equipment), 'next') }}</div></template><span v-else>—</span></td>
              <td class="px-3 py-2"><span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-bold" :class="preventiveClass(equipment)">{{ preventiveLabel(equipment) }}</span><details v-if="missingPlanDetails(equipment).length" class="mt-1 text-[11px] text-danger-strong"><summary class="cursor-pointer font-semibold">Ver faltantes</summary><div v-for="detail in missingPlanDetails(equipment)" :key="detail" class="mt-1">{{ detail }}</div></details><div v-else-if="maintenance[equipment.id]?.planCount > 1" class="mt-1 text-[11px] text-ink-subtle">+{{ maintenance[equipment.id].planCount - 1 }} planes</div></td>
              <td class="px-3 py-2"><template v-if="primaryPlan(equipment)?.order"><div class="text-xs font-bold text-success-strong">{{ primaryPlan(equipment).order.number }}</div><a :href="`${data.routes.workOrderBase}/${primaryPlan(equipment).order.id}/imprimir`" target="_blank" rel="noopener" :class="`${secondaryButton} mt-1 px-2 py-1 text-xs`"><PrinterIcon class="mr-1 size-4" />Imprimir</a></template><button v-else-if="data.canGenerateOrder && primaryPlan(equipment)?.noticeId" type="button" :disabled="orderSaving[equipment.id]" :class="`${primaryButton} px-2 py-1 text-xs`" @click="generateOrder(equipment)"><ArrowPathIcon v-if="orderSaving[equipment.id]" class="mr-1 size-4 animate-spin" /><WrenchScrewdriverIcon v-else class="mr-1 size-4" />{{ orderSaving[equipment.id] ? 'Generando…' : 'Generar OT' }}</button><span v-else-if="maintenance[equipment.id]?.state === 'PROXIMO'" class="text-xs font-semibold text-warning-strong">Próximo service</span><span v-else class="text-xs text-ink-subtle">—</span><p v-if="orderErrors[equipment.id]" class="mt-1 text-xs text-danger-strong">{{ orderErrors[equipment.id] }}</p></td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="invalidRows.length" class="mt-3 text-sm font-semibold text-danger-strong">{{ invalidRows.length }} lectura{{ invalidRows.length === 1 ? '' : 's' }} para corregir. Las válidas se pueden guardar.</div>
      <template #footer><div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><PaginationBar :pagination="data.equipment.pagination" /><div class="text-right"><div class="mb-1 text-xs text-ink-muted">{{ readyRows.length }} lista{{ readyRows.length === 1 ? '' : 's' }} para guardar</div><button v-if="data.canRegister && data.equipment.items.length" type="submit" :disabled="saving || !readyRows.length" :class="primaryButton"><ArrowPathIcon class="mr-2 size-5" :class="saving ? 'animate-spin' : ''" />{{ saveButtonLabel }}</button></div></div></template>
    </PanelCard>
  </form>
</template>