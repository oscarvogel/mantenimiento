<script setup>
import { computed, nextTick, ref } from 'vue'
import { ArrowDownTrayIcon, BanknotesIcon, BeakerIcon, BookOpenIcon, CheckCircleIcon, CurrencyDollarIcon, ReceiptPercentIcon, XCircleIcon } from '@heroicons/vue/24/outline'
import PageHeading from './components/PageHeading.vue'
import PanelCard from './components/PanelCard.vue'
import AutocompleteSelect from './components/AutocompleteSelect.vue'
import LocalityCreateModal from './components/LocalityCreateModal.vue'
import { fieldClass, primaryButton, secondaryButton, dangerButton, nowLocal } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })

const trip = computed(() => props.data.trip ?? {})
const assignments = computed(() => props.data.assignments ?? [])
const stops = computed(() => props.data.stops ?? [])
const fuel = computed(() => props.data.fuel ?? [])
const expenses = computed(() => props.data.expenses ?? [])
const cashMovements = computed(() => props.data.cashMovements ?? [])
const otherEarnings = computed(() => props.data.otherEarnings ?? [])
const logbook = computed(() => props.data.logbook ?? [])
const settlement = computed(() => props.data.settlement ?? null)
const totals = computed(() => props.data.totals ?? {})
const legs = computed(() => props.data.legs ?? [])
const consumption = computed(() => props.data.consumption ?? {})
const options = computed(() => props.data.options ?? { expenseCategories: [], paymentMethods: [], currencies: [] })
const expenseConcepts = computed(() => options.value.expenseConcepts ?? [])
const earningConcepts = computed(() => options.value.earningConcepts ?? [])
const csrf = computed(() => props.data.csrf ?? { name: 'csrf_test_name', hash: '' })
const canRegisterCosts = computed(() => Boolean(props.data.canRegisterCosts))
const canAddLegs = computed(() => Boolean(props.data.canAddLegs))
const canClose = computed(() => Boolean(props.data.canClose))
const currencies = computed(() => options.value.currencies?.length ? options.value.currencies : [{ code: 'ARS', label: 'Peso argentino' }])
const customerOptions = computed(() => (options.value.customers ?? []).map((customer) => ({ value: customer.id, label: customer.label, hint: customer.code ? `Código ${customer.code}` : '' })))
const localities = computed(() => options.value.localities ?? [])
const extraLocalities = ref([])
const localityOptions = computed(() => [...localities.value, ...extraLocalities.value].map((locality) => ({ value: locality.id, label: locality.label })))
const localityName = (localityId) => [...localities.value, ...extraLocalities.value].find((locality) => String(locality.id) === String(localityId))?.name ?? ''
const newLegOriginId = ref('')
const newLegDestinationId = ref('')
const newLegCustomerId = ref('')
const localityModalOpen = ref(false)
const localityModalName = ref('')
const localityTargetField = ref('localidad_origen_id')
const openLocalityModal = (field, name) => {
  localityTargetField.value = field
  localityModalName.value = name
  localityModalOpen.value = true
}
const closeLocalityModal = () => {
  localityModalOpen.value = false
}
const applyCreatedLocality = (locality) => {
  if (!extraLocalities.value.some((item) => String(item.id) === String(locality.id))) {
    extraLocalities.value = [...extraLocalities.value, locality]
  }
  if (localityTargetField.value === 'localidad_destino_id') {
    newLegDestinationId.value = String(locality.id)
  } else {
    newLegOriginId.value = String(locality.id)
  }
  closeLocalityModal()
}
const newLegCurrency = ref('ARS')
const newLegExchangeRate = ref('1')
const setNewLegCurrency = () => { newLegExchangeRate.value = newLegCurrency.value === 'ARS' ? '1' : '' }
const entryDialog = ref(null)
const activeEntryModal = ref('')
const editingEntry = ref(null)
const openEntryModal = (type) => {
  editingEntry.value = null
  activeEntryModal.value = type
  nextTick(() => entryDialog.value?.showModal())
}
const openEditModal = (type, entry) => {
  editingEntry.value = entry
  activeEntryModal.value = type
  nextTick(() => entryDialog.value?.showModal())
}
const movementUrl = (type, id, remove = false) => {
  const segment = { fuel: 'combustible', expense: 'gastos', cash: 'caja', earning: 'haberes', logbook: 'diario-bordo' }[type]
  return `${props.data.links?.detail ?? ''}/${segment}/${id}${remove ? '/eliminar' : ''}`
}
const inputDateTime = (value) => String(value ?? '').replace(' ', 'T').slice(0, 16)
const confirmDelete = (event, label) => {
  if (!window.confirm(`¿Quitar ${label}? Se conservará en el historial de auditoría y dejará de incluirse en los totales.`)) event.preventDefault()
}
const handleEntryDialogClose = () => {
  activeEntryModal.value = ''
  editingEntry.value = null
}
const statusTone = {
  PLANIFICADO: 'bg-info-subtle text-info-strong',
  EN_CURSO: 'bg-success-subtle text-success-strong',
  FINALIZADO: 'bg-surface-muted text-ink-muted',
  CANCELADO: 'bg-danger-subtle text-danger-strong',
}
const cashTypeLabel = { CAJA_INICIAL: 'Caja inicial', ANTICIPO: 'Anticipo', RETIRO: 'Retiro', DEVOLUCION: 'Devolución' }

const conceptOptionLabel = (concept) => `${concept.label} · ${money(concept.amount, concept.currency)}${concept.unit === 'DIA' ? ' / día' : ''}`
const quantityLabel = (value) => Number(value ?? 0).toLocaleString('es-AR', { maximumFractionDigits: 2 })
const earningConceptLabel = (earning) => earning.concepto_catalogo || earning.concepto || 'Sin concepto'
const applyConceptToForm = (event) => {
  const form = event.target.closest('form')
  if (!form) return
  const list = form.dataset.conceptKind === 'expense' ? expenseConcepts.value : earningConcepts.value
  const conceptId = form.querySelector('select[name="concepto_id"]')?.value
  const concept = list.find((item) => String(item.id) === String(conceptId))
  const quantityInput = form.querySelector('input[name="cantidad"]')
  const amountInput = form.querySelector('input[name="importe"]')
  if (!amountInput) return
  if (!concept) {
    amountInput.readOnly = false
    return
  }
  const quantity = Number(String(quantityInput?.value ?? '1').replace(',', '.')) || 0
  amountInput.value = (Math.round(quantity * Number(concept.amount) * 100) / 100).toFixed(2)
  amountInput.readOnly = !concept.editable
  const freeConcept = form.querySelector('input[name="concepto"]')
  if (freeConcept) freeConcept.value = ''
  const currencySelect = form.querySelector('select[name="moneda"]')
  if (currencySelect && concept.currency) {
    currencySelect.value = concept.currency
  }
}

const paidByLabel = { EMPRESA: 'Empresa', CHOFER: 'Chofer', CAJA: 'Caja del chofer' }
const drivers = computed(() => assignments.value.filter((row) => row.rol === 'CHOFER'))
const driverOptions = computed(() => drivers.value
  .filter((driver) => driver.empleado_id)
  .map((driver) => ({ value: driver.empleado_id, label: driver.label })))
const entryDialogTitle = computed(() => {
  if (editingEntry.value) {
    if (activeEntryModal.value === 'fuel') return 'Editar carga de combustible'
    if (activeEntryModal.value === 'expense') return 'Editar gasto'
    if (activeEntryModal.value === 'earning') return 'Editar haber'
    if (activeEntryModal.value === 'logbook') return 'Editar etapa del diario de bordo'
    return 'Editar movimiento de caja'
  }
  if (activeEntryModal.value === 'fuel') return 'Cargar combustible'
  if (activeEntryModal.value === 'expense') return 'Cargar gasto'
  if (activeEntryModal.value === 'earning') return 'Cargar haber o estadía'
  if (activeEntryModal.value === 'logbook') return 'Cargar etapa del diario de bordo'
  return 'Movimiento de caja del chofer'
})
const today = computed(() => {
  const now = new Date()
  const local = new Date(now.getTime() - now.getTimezoneOffset() * 60_000)
  return local.toISOString().slice(0, 10)
})
const logbookDayOptions = computed(() => earningConcepts.value.filter((concept) => concept.unit === 'DIA'))
const parseDay = (value) => {
  const match = String(value ?? '').match(/^(\d{4})-(\d{2})-(\d{2})/)
  if (!match) return null
  const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]))
  return Number.isNaN(date.getTime()) ? null : date
}
const emptyLogbookDraft = () => ({
  tipo: 'CARGA',
  ubicacion: '',
  llegada: today.value,
  salida: today.value,
  horasLibres: 48,
  conceptoId: '',
  diasAjustados: '',
  motivoAjuste: '',
  observaciones: '',
  empleadoId: driverOptions.value[0]?.value ?? '',
})
const logbookDraft = ref(emptyLogbookDraft())
const openLogbookModal = (entry = null) => {
  editingEntry.value = entry
  activeEntryModal.value = 'logbook'
  logbookDraft.value = entry ? {
    tipo: entry.tipo,
    ubicacion: entry.ubicacion,
    llegada: String(entry.llegada ?? '').slice(0, 10),
    salida: entry.salida ? String(entry.salida).slice(0, 10) : '',
    horasLibres: entry.horas_libres ?? 48,
    conceptoId: entry.concepto_id ?? '',
    diasAjustados: entry.dias_ajustados ?? '',
    motivoAjuste: entry.motivo_ajuste ?? '',
    observaciones: entry.observaciones ?? '',
    empleadoId: entry.empleado_id ?? '',
  } : emptyLogbookDraft()
  nextTick(() => entryDialog.value?.showModal())
}
const logbookPresenceDays = computed(() => {
  const arrival = parseDay(logbookDraft.value.llegada)
  const departure = parseDay(logbookDraft.value.salida)
  if (!arrival || !departure || departure < arrival) return null
  return Math.round((departure - arrival) / 86_400_000)
})
const logbookCalculatedDays = computed(() => {
  const presence = logbookPresenceDays.value
  if (presence === null) return null
  const freeDays = Number(logbookDraft.value.horasLibres ?? 0) / 24
  return Math.round(Math.max(0, presence - freeDays) * 100) / 100
})
const logbookAdjustedDays = computed(() => {
  const raw = logbookDraft.value.diasAjustados
  if (raw === '' || raw === null || raw === undefined) return null
  const value = Number(String(raw).replace(',', '.'))
  return Number.isFinite(value) && value >= 0 ? value : null
})
const logbookLiquidatedDays = computed(() => logbookAdjustedDays.value ?? logbookCalculatedDays.value)
const logbookSelectedConcept = computed(() => logbookDayOptions.value.find((concept) => String(concept.id) === String(logbookDraft.value.conceptoId)) ?? null)
const logbookAmount = computed(() => {
  const concept = logbookSelectedConcept.value
  const days = logbookLiquidatedDays.value
  if (!concept || days === null) return null
  return Math.round(days * Number(concept.amount) * 100) / 100
})
const logbookNeedsReason = computed(() => {
  const adjusted = logbookAdjustedDays.value
  const calculated = logbookCalculatedDays.value
  if (adjusted === null) return false
  if (calculated === null) return true
  return Math.abs(adjusted - calculated) > 0.0001
})
const settlementNote = computed(() => {
  if (settlement.value) return null
  if (totals.value.multiple_drivers) return 'Hay más de un chofer. La liquidación se calcula cuando hay un chofer responsable.'
  if (totals.value.driver_percentage === null || totals.value.driver_percentage === undefined) return 'El chofer no tiene un porcentaje de liquidación definido.'
  return 'Cargá al menos un tramo para calcular la liquidación.'
})
const fmtDateTime = (value) => {
  const match = String(value ?? '').match(/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}))?/)
  if (!match) return 'Sin fecha'
  const date = `${match[3]}/${match[2]}/${match[1]}`
  return match[4] ? `${date} ${match[4]}:${match[5]}` : date
}
const fmtDate = (value) => {
  const match = String(value ?? '').match(/^(\d{4})-(\d{2})-(\d{2})/)
  return match ? `${match[3]}/${match[2]}/${match[1]}` : 'Sin fecha'
}
const number = (value) => value === null || value === undefined || value === '' ? '—' : Number(value).toLocaleString('es-AR')
const money = (value, currency = 'ARS') => `${currency} ${Number(value ?? 0).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
const moneyOrNoData = (value) => value === null || value === undefined ? 'Sin datos' : money(value)
const legLabel = (legId) => {
  const leg = legs.value.find((row) => String(row.id) === String(legId))
  return leg ? `Tramo ${leg.orden}: ${leg.origen} → ${leg.destino}` : 'Todo el viaje'
}
const financialNote = computed(() => {
  if (!totals.value.has_legs) return 'Cargá al menos un tramo para calcular facturación, comisión y margen.'
  if (totals.value.multiple_drivers) return 'Hay más de un chofer. Definí un chofer responsable para calcular la comisión.'
  if (totals.value.driver_percentage === null || totals.value.driver_percentage === undefined) {
    return 'El chofer no tiene un porcentaje de liquidación definido.'
  }
  return null
})
const liters = (value) => `${Number(value ?? 0).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} L`
const consumptionValue = computed(() => {
  if (consumption.value.liters_per_100 === null || consumption.value.liters_per_100 === undefined) return 'Sin datos suficientes'
  return `${Number(consumption.value.liters_per_100).toLocaleString('es-AR', { maximumFractionDigits: 1 })} L/100 km`
})
const consumptionSource = computed(() => consumption.value.source === 'ODOMETRO_VIAJE'
  ? 'sobre odómetro de salida y regreso'
  : (consumption.value.source === 'ODOMETRO_COMBUSTIBLE' ? 'estimado con odómetro de cargas' : 'faltan kilómetros o litros'))
const cashBalanceLabel = computed(() => {
  const balance = Number(totals.value.cash_balance_ars ?? 0)
  if (balance > 0) return 'Pendiente de rendir'
  if (balance < 0) return 'A favor del chofer'
  return 'Sin saldo'
})
const facts = computed(() => {
  const started = ['EN_CURSO', 'FINALIZADO'].includes(trip.value.estado)
  const finished = trip.value.estado === 'FINALIZADO'

  return [
  ['Viaje', `#${trip.value.numero}`],
  ['Estado', trip.value.estado_label],
  ['Sucursal de origen', trip.value.sucursal],
  [started ? 'Salida real' : 'Salida', fmtDate(trip.value.fecha_salida_real ?? trip.value.fecha_salida_planificada)],
  [finished ? 'Regreso real' : (started ? 'Regreso estimado' : 'Regreso'), trip.value.fecha_regreso_real || trip.value.fecha_regreso_planificada ? fmtDate(trip.value.fecha_regreso_real ?? trip.value.fecha_regreso_planificada) : 'Sin estimar'],
  ['Odómetro de salida', trip.value.km_salida === null ? null : `${number(trip.value.km_salida)} km`],
  ['Odómetro de regreso', trip.value.km_regreso === null ? null : `${number(trip.value.km_regreso)} km`],
  ['Observaciones', trip.value.observaciones],
  ['Motivo de cancelación', trip.value.motivo_cancelacion],
  ]
})
</script>

<template>
  <div>
    <PageHeading eyebrow="Viajes · Operativo" :title="`Viaje #${trip.numero}`" description="Seguimiento del recorrido, equipo, choferes, combustible, gastos y caja. Los retiros de caja no son costo del viaje." :back="{ href: data.backUrl ?? '/viajes/operativos', label: 'Volver a viajes operativos' }">
      <template #actions>
        <a v-if="trip.estado === 'FINALIZADO' && data.links?.summaryPdf" :href="data.links.summaryPdf" class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-primary px-3 py-2 text-sm font-semibold text-primary hover:bg-primary-subtle">
          <ArrowDownTrayIcon class="size-4" aria-hidden="true" />Descargar resumen PDF
        </a>
        <a v-if="trip.estado === 'FINALIZADO' && settlement && data.links?.driverSettlementPdf" :href="data.links.driverSettlementPdf" class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-success/40 bg-success-subtle px-3 py-2 text-sm font-semibold text-success-strong hover:bg-success/15">
          <ArrowDownTrayIcon class="size-4" aria-hidden="true" />Descargar liquidación para el chofer
        </a>
        <span class="inline-flex rounded-full px-3 py-1.5 text-sm font-semibold" :class="statusTone[trip.estado] ?? 'bg-surface-muted text-ink-muted'">{{ trip.estado_label }}</span>
      </template>
    </PageHeading>

    <section v-if="canRegisterCosts" aria-label="Cargar movimientos del viaje" class="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
      <button type="button" aria-haspopup="dialog" @click="openEntryModal('fuel')" class="group flex min-h-16 items-center gap-3 rounded-xl border border-border bg-surface-raised px-4 py-3 text-left shadow-card transition hover:border-primary hover:bg-primary-subtle focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary">
        <span class="grid size-10 shrink-0 place-items-center rounded-lg bg-primary-subtle text-primary"><BeakerIcon class="size-5" aria-hidden="true" /></span>
        <span><span class="block text-sm font-bold text-ink">Cargar combustible</span><span class="mt-0.5 block text-xs text-ink-muted">Registrar una carga</span></span>
      </button>
      <button type="button" aria-haspopup="dialog" @click="openEntryModal('expense')" class="group flex min-h-16 items-center gap-3 rounded-xl border border-border bg-surface-raised px-4 py-3 text-left shadow-card transition hover:border-primary hover:bg-primary-subtle focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary">
        <span class="grid size-10 shrink-0 place-items-center rounded-lg bg-primary-subtle text-primary"><ReceiptPercentIcon class="size-5" aria-hidden="true" /></span>
        <span><span class="block text-sm font-bold text-ink">Cargar gasto</span><span class="mt-0.5 block text-xs text-ink-muted">Peajes, viáticos y otros</span></span>
      </button>
      <button v-if="driverOptions.length > 0" type="button" aria-haspopup="dialog" @click="openEntryModal('earning')" class="group flex min-h-16 items-center gap-3 rounded-xl border border-border bg-surface-raised px-4 py-3 text-left shadow-card transition hover:border-primary hover:bg-primary-subtle focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary">
        <span class="grid size-10 shrink-0 place-items-center rounded-lg bg-primary-subtle text-primary"><CurrencyDollarIcon class="size-5" aria-hidden="true" /></span>
        <span><span class="block text-sm font-bold text-ink">Cargar haber</span><span class="mt-0.5 block text-xs text-ink-muted">Estadías y adicionales</span></span>
      </button>
      <button v-if="driverOptions.length > 0 && logbookDayOptions.length > 0" type="button" aria-haspopup="dialog" @click="openLogbookModal()" class="group flex min-h-16 items-center gap-3 rounded-xl border border-border bg-surface-raised px-4 py-3 text-left shadow-card transition hover:border-primary hover:bg-primary-subtle focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary">
        <span class="grid size-10 shrink-0 place-items-center rounded-lg bg-primary-subtle text-primary"><BookOpenIcon class="size-5" aria-hidden="true" /></span>
        <span><span class="block text-sm font-bold text-ink">Diario de bordo</span><span class="mt-0.5 block text-xs text-ink-muted">Estadías por etapa</span></span>
      </button>
      <button type="button" aria-haspopup="dialog" @click="openEntryModal('cash')" class="group flex min-h-16 items-center gap-3 rounded-xl border border-border bg-surface-raised px-4 py-3 text-left shadow-card transition hover:border-primary hover:bg-primary-subtle focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary">
        <span class="grid size-10 shrink-0 place-items-center rounded-lg bg-primary-subtle text-primary"><BanknotesIcon class="size-5" aria-hidden="true" /></span>
        <span><span class="block text-sm font-bold text-ink">Caja del chofer</span><span class="mt-0.5 block text-xs text-ink-muted">Anticipo, retiro o devolución</span></span>
      </button>
    </section>

    <PanelCard title="Datos generales" class="mb-5">
      <dl class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <div v-for="([label, value]) in facts" :key="label" class="min-w-0 rounded-lg border border-border-subtle bg-surface p-3">
          <dt class="text-xs font-semibold text-ink-muted">{{ label }}</dt>
          <dd class="mt-1 break-words text-sm font-semibold text-ink">{{ value === null || value === undefined || value === '' ? '—' : value }}</dd>
        </div>
      </dl>
    </PanelCard>

    <section aria-label="Resumen económico" class="mb-5">
      <div class="grid grid-cols-2 gap-3 lg:grid-cols-6">
        <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card">
          <p class="text-xs font-semibold text-ink-muted">Facturado</p>
          <p class="mt-2 text-xl font-bold text-ink">{{ moneyOrNoData(totals.invoice_ars) }}</p>
          <p class="mt-1 text-xs text-ink-muted">{{ legs.length }} tramo{{ legs.length === 1 ? '' : 's' }}</p>
        </article>
        <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card">
          <p class="text-xs font-semibold text-ink-muted">Comisión chofer</p>
          <p class="mt-2 text-xl font-bold text-ink">{{ moneyOrNoData(totals.commission_ars) }}</p>
          <p class="mt-1 text-xs text-ink-muted">{{ totals.driver_percentage === null || totals.driver_percentage === undefined ? 'Sin %' : `${totals.driver_percentage}%` }}</p>
        </article>
        <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card">
          <p class="text-xs font-semibold text-ink-muted">Combustible</p>
          <p class="mt-2 text-xl font-bold text-ink">{{ money(totals.fuel_amount_ars) }}</p>
          <p class="mt-1 text-xs text-ink-muted">{{ liters(totals.fuel_liters) }}</p>
        </article>
        <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card">
          <p class="text-xs font-semibold text-ink-muted">Gastos</p>
          <p class="mt-2 text-xl font-bold text-ink">{{ money(totals.expense_amount_ars) }}</p>
          <p class="mt-1 text-xs text-ink-muted">del viaje</p>
        </article>
        <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card">
          <p class="text-xs font-semibold text-ink-muted">Costos sin asignar</p>
          <p class="mt-2 text-xl font-bold text-ink">{{ money(totals.unassigned_costs_ars) }}</p>
          <p class="mt-1 text-xs text-ink-muted">no imputados a un tramo</p>
        </article>
        <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card">
          <p class="text-xs font-semibold text-ink-muted">Margen del viaje</p>
          <p class="mt-2 text-xl font-bold" :class="totals.margin_ars === null || totals.margin_ars === undefined ? 'text-ink-muted' : (totals.margin_ars < 0 ? 'text-danger-strong' : 'text-success-strong')">{{ moneyOrNoData(totals.margin_ars) }}</p>
          <p class="mt-1 text-xs text-ink-muted">{{ totals.margin_pct === null || totals.margin_pct === undefined ? 'Sin datos suficientes' : `${totals.margin_pct}%` }}</p>
        </article>
      </div>
      <p v-if="financialNote" class="mt-3 rounded-lg border border-info/30 bg-info-subtle px-3 py-2 text-sm text-info-strong">{{ financialNote }}</p>
    </section>

    <PanelCard title="Tramos" :count="legs.length" class="mb-5" allow-overflow>
      <p v-if="legs.length === 0" class="mb-4 text-sm text-ink-muted">Cargá el primer tramo con origen, destino y los dos fletes: facturación y liquidación.</p>
      <div v-else class="mb-4 overflow-x-auto">
        <table class="w-full min-w-[64rem] text-left text-sm">
          <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted">
            <tr>
              <th class="px-3 py-2">#</th>
              <th class="px-3 py-2">Recorrido</th>
              <th class="px-3 py-2 text-right">Toneladas</th>
              <th class="px-3 py-2 text-right">Km</th>
              <th class="px-3 py-2 text-right">Flete facturación (ARS)</th>
              <th class="px-3 py-2 text-right">Flete liquidación (ARS)</th>
              <th class="px-3 py-2 text-right">Comisión</th>
              <th class="px-3 py-2 text-right">Costos asignados</th>
              <th class="px-3 py-2 text-right">Margen</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border-subtle">
            <tr v-for="leg in legs" :key="leg.id">
              <td class="px-3 py-2 text-ink-muted">{{ leg.orden }}</td>
              <td class="px-3 py-2">
                <p class="font-semibold text-ink">{{ leg.origen }} → {{ leg.destino }}</p>
                <p v-if="leg.cliente" class="mt-0.5 text-xs text-ink-muted"><span v-if="leg.cliente_codigo">{{ leg.cliente_codigo }} · </span>{{ leg.cliente }}</p>
              </td>
              <td class="px-3 py-2 text-right text-ink-muted">{{ leg.toneladas === null ? '—' : number(leg.toneladas) }}</td>
              <td class="px-3 py-2 text-right text-ink-muted">{{ leg.km === null ? '—' : number(leg.km) }}</td>
              <td class="px-3 py-2 text-right text-ink">{{ money(leg.flete_facturacion_ars) }}</td>
              <td class="px-3 py-2 text-right text-ink">{{ money(leg.flete_liquidacion_ars) }}</td>
              <td class="px-3 py-2 text-right text-ink-muted">{{ moneyOrNoData(leg.commission_ars) }}</td>
              <td class="px-3 py-2 text-right text-ink-muted">{{ money(leg.assigned_costs_ars) }}</td>
              <td class="px-3 py-2 text-right font-semibold" :class="leg.margin_ars === null ? 'text-ink-muted' : (leg.margin_ars < 0 ? 'text-danger-strong' : 'text-success-strong')">{{ moneyOrNoData(leg.margin_ars) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <form v-if="canAddLegs" method="post" :action="data.links?.legs" class="rounded-xl border border-border-subtle bg-surface p-4">
        <input type="hidden" :name="csrf.name" :value="csrf.hash" />
        <h3 class="text-sm font-bold text-ink">Agregar tramo</h3>
        <p class="mt-1 text-xs text-ink-muted">El flete de liquidación es la base para el porcentaje del chofer. El de facturación es el ingreso del viaje.</p>
        <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
          <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Localidad de origen</span><AutocompleteSelect v-model="newLegOriginId" name="localidad_origen_id" :options="localityOptions" :search-url="data.links?.localities" placeholder="Buscá la localidad" required allow-create @create="openLocalityModal('localidad_origen_id', $event)" /></label>
          <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Localidad de destino</span><AutocompleteSelect v-model="newLegDestinationId" name="localidad_destino_id" :options="localityOptions" :search-url="data.links?.localities" placeholder="Buscá la localidad" required allow-create @create="openLocalityModal('localidad_destino_id', $event)" /></label>
          <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Cliente</span><AutocompleteSelect v-model="newLegCustomerId" name="cliente_id" :options="customerOptions" placeholder="Elegí el cliente" required /></label>
          <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Toneladas</span><input type="number" name="toneladas" min="0" step="0.01" :class="fieldClass" /></label>
        </div>
        <div class="mt-3 grid gap-3 md:grid-cols-3">
          <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Km</span><input type="number" name="km" min="0" step="0.01" :class="fieldClass" /></label>
          <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Moneda</span><select v-model="newLegCurrency" name="moneda" :class="fieldClass" required @change="setNewLegCurrency"><option v-for="currency in currencies" :key="currency.code" :value="currency.code">{{ currency.code }} · {{ currency.label }}</option></select></label>
          <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Cotización a ARS</span><input v-model="newLegExchangeRate" type="number" name="cotizacion" min="0.000001" step="0.000001" :required="newLegCurrency !== 'ARS'" :disabled="newLegCurrency === 'ARS'" :class="fieldClass" /><span class="mt-1 block text-xs text-ink-muted">{{ newLegCurrency === 'ARS' ? 'Para ARS se usa cotización 1.' : `Ingresá cuántos ARS vale 1 ${newLegCurrency}.` }}</span></label>
        </div>
        <div class="mt-3 grid gap-3 md:grid-cols-2">
          <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Flete de facturación ({{ newLegCurrency }})</span><input type="number" name="flete_facturacion" min="0" step="0.01" required :class="fieldClass" /></label>
          <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Flete de liquidación ({{ newLegCurrency }})</span><input type="number" name="flete_liquidacion" min="0" step="0.01" required :class="fieldClass" /></label>
        </div>
        <label class="mt-3 block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Observaciones</span><input type="text" name="observaciones" maxlength="500" :class="fieldClass" /></label>
        <button type="submit" :class="[primaryButton, 'mt-3']">Agregar tramo</button>
      </form>
    </PanelCard>

    <section aria-label="Resumen de costos" class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-6">
      <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card">
        <p class="text-xs font-semibold text-ink-muted">Combustible</p>
        <p class="mt-2 text-xl font-bold text-ink">{{ liters(totals.fuel_liters) }}</p>
        <p class="mt-1 text-xs text-ink-muted">{{ money(totals.fuel_amount_ars) }}</p>
      </article>
      <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card">
        <p class="text-xs font-semibold text-ink-muted">Gastos</p>
        <p class="mt-2 text-xl font-bold text-ink">{{ money(totals.expense_amount_ars) }}</p>
      </article>
      <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card">
        <p class="text-xs font-semibold text-ink-muted">Combustible + gastos</p>
        <p class="mt-2 text-xl font-bold text-ink">{{ money(totals.direct_cost_ars) }}</p>
        <p class="mt-1 text-xs text-ink-muted">sin comisión</p>
      </article>
      <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card">
        <p class="text-xs font-semibold text-ink-muted">Consumo</p>
        <p class="mt-2 text-xl font-bold text-ink">{{ consumptionValue }}</p>
        <p class="mt-1 text-xs text-ink-muted">{{ consumptionSource }}</p>
      </article>
      <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card">
        <p class="text-xs font-semibold text-ink-muted">Caja entregada</p>
        <p class="mt-2 text-xl font-bold text-ink">{{ money(totals.cash_delivered_ars) }}</p>
        <p class="mt-1 text-xs text-ink-muted">devuelto {{ money(totals.cash_returned_ars) }}</p>
      </article>
      <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card">
        <p class="text-xs font-semibold text-ink-muted">Saldo de caja</p>
        <p class="mt-2 text-xl font-bold" :class="Number(totals.cash_balance_ars) === 0 ? 'text-ink' : 'text-warning-strong'">{{ money(totals.cash_balance_ars) }}</p>
        <p class="mt-1 text-xs text-ink-muted">{{ cashBalanceLabel }}</p>
      </article>
    </section>

    <PanelCard title="Liquidación del chofer" class="mb-5" allow-overflow>
      <p v-if="settlementNote" class="rounded-lg border border-info/30 bg-info-subtle px-3 py-2 text-sm text-info-strong">{{ settlementNote }}</p>
      <div class="grid gap-5" :class="settlementNote ? '' : 'xl:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]'">
        <div v-if="!settlementNote" class="rounded-xl border border-border-subtle bg-surface p-4">
          <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Chofer responsable</p>
          <p class="mt-1 text-lg font-bold text-ink">{{ settlement.driver || 'Sin dato' }}</p>
          <dl class="mt-4 space-y-2 text-sm">
            <div class="flex items-center justify-between gap-3"><dt class="text-ink-muted">Comisión</dt><dd class="font-semibold text-ink">{{ money(settlement.commission_ars) }}</dd></div>
            <div class="flex items-center justify-between gap-3"><dt class="text-ink-muted">Otros haberes</dt><dd class="font-semibold text-ink">{{ money(settlement.other_earnings_ars) }}</dd></div>
            <div class="flex items-center justify-between gap-3"><dt class="text-ink-muted">Gastos pagados por el chofer</dt><dd class="font-semibold text-ink">{{ money(settlement.driver_expenses_ars) }}</dd></div>
            <div class="flex items-center justify-between gap-3"><dt class="text-ink-muted">Gastos pagados con caja</dt><dd class="font-semibold text-ink">{{ money(settlement.cash_expenses_ars) }}</dd></div>
            <div class="flex items-center justify-between gap-3"><dt class="text-ink-muted">Caja inicial, anticipos y retiros</dt><dd class="font-semibold text-danger-strong">- {{ money(settlement.advances_ars) }}</dd></div>
            <div class="flex items-center justify-between gap-3"><dt class="text-ink-muted">Devoluciones</dt><dd class="font-semibold text-ink">{{ money(settlement.returns_ars) }}</dd></div>
            <div class="flex items-center justify-between gap-3 border-t border-border-subtle pt-2"><dt class="font-semibold text-ink">Saldo de cuenta corriente</dt><dd class="font-bold" :class="settlement.account_balance_ars < 0 ? 'text-danger-strong' : 'text-success-strong'">{{ money(settlement.account_balance_ars) }}</dd></div>
          </dl>
          <div class="mt-4 flex items-center justify-between gap-3 rounded-lg bg-primary/10 px-3 py-3">
            <p class="text-sm font-bold text-ink">Total a liquidar</p>
            <p class="text-xl font-extrabold" :class="settlement.total_ars < 0 ? 'text-danger-strong' : 'text-primary'">{{ money(settlement.total_ars) }}</p>
          </div>
          <div v-if="otherEarnings.length" class="mt-3 rounded-lg border border-border-subtle bg-surface p-3">
            <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Detalle de haberes</p>
            <ul class="mt-2 space-y-1 text-xs">
              <li v-for="earning in otherEarnings" :key="`settlement-${earning.id}`" class="flex items-start justify-between gap-3">
                <span class="text-ink-muted">{{ earningConceptLabel(earning) }}<span v-if="earning.generado_por_diario"> · diario de bordo</span><span v-if="earning.cantidad && earning.cantidad !== 1"> · {{ quantityLabel(earning.cantidad) }} × {{ money(earning.importe_unitario, earning.moneda) }}</span></span>
                <span class="shrink-0 font-semibold text-ink">{{ money(earning.importe_ars) }}</span>
              </li>
            </ul>
          </div>
          <p class="mt-2 text-xs text-ink-muted">Los anticipos no son costo del viaje. El saldo negativo queda a favor de la empresa.</p>
        </div>

        <div>
          <p v-if="otherEarnings.length === 0" class="text-sm text-ink-muted">Todavía no hay otros haberes cargados. Usá “Diario de bordo” para calcular una estadía o “Cargar haber” para un adicional.</p>
          <div v-else class="overflow-x-auto">
            <table class="w-full min-w-[42rem] text-left text-sm">
              <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted"><tr><th class="px-3 py-2">Fecha</th><th class="px-3 py-2">Concepto</th><th class="px-3 py-2">Chofer</th><th class="px-3 py-2 text-right">ARS</th><th v-if="canRegisterCosts" class="px-3 py-2">Acciones</th></tr></thead>
              <tbody class="divide-y divide-border-subtle">
                <tr v-for="earning in otherEarnings" :key="earning.id"><td class="whitespace-nowrap px-3 py-2 text-ink-muted">{{ fmtDateTime(earning.fecha) }}</td><td class="px-3 py-2 text-ink">{{ earningConceptLabel(earning) }}<span v-if="earning.generado_por_diario" class="ml-2 rounded bg-info-subtle px-1.5 py-0.5 text-[10px] font-bold uppercase text-info-strong">Diario</span><span v-if="earning.importe_unitario !== null && earning.importe_unitario !== undefined && earning.cantidad" class="block text-xs text-ink-muted">{{ quantityLabel(earning.cantidad) }} × {{ money(earning.importe_unitario, earning.moneda) }}</span></td><td class="px-3 py-2 text-ink-muted">{{ earning.empleado || '—' }}</td><td class="px-3 py-2 text-right font-semibold text-ink">{{ money(earning.importe_ars) }}</td><td v-if="canRegisterCosts" class="whitespace-nowrap px-3 py-2"><span v-if="earning.generado_por_diario" class="text-xs text-ink-muted">Se corrige en el diario de bordo</span><div v-else class="flex gap-2"><button type="button" :class="secondaryButton" @click="openEditModal('earning', earning)">Editar</button><form method="post" :action="movementUrl('earning', earning.id, true)" @submit="confirmDelete($event, 'este haber')"><input type="hidden" :name="csrf.name" :value="csrf.hash" /><button type="submit" :class="dangerButton">Quitar</button></form></div></td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </PanelCard>

    <PanelCard title="Diario de bordo" :count="logbook.length" class="mb-5" allow-overflow>
      <p class="text-sm text-ink-muted">Cargá llegada y salida de cada etapa. El sistema calcula los días por diferencia de fechas y descuenta la franquicia de esa etapa (48 h por defecto). La estadía se genera automáticamente como haber del chofer.</p>
      <p v-if="logbookDayOptions.length === 0" class="mt-3 rounded-lg border border-warning/30 bg-warning-subtle px-3 py-2 text-sm text-warning-strong">No hay conceptos de haber liquidados por día. Cargalos en Maestros de viajes para calcular estadías.</p>
      <p v-else-if="logbook.length === 0" class="mt-3 text-sm text-ink-muted">Todavía no hay etapas cargadas. Usá “Diario de bordo” para registrar carga, fronteras y descarga.</p>
      <div v-else class="mt-4 overflow-x-auto">
        <table class="w-full min-w-[64rem] text-left text-sm">
          <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted">
            <tr>
              <th class="px-3 py-2">#</th>
              <th class="px-3 py-2">Etapa</th>
              <th class="px-3 py-2">Llegada</th>
              <th class="px-3 py-2">Salida</th>
              <th class="px-3 py-2 text-right">Permanencia</th>
              <th class="px-3 py-2 text-right">Franquicia</th>
              <th class="px-3 py-2 text-right">Días calculados</th>
              <th class="px-3 py-2 text-right">Días a liquidar</th>
              <th class="px-3 py-2">Concepto</th>
              <th class="px-3 py-2 text-right">Importe</th>
              <th v-if="canRegisterCosts" class="px-3 py-2">Acciones</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border-subtle">
            <tr v-for="entry in logbook" :key="entry.id">
              <td class="px-3 py-2 text-ink-muted">{{ entry.orden }}</td>
              <td class="px-3 py-2">
                <p class="font-semibold text-ink">{{ entry.tipo_label }}</p>
                <p class="text-xs text-ink-muted">{{ entry.ubicacion }}</p>
                <p v-if="entry.empleado" class="text-xs text-ink-muted">{{ entry.empleado }}</p>
              </td>
              <td class="whitespace-nowrap px-3 py-2 text-ink-muted">{{ fmtDate(entry.llegada) }}</td>
              <td class="whitespace-nowrap px-3 py-2 text-ink-muted">{{ entry.salida ? fmtDate(entry.salida) : 'En espera' }}</td>
              <td class="px-3 py-2 text-right text-ink-muted">{{ entry.dias_permanencia === null ? '—' : `${entry.dias_permanencia} días` }}</td>
              <td class="px-3 py-2 text-right text-ink-muted">{{ quantityLabel(entry.horas_libres / 24) }} días</td>
              <td class="px-3 py-2 text-right text-ink-muted">{{ entry.dias_calculados === null ? '—' : quantityLabel(entry.dias_calculados) }}</td>
              <td class="px-3 py-2 text-right font-semibold text-ink">{{ entry.dias_liquidados === null ? '—' : quantityLabel(entry.dias_liquidados) }}<span v-if="entry.dias_ajustados !== null" class="block text-xs font-normal text-warning-strong">Corregido: {{ entry.motivo_ajuste || 'sin motivo' }}</span></td>
              <td class="px-3 py-2 text-ink">{{ entry.concepto || '—' }}<span v-if="entry.importe_unitario !== null" class="block text-xs text-ink-muted">{{ money(entry.importe_unitario, entry.moneda ?? 'ARS') }} / día</span></td>
              <td class="px-3 py-2 text-right font-semibold text-ink">{{ entry.importe_ars === null ? '—' : money(entry.importe_ars) }}</td>
              <td v-if="canRegisterCosts" class="whitespace-nowrap px-3 py-2">
                <div class="flex gap-2">
                  <button type="button" :class="secondaryButton" @click="openLogbookModal(entry)">Editar</button>
                  <form method="post" :action="movementUrl('logbook', entry.id, true)" @submit="confirmDelete($event, 'esta etapa del diario')"><input type="hidden" :name="csrf.name" :value="csrf.hash" /><button type="submit" :class="dangerButton">Quitar</button></form>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </PanelCard>

    <div class="mb-5 grid gap-5 xl:grid-cols-2">
      <PanelCard title="Equipo y choferes" :count="assignments.length">
        <p v-if="assignments.length === 0" class="text-sm text-ink-muted">El viaje no tiene asignaciones registradas.</p>
        <ul v-else class="divide-y divide-border-subtle">
          <li v-for="(assignment, index) in assignments" :key="`${assignment.rol}-${index}`" class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
            <div>
              <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ assignment.rol_label }}</p>
              <p class="mt-0.5 text-sm font-semibold text-ink">{{ assignment.label || 'Sin dato' }}</p>
              <p v-if="assignment.porcentaje_liquidacion !== null && assignment.porcentaje_liquidacion !== undefined" class="mt-0.5 text-xs font-semibold text-primary">Liquidación: {{ Number(assignment.porcentaje_liquidacion).toLocaleString('es-AR', { maximumFractionDigits: 2 }) }}%</p>
              <p v-else-if="assignment.master_percentage !== null && assignment.master_percentage !== undefined" class="mt-0.5 text-xs font-semibold text-warning-strong">Liquidación: {{ Number(assignment.master_percentage).toLocaleString('es-AR', { maximumFractionDigits: 2 }) }}% del maestro · guardá para fijarlo en el viaje</p>
              <form v-if="canRegisterCosts && assignment.rol === 'CHOFER' && assignment.empleado_id" method="post" :action="data.links?.percentage" class="mt-2 flex flex-wrap items-end gap-2">
                <input type="hidden" :name="csrf.name" :value="csrf.hash" />
                <input type="hidden" name="empleado_id" :value="assignment.empleado_id" />
                <label class="block">
                  <span class="mb-1 block text-xs font-semibold text-ink-muted">% de liquidación del viaje</span>
                  <input type="number" name="porcentaje" min="0" max="100" step="0.01" :value="assignment.porcentaje_liquidacion ?? assignment.master_percentage ?? ''" :class="fieldClass" />
                </label>
                <button type="submit" :class="secondaryButton">Guardar %</button>
              </form>
            </div>
            <p class="text-xs text-ink-muted">{{ fmtDate(assignment.desde) }} — {{ assignment.hasta ? fmtDate(assignment.hasta) : 'sin fin' }}</p>
          </li>
        </ul>
      </PanelCard>

      <PanelCard title="Acciones" v-if="data.canStart || data.canCancel || canClose">
        <form v-if="data.canStart" method="post" :action="`${data.links?.detail ?? ''}/iniciar`" class="mb-5 rounded-xl border border-border-subtle bg-surface p-4">
          <input type="hidden" :name="csrf.name" :value="csrf.hash" />
          <h3 class="flex items-center gap-2 text-sm font-bold text-ink"><CheckCircleIcon class="size-4 text-success" aria-hidden="true" />Registrar salida real</h3>
          <p class="mt-1 text-xs text-ink-muted">Al iniciar, el viaje pasa a En curso. Si no indicás la fecha, se usa la fecha actual.</p>
          <div class="mt-3 grid gap-3 sm:grid-cols-2">
            <label class="block">
              <span class="mb-1.5 block text-sm font-semibold text-ink">Fecha de salida</span>
              <input type="date" name="fecha_salida_real" :value="today" :class="fieldClass" />
            </label>
            <label class="block">
              <span class="mb-1.5 block text-sm font-semibold text-ink">Odómetro de salida</span>
              <input type="number" name="km_salida" min="0" step="1" required :value="trip.km_salida ?? ''" :class="fieldClass" />
            </label>
          </div>
          <button type="submit" :class="[primaryButton, 'mt-3']">Iniciar viaje</button>
        </form>

        <form v-if="canClose" method="post" :action="data.links?.finish" class="mb-5 rounded-xl border border-border-subtle bg-surface p-4">
          <input type="hidden" :name="csrf.name" :value="csrf.hash" />
          <h3 class="flex items-center gap-2 text-sm font-bold text-ink"><CheckCircleIcon class="size-4 text-success" aria-hidden="true" />Cerrar viaje</h3>
          <p class="mt-1 text-xs text-ink-muted">Al cerrar, el viaje pasa a Finalizado y se genera la lectura del equipo con el km de regreso.</p>
          <div class="mt-3 grid gap-3 sm:grid-cols-2">
            <label class="block">
              <span class="mb-1.5 block text-sm font-semibold text-ink">Fecha de regreso</span>
              <input type="date" name="fecha_regreso_real" :value="today" :class="fieldClass" />
            </label>
            <label class="block">
              <span class="mb-1.5 block text-sm font-semibold text-ink">Odómetro de regreso</span>
              <input type="number" name="km_regreso" min="0" step="1" required :min="trip.km_salida ?? 0" :class="fieldClass" />
            </label>
          </div>
          <div v-if="!totals.has_legs || !totals.fuel_amount_ars || !totals.expense_amount_ars" class="mt-3 rounded-lg border border-warning/30 bg-warning-subtle px-3 py-2 text-xs text-warning-strong">
            <p v-if="!totals.has_legs">No hay tramos cargados.</p>
            <p v-if="!totals.fuel_amount_ars">No hay combustible cargado.</p>
            <p v-if="!totals.expense_amount_ars">No hay gastos cargados.</p>
            <p class="mt-1">Podés cerrar igual, pero revisá que corresponda.</p>
          </div>
          <button type="submit" :class="[primaryButton, 'mt-3']">Cerrar y generar lectura</button>
        </form>

        <form v-if="data.canCancel" method="post" :action="`${data.links?.detail ?? ''}/cancelar`" class="rounded-xl border border-danger/30 bg-danger-subtle/40 p-4">
          <input type="hidden" :name="csrf.name" :value="csrf.hash" />
          <h3 class="flex items-center gap-2 text-sm font-bold text-danger-strong"><XCircleIcon class="size-4" aria-hidden="true" />Cancelar viaje</h3>
          <p class="mt-1 text-xs text-ink-muted">El viaje cancelado conserva su recorrido y asignaciones; el motivo queda registrado.</p>
          <label class="mt-3 block">
            <span class="mb-1.5 block text-sm font-semibold text-ink">Motivo de cancelación</span>
            <textarea name="motivo_cancelacion" rows="3" maxlength="500" required :class="fieldClass"></textarea>
          </label>
          <button type="submit" :class="[dangerButton, 'mt-3']">Cancelar viaje</button>
        </form>
      </PanelCard>
    </div>

    <div class="space-y-5">
      <PanelCard title="Combustible" :count="fuel.length">
        <p v-if="!canRegisterCosts" class="mb-4 rounded-lg bg-surface p-3 text-sm text-ink-muted">El viaje está cancelado o finalizado; sus costos quedan de solo lectura.</p>
        <p v-if="fuel.length === 0" class="text-sm text-ink-muted">Todavía no hay cargas de combustible.</p>
        <div v-else class="overflow-x-auto">
          <table class="w-full min-w-[52rem] text-left text-sm">
            <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted"><tr><th class="px-3 py-2">Fecha</th><th class="px-3 py-2 text-right">Litros</th><th class="px-3 py-2 text-right">Importe</th><th class="px-3 py-2 text-right">ARS</th><th class="px-3 py-2 text-right">Odómetro</th><th class="px-3 py-2">Tramo</th><th class="px-3 py-2">Estación</th><th class="px-3 py-2">Comprobante</th><th class="px-3 py-2">Observaciones</th><th v-if="canRegisterCosts" class="px-3 py-2">Acciones</th></tr></thead>
            <tbody class="divide-y divide-border-subtle">
              <tr v-for="load in fuel" :key="load.id"><td class="whitespace-nowrap px-3 py-2 text-ink-muted">{{ fmtDateTime(load.fecha) }}</td><td class="px-3 py-2 text-right text-ink">{{ number(load.litros) }}</td><td class="px-3 py-2 text-right text-ink-muted">{{ money(load.importe, load.moneda) }}</td><td class="px-3 py-2 text-right font-semibold text-ink">{{ money(load.importe_ars) }}</td><td class="px-3 py-2 text-right text-ink-muted">{{ load.kilometraje === null ? '—' : number(load.kilometraje) }}</td><td class="px-3 py-2 text-ink-muted">{{ legLabel(load.tramo_id) }}</td><td class="px-3 py-2 text-ink-muted">{{ load.estacion || '—' }}</td><td class="px-3 py-2 text-ink-muted">{{ load.comprobante || '—' }}</td><td class="px-3 py-2 text-ink-muted">{{ load.observaciones || '—' }}</td><td v-if="canRegisterCosts" class="whitespace-nowrap px-3 py-2"><div class="flex gap-2"><button type="button" :class="secondaryButton" @click="openEditModal('fuel', load)">Editar</button><form method="post" :action="movementUrl('fuel', load.id, true)" @submit="confirmDelete($event, 'esta carga de combustible')"><input type="hidden" :name="csrf.name" :value="csrf.hash" /><button type="submit" :class="dangerButton">Quitar</button></form></div></td></tr>
            </tbody>
          </table>
        </div>
      </PanelCard>

      <PanelCard title="Gastos" :count="expenses.length">
        <p v-if="!canRegisterCosts" class="mb-4 rounded-lg bg-surface p-3 text-sm text-ink-muted">El viaje está cancelado o finalizado; sus costos quedan de solo lectura.</p>
        <p v-if="expenses.length === 0" class="text-sm text-ink-muted">Todavía no hay gastos cargados.</p>
        <div v-else class="overflow-x-auto">
          <table class="w-full min-w-[64rem] text-left text-sm">
            <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted"><tr><th class="px-3 py-2">Fecha</th><th class="px-3 py-2">Categoría</th><th class="px-3 py-2">Concepto</th><th class="px-3 py-2 text-right">Cant.</th><th class="px-3 py-2 text-right">Importe</th><th class="px-3 py-2 text-right">ARS</th><th class="px-3 py-2">Medio</th><th class="px-3 py-2">Tramo</th><th class="px-3 py-2">Pagado por</th><th class="px-3 py-2">Comprobante</th><th class="px-3 py-2">Observaciones</th><th v-if="canRegisterCosts" class="px-3 py-2">Acciones</th></tr></thead>
            <tbody class="divide-y divide-border-subtle">
              <tr v-for="expense in expenses" :key="expense.id"><td class="whitespace-nowrap px-3 py-2 text-ink-muted">{{ fmtDateTime(expense.fecha) }}</td><td class="px-3 py-2 text-ink">{{ expense.categoria || 'Sin categoría' }}</td><td class="px-3 py-2 text-ink">{{ expense.concepto_catalogo || '—' }}</td><td class="px-3 py-2 text-right text-ink-muted">{{ quantityLabel(expense.cantidad) }}</td><td class="px-3 py-2 text-right text-ink-muted">{{ money(expense.importe, expense.moneda) }}</td><td class="px-3 py-2 text-right font-semibold text-ink">{{ money(expense.importe_ars) }}</td><td class="px-3 py-2 text-ink-muted">{{ expense.medio_pago || '—' }}</td><td class="px-3 py-2 text-ink-muted">{{ legLabel(expense.tramo_id) }}</td><td class="px-3 py-2 text-ink-muted">{{ paidByLabel[expense.pagado_por] ?? 'Empresa' }}</td><td class="px-3 py-2 text-ink-muted">{{ expense.comprobante || '—' }}</td><td class="px-3 py-2 text-ink-muted">{{ expense.observaciones || '—' }}</td><td v-if="canRegisterCosts" class="whitespace-nowrap px-3 py-2"><div class="flex gap-2"><button type="button" :class="secondaryButton" @click="openEditModal('expense', expense)">Editar</button><form method="post" :action="movementUrl('expense', expense.id, true)" @submit="confirmDelete($event, 'este gasto')"><input type="hidden" :name="csrf.name" :value="csrf.hash" /><button type="submit" :class="dangerButton">Quitar</button></form></div></td></tr>
            </tbody>
          </table>
        </div>
      </PanelCard>

      <PanelCard title="Caja del chofer" :count="cashMovements.length">
        <p v-if="!canRegisterCosts" class="mb-4 rounded-lg bg-surface p-3 text-sm text-ink-muted">El viaje está cancelado o finalizado; la caja queda de solo lectura.</p>
        <p v-if="cashMovements.length === 0" class="text-sm text-ink-muted">Todavía no hay movimientos de caja.</p>
        <div v-else class="overflow-x-auto">
          <table class="w-full min-w-[42rem] text-left text-sm">
            <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted"><tr><th class="px-3 py-2">Fecha</th><th class="px-3 py-2">Tipo</th><th class="px-3 py-2 text-right">Importe</th><th class="px-3 py-2 text-right">ARS</th><th class="px-3 py-2">Observaciones</th><th v-if="canRegisterCosts" class="px-3 py-2">Acciones</th></tr></thead>
            <tbody class="divide-y divide-border-subtle">
              <tr v-for="movement in cashMovements" :key="movement.id"><td class="whitespace-nowrap px-3 py-2 text-ink-muted">{{ fmtDateTime(movement.fecha) }}</td><td class="px-3 py-2 text-ink">{{ cashTypeLabel[movement.tipo] ?? movement.tipo }}</td><td class="px-3 py-2 text-right text-ink-muted">{{ money(movement.importe, movement.moneda) }}</td><td class="px-3 py-2 text-right font-semibold text-ink">{{ money(movement.importe_ars) }}</td><td class="px-3 py-2 text-ink-muted">{{ movement.observaciones || '—' }}</td><td v-if="canRegisterCosts" class="whitespace-nowrap px-3 py-2"><div class="flex gap-2"><button type="button" :class="secondaryButton" @click="openEditModal('cash', movement)">Editar</button><form method="post" :action="movementUrl('cash', movement.id, true)" @submit="confirmDelete($event, 'este movimiento de caja')"><input type="hidden" :name="csrf.name" :value="csrf.hash" /><button type="submit" :class="dangerButton">Quitar</button></form></div></td></tr>
            </tbody>
          </table>
        </div>
      </PanelCard>
    </div>

    <PanelCard title="Paradas operativas" :count="stops.length" class="mt-5">
      <p v-if="stops.length === 0" class="text-sm text-ink-muted">No hay paradas operativas cargadas. Los tramos con cliente y fletes se administran en la sección Tramos.</p>
      <ol v-else class="space-y-4">
        <li v-for="stop in stops" :key="stop.orden" class="rounded-xl border border-border-subtle bg-surface p-4">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Parada {{ stop.orden }} · {{ stop.tipo_label }}</p>
              <p class="mt-1 text-sm font-bold text-ink">{{ stop.ubicacion }}</p>
              <p v-if="stop.cliente" class="mt-0.5 text-sm text-ink-muted">Cliente: {{ stop.cliente }}</p>
              <p v-if="stop.domicilio" class="mt-0.5 text-xs text-ink-muted">{{ stop.domicilio }}</p>
            </div>
            <p class="text-xs text-ink-muted">Estimada: {{ stop.fecha_estimada ? fmtDateTime(stop.fecha_estimada) : 'sin fecha' }}</p>
          </div>
          <dl v-if="stop.descripcion_carga || stop.cantidad || stop.observaciones" class="mt-3 grid gap-2 border-t border-border-subtle pt-3 text-sm sm:grid-cols-3">
            <div v-if="stop.descripcion_carga"><dt class="text-xs text-ink-muted">Carga</dt><dd class="font-semibold text-ink">{{ stop.descripcion_carga }}</dd></div>
            <div v-if="stop.cantidad"><dt class="text-xs text-ink-muted">Cantidad</dt><dd class="font-semibold text-ink">{{ number(stop.cantidad) }} {{ stop.unidad ?? '' }}</dd></div>
            <div v-if="stop.observaciones"><dt class="text-xs text-ink-muted">Observaciones</dt><dd class="font-semibold text-ink">{{ stop.observaciones }}</dd></div>
          </dl>
        </li>
      </ol>
    </PanelCard>

    <dialog v-if="canRegisterCosts" ref="entryDialog" aria-labelledby="entry-dialog-title" @close="handleEntryDialogClose" @click.self="entryDialog?.close()" class="m-auto w-[min(48rem,calc(100vw-2rem))] max-h-[calc(100vh-2rem)] overflow-y-auto rounded-xl border border-border bg-surface-raised p-0 text-ink shadow-xl backdrop:bg-black/60">
      <div v-if="activeEntryModal" class="p-5 sm:p-6">
        <div class="mb-5 flex items-start justify-between gap-4 border-b border-border-subtle pb-4">
          <div>
            <h2 id="entry-dialog-title" class="text-lg font-bold text-ink">{{ entryDialogTitle }}</h2>
            <p class="mt-1 text-sm text-ink-muted">Viaje #{{ trip.numero }}</p>
          </div>
          <button type="button" aria-label="Cerrar" @click="entryDialog?.close()" class="grid size-9 shrink-0 place-items-center rounded-lg text-ink-muted hover:bg-surface-subtle hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary">×</button>
        </div>

        <form v-if="activeEntryModal === 'fuel'" method="post" :action="editingEntry ? movementUrl('fuel', editingEntry.id) : data.links?.fuel">
          <input type="hidden" :name="csrf.name" :value="csrf.hash" />
          <p class="text-sm text-ink-muted">Los litros y el odómetro permiten calcular el consumo del viaje.</p>
          <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Fecha y hora</span><input type="datetime-local" name="fecha" :value="editingEntry ? inputDateTime(editingEntry.fecha) : nowLocal()" required :class="fieldClass" /></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Litros</span><input type="number" name="litros" min="0.01" step="0.01" :value="editingEntry?.litros ?? ''" required :class="fieldClass" /></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Importe</span><input type="number" name="importe" min="0.01" step="0.01" :value="editingEntry?.importe ?? ''" required :class="fieldClass" /></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Moneda</span><select name="moneda" :value="editingEntry?.moneda ?? 'ARS'" :class="fieldClass"><option v-for="currency in currencies" :key="currency.code" :value="currency.code">{{ currency.code }} · {{ currency.label }}</option></select></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Cotización a ARS</span><input type="number" name="cotizacion" min="0.000001" step="0.000001" :value="editingEntry?.cotizacion ?? 1" :class="fieldClass" /></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Odómetro</span><input type="number" name="kilometraje" min="1" step="1" :value="editingEntry?.kilometraje ?? ''" :class="fieldClass" /></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Estación</span><input type="text" name="estacion" maxlength="120" :value="editingEntry?.estacion ?? ''" :class="fieldClass" /></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Tramo (opcional)</span><select name="tramo_id" :value="editingEntry?.tramo_id ?? ''" :class="fieldClass"><option value="">Todo el viaje</option><option v-for="leg in legs" :key="leg.id" :value="leg.id">{{ leg.orden }} · {{ leg.origen }} → {{ leg.destino }}</option></select></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Comprobante</span><input type="text" name="comprobante" maxlength="80" :value="editingEntry?.comprobante ?? ''" :class="fieldClass" /></label>
            <label class="block sm:col-span-2 lg:col-span-3"><span class="mb-1 block text-xs font-semibold text-ink-muted">Observaciones</span><input type="text" name="observaciones" maxlength="500" :value="editingEntry?.observaciones ?? ''" :class="fieldClass" /></label>
          </div>
          <div class="mt-5 flex justify-end gap-2 border-t border-border-subtle pt-4"><button type="button" @click="entryDialog?.close()" :class="secondaryButton">Volver</button><button type="submit" :class="primaryButton">{{ editingEntry ? 'Guardar cambios' : 'Guardar combustible' }}</button></div>
        </form>

        <form v-else-if="activeEntryModal === 'expense'" method="post" :action="editingEntry ? movementUrl('expense', editingEntry.id) : data.links?.expense" data-concept-kind="expense">
          <input type="hidden" :name="csrf.name" :value="csrf.hash" />
          <p class="text-sm text-ink-muted">Peajes, viáticos, carga/descarga y otros gastos del viaje. Si elegís un concepto, podés calcular el importe por cantidad.</p>
          <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Categoría</span><select name="categoria_id" :value="editingEntry?.categoria_id ?? ''" :class="fieldClass"><option value="">Sin categoría</option><option v-for="category in options.expenseCategories" :key="category.id" :value="category.id">{{ category.label }}</option></select></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Concepto del catálogo</span><select name="concepto_id" :value="editingEntry?.concepto_id ?? ''" :class="fieldClass" @change="applyConceptToForm"><option value="">Sin concepto</option><option v-for="concept in expenseConcepts" :key="concept.id" :value="concept.id">{{ conceptOptionLabel(concept) }}</option></select></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Cantidad</span><input type="number" name="cantidad" min="0.01" step="0.01" :value="editingEntry?.cantidad ?? 1" :class="fieldClass" @input="applyConceptToForm" /></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Fecha y hora</span><input type="datetime-local" name="fecha" :value="editingEntry ? inputDateTime(editingEntry.fecha) : nowLocal()" required :class="fieldClass" /></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Importe</span><input type="number" name="importe" min="0.01" step="0.01" :value="editingEntry?.importe ?? ''" required :class="fieldClass" /></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Moneda</span><select name="moneda" :value="editingEntry?.moneda ?? 'ARS'" :class="fieldClass"><option v-for="currency in currencies" :key="currency.code" :value="currency.code">{{ currency.code }} · {{ currency.label }}</option></select></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Cotización a ARS</span><input type="number" name="cotizacion" min="0.000001" step="0.000001" :value="editingEntry?.cotizacion ?? 1" :class="fieldClass" /></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Medio de pago</span><select name="medio_pago_id" :value="editingEntry?.medio_pago_id ?? ''" :class="fieldClass"><option value="">Sin medio</option><option v-for="method in options.paymentMethods" :key="method.id" :value="method.id">{{ method.label }}</option></select></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Tramo (opcional)</span><select name="tramo_id" :value="editingEntry?.tramo_id ?? ''" :class="fieldClass"><option value="">Todo el viaje</option><option v-for="leg in legs" :key="leg.id" :value="leg.id">{{ leg.orden }} · {{ leg.origen }} → {{ leg.destino }}</option></select></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Pagado por</span><select name="pagado_por" :value="editingEntry?.pagado_por ?? 'EMPRESA'" :class="fieldClass"><option value="EMPRESA">Empresa</option><option value="CHOFER">Chofer (a reintegrar)</option><option value="CAJA">Caja del chofer</option></select></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Comprobante</span><input type="text" name="comprobante" maxlength="80" :value="editingEntry?.comprobante ?? ''" :class="fieldClass" /></label>
            <label class="block sm:col-span-2 lg:col-span-3"><span class="mb-1 block text-xs font-semibold text-ink-muted">Observaciones</span><input type="text" name="observaciones" maxlength="500" :value="editingEntry?.observaciones ?? ''" :class="fieldClass" /></label>
          </div>
          <p class="mt-2 text-xs text-ink-muted">Si el concepto no permite editar el importe, se calcula como cantidad × importe unitario.</p>
          <div class="mt-5 flex justify-end gap-2 border-t border-border-subtle pt-4"><button type="button" @click="entryDialog?.close()" :class="secondaryButton">Volver</button><button type="submit" :class="primaryButton">{{ editingEntry ? 'Guardar cambios' : 'Guardar gasto' }}</button></div>
        </form>

        <form v-else-if="activeEntryModal === 'earning'" method="post" :action="editingEntry ? movementUrl('earning', editingEntry.id) : data.links?.earnings" data-concept-kind="earning">
          <input type="hidden" :name="csrf.name" :value="csrf.hash" />
          <p class="text-sm text-ink-muted">Estadías y otros haberes del chofer. Elegí un concepto para calcular el importe por cantidad.</p>
          <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <label v-if="driverOptions.length > 1" class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Chofer</span><select name="empleado_id" :value="editingEntry?.empleado_id ?? driverOptions[0]?.value" :class="fieldClass"><option v-for="driver in driverOptions" :key="driver.value" :value="driver.value">{{ driver.label }}</option></select></label>
            <input v-else type="hidden" name="empleado_id" :value="editingEntry?.empleado_id ?? driverOptions[0]?.value ?? ''">
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Fecha</span><input type="date" name="fecha" :value="editingEntry?.fecha ?? today" required :class="fieldClass" /></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Concepto del catálogo</span><select name="concepto_id" :value="editingEntry?.concepto_id ?? ''" :class="fieldClass" @change="applyConceptToForm"><option value="">Concepto libre</option><option v-for="concept in earningConcepts" :key="concept.id" :value="concept.id">{{ conceptOptionLabel(concept) }}</option></select></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Cantidad (días o unidades)</span><input type="number" name="cantidad" min="0.01" step="0.01" :value="editingEntry?.cantidad ?? 1" :class="fieldClass" @input="applyConceptToForm" /></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Importe</span><input type="number" name="importe" min="0.01" step="0.01" :value="editingEntry?.importe ?? ''" required :class="fieldClass" /></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Moneda</span><select name="moneda" :value="editingEntry?.moneda ?? 'ARS'" :class="fieldClass"><option v-for="currency in currencies" :key="currency.code" :value="currency.code">{{ currency.code }} · {{ currency.label }}</option></select></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Cotización a ARS</span><input type="number" name="cotizacion" min="0.000001" step="0.000001" :value="editingEntry?.cotizacion ?? 1" :class="fieldClass" /></label>
            <label class="block sm:col-span-2 lg:col-span-3"><span class="mb-1 block text-xs font-semibold text-ink-muted">Concepto libre (si no elegís uno del catálogo)</span><input type="text" name="concepto" maxlength="120" :value="editingEntry?.concepto ?? ''" :class="fieldClass" /></label>
            <label class="block sm:col-span-2 lg:col-span-3"><span class="mb-1 block text-xs font-semibold text-ink-muted">Observaciones</span><input type="text" name="observaciones" maxlength="500" :value="editingEntry?.observaciones ?? ''" :class="fieldClass" /></label>
          </div>
          <p class="mt-2 text-xs text-ink-muted">Si el concepto no permite editar el importe, se calcula como cantidad × importe unitario.</p>
          <div class="mt-5 flex justify-end gap-2 border-t border-border-subtle pt-4"><button type="button" @click="entryDialog?.close()" :class="secondaryButton">Volver</button><button type="submit" :class="primaryButton">{{ editingEntry ? 'Guardar cambios' : 'Guardar haber' }}</button></div>
        </form>

        <form v-else-if="activeEntryModal === 'logbook'" method="post" :action="editingEntry ? movementUrl('logbook', editingEntry.id) : data.links?.logbook">
          <input type="hidden" :name="csrf.name" :value="csrf.hash" />
          <p class="text-sm text-ink-muted">Cargá la llegada y la salida de la etapa. Los días se calculan por diferencia de fechas y se descuenta la franquicia de esa etapa. El haber se genera automáticamente.</p>
          <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Etapa</span><select v-model="logbookDraft.tipo" name="tipo" :class="fieldClass"><option value="CARGA">Carga</option><option value="FRONTERA">Frontera</option><option value="CLIENTE">Cliente / descarga</option></select></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Ubicación</span><input v-model="logbookDraft.ubicacion" type="text" name="ubicacion" maxlength="200" placeholder="Ej.: Frontera Brasil" required :class="fieldClass" /></label>
            <label v-if="driverOptions.length > 1" class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Chofer</span><select v-model="logbookDraft.empleadoId" name="empleado_id" :class="fieldClass"><option v-for="driver in driverOptions" :key="driver.value" :value="driver.value">{{ driver.label }}</option></select></label>
            <input v-else type="hidden" name="empleado_id" :value="logbookDraft.empleadoId" />
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Llegada</span><input v-model="logbookDraft.llegada" type="date" name="llegada" required :class="fieldClass" /></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Salida</span><input v-model="logbookDraft.salida" type="date" name="salida" :class="fieldClass" /></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Franquicia (horas)</span><input v-model="logbookDraft.horasLibres" type="number" name="horas_libres" min="0" step="1" :class="fieldClass" /><span class="mt-1 block text-xs text-ink-muted">48 h por defecto en cada etapa.</span></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Concepto de estadía</span><select v-model="logbookDraft.conceptoId" name="concepto_id" required :class="fieldClass"><option value="">Elegí el concepto</option><option v-for="concept in logbookDayOptions" :key="concept.id" :value="concept.id">{{ conceptOptionLabel(concept) }}</option></select></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Días a liquidar (opcional)</span><input v-model="logbookDraft.diasAjustados" type="number" name="dias_ajustados" min="0" step="0.01" :placeholder="logbookCalculatedDays === null ? '' : String(logbookCalculatedDays)" :class="fieldClass" /><span class="mt-1 block text-xs text-ink-muted">Si lo dejás vacío se usan los días calculados.</span></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Motivo de la corrección</span><input v-model="logbookDraft.motivoAjuste" type="text" name="motivo_ajuste" maxlength="500" :class="fieldClass" /><span class="mt-1 block text-xs text-ink-muted">Obligatorio si corregís los días.</span></label>
            <label class="block sm:col-span-2 lg:col-span-3"><span class="mb-1 block text-xs font-semibold text-ink-muted">Observaciones</span><input v-model="logbookDraft.observaciones" type="text" name="observaciones" maxlength="500" :class="fieldClass" /></label>
          </div>
          <div class="mt-4 rounded-lg border border-info/30 bg-info-subtle px-3 py-2 text-sm text-info-strong">
            <p>Permanencia: <strong>{{ logbookPresenceDays === null ? '—' : `${logbookPresenceDays} días` }}</strong> · Franquicia: <strong>{{ quantityLabel(Number(logbookDraft.horasLibres ?? 0) / 24) }} días</strong> · Días calculados: <strong>{{ logbookCalculatedDays === null ? '—' : quantityLabel(logbookCalculatedDays) }}</strong></p>
            <p class="mt-1">Días a liquidar: <strong>{{ logbookLiquidatedDays === null ? '—' : quantityLabel(logbookLiquidatedDays) }}</strong><span v-if="logbookAmount !== null"> · Importe: <strong>{{ money(logbookAmount, logbookSelectedConcept?.currency ?? 'ARS') }}</strong></span></p>
            <p v-if="logbookNeedsReason" class="mt-1 text-warning-strong">Indicá el motivo de la corrección de días.</p>
          </div>
          <div class="mt-5 flex justify-end gap-2 border-t border-border-subtle pt-4"><button type="button" @click="entryDialog?.close()" :class="secondaryButton">Volver</button><button type="submit" :class="primaryButton">{{ editingEntry ? 'Guardar cambios' : 'Guardar etapa' }}</button></div>
        </form>

        <form v-else-if="activeEntryModal === 'cash'" method="post" :action="editingEntry ? movementUrl('cash', editingEntry.id) : data.links?.cash">
          <input type="hidden" :name="csrf.name" :value="csrf.hash" />
          <p class="text-sm text-ink-muted">La caja inicial, los anticipos y los retiros aumentan el importe a rendir, pero no son costo del viaje. Las devoluciones reducen el saldo pendiente.</p>
          <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Tipo</span><select name="tipo" :value="editingEntry?.tipo ?? 'ANTICIPO'" :class="fieldClass"><option value="CAJA_INICIAL">Caja inicial</option><option value="ANTICIPO">Anticipo</option><option value="RETIRO">Retiro</option><option value="DEVOLUCION">Devolución</option></select></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Fecha y hora</span><input type="datetime-local" name="fecha" :value="editingEntry ? inputDateTime(editingEntry.fecha) : nowLocal()" required :class="fieldClass" /></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Importe</span><input type="number" name="importe" min="0.01" step="0.01" :value="editingEntry?.importe ?? ''" required :class="fieldClass" /></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Moneda</span><select name="moneda" :value="editingEntry?.moneda ?? 'ARS'" :class="fieldClass"><option v-for="currency in currencies" :key="currency.code" :value="currency.code">{{ currency.code }} · {{ currency.label }}</option></select></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Cotización a ARS</span><input type="number" name="cotizacion" min="0.000001" step="0.000001" :value="editingEntry?.cotizacion ?? 1" :class="fieldClass" /></label>
            <label class="block sm:col-span-2 lg:col-span-3"><span class="mb-1 block text-xs font-semibold text-ink-muted">Observaciones</span><input type="text" name="observaciones" maxlength="500" :value="editingEntry?.observaciones ?? ''" :class="fieldClass" /></label>
          </div>
          <div class="mt-5 flex justify-end gap-2 border-t border-border-subtle pt-4"><button type="button" @click="entryDialog?.close()" :class="secondaryButton">Volver</button><button type="submit" :class="primaryButton">{{ editingEntry ? 'Guardar cambios' : 'Guardar movimiento' }}</button></div>
        </form>
      </div>
    </dialog>

    <LocalityCreateModal
      :open="localityModalOpen"
      :initial-name="localityModalName"
      :create-url="data.links?.localities ?? ''"
      :provinces-url="data.links?.provinces ?? ''"
      :csrf="csrf"
      @close="closeLocalityModal"
      @created="applyCreatedLocality"
    />
  </div>
</template>
