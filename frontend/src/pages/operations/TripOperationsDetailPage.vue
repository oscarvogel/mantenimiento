<script setup>
import { computed, nextTick, onMounted, ref } from 'vue'
import { ArrowDownTrayIcon, BanknotesIcon, BeakerIcon, CheckCircleIcon, ReceiptPercentIcon, XCircleIcon } from '@heroicons/vue/24/outline'
import PageHeading from './components/PageHeading.vue'
import PanelCard from './components/PanelCard.vue'
import AutocompleteSelect from './components/AutocompleteSelect.vue'
import { fieldClass, primaryButton, secondaryButton, dangerButton, nowLocal } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })

const trip = computed(() => props.data.trip ?? {})
const assignments = computed(() => props.data.assignments ?? [])
const stops = computed(() => props.data.stops ?? [])
const fuel = computed(() => props.data.fuel ?? [])
const expenses = computed(() => props.data.expenses ?? [])
const cashMovements = computed(() => props.data.cashMovements ?? [])
const otherEarnings = computed(() => props.data.otherEarnings ?? [])
const settlement = computed(() => props.data.settlement ?? null)
const totals = computed(() => props.data.totals ?? {})
const legs = computed(() => props.data.legs ?? [])
const consumption = computed(() => props.data.consumption ?? {})
const options = computed(() => props.data.options ?? { expenseCategories: [], paymentMethods: [], currencies: [] })
const csrf = computed(() => props.data.csrf ?? { name: 'csrf_test_name', hash: '' })
const canRegisterCosts = computed(() => Boolean(props.data.canRegisterCosts))
const canAddLegs = computed(() => Boolean(props.data.canAddLegs))
const canClose = computed(() => Boolean(props.data.canClose))
const currencies = computed(() => options.value.currencies?.length ? options.value.currencies : [{ code: 'ARS', label: 'Peso argentino' }])
const customerOptions = computed(() => (options.value.customers ?? []).map((customer) => ({ value: customer.id, label: customer.label, hint: customer.code ? `Código ${customer.code}` : '' })))
const localities = computed(() => options.value.localities ?? [])
const localityOptions = computed(() => localities.value.map((locality) => ({ value: locality.id, label: locality.label })))
const localityName = (localityId) => localities.value.find((locality) => String(locality.id) === String(localityId))?.name ?? ''
const newLegOriginId = ref('')
const newLegDestinationId = ref('')
const newLegCustomerId = ref('')
const newLegCurrency = ref('ARS')
const newLegExchangeRate = ref('1')
const setNewLegCurrency = () => { newLegExchangeRate.value = newLegCurrency.value === 'ARS' ? '1' : '' }
const entryFormState = computed(() => props.data.entryForm ?? null)
const entryDialog = ref(null)
const activeEntryModal = ref('')
const editingEntry = ref(null)
const showMore = ref(false)
const movementFilter = ref('all')
const repeatChoices = ref({
  fecha: true,
  moneda: true,
  tramo_id: true,
  estacion: false,
  categoria_id: true,
  pagado_por: true,
  medio_pago_id: false,
  tipo: true,
})

const entryFields = {
  fuel: ['fecha', 'litros', 'importe', 'moneda', 'cotizacion', 'kilometraje', 'estacion', 'comprobante', 'observaciones', 'tramo_id'],
  expense: ['fecha', 'importe', 'categoria_id', 'moneda', 'cotizacion', 'medio_pago_id', 'tramo_id', 'pagado_por', 'comprobante', 'observaciones'],
  cash: ['tipo', 'fecha', 'importe', 'moneda', 'cotizacion', 'observaciones'],
}

function emptyEntry() {
  return {
    fecha: nowLocal(),
    litros: '',
    importe: '',
    moneda: 'ARS',
    cotizacion: '1',
    kilometraje: '',
    estacion: '',
    comprobante: '',
    observaciones: '',
    tramo_id: '',
    categoria_id: '',
    medio_pago_id: '',
    pagado_por: 'EMPRESA',
    tipo: 'ANTICIPO',
  }
}

const entryValues = ref(emptyEntry())

function toInputDateTime(value) {
  return String(value ?? '').replace(' ', 'T').slice(0, 16)
}

function entryFromValues(values) {
  const entry = emptyEntry()
  for (const [key, value] of Object.entries(values ?? {})) {
    if (Object.prototype.hasOwnProperty.call(entry, key) && value !== null && value !== undefined) {
      entry[key] = String(value)
    }
  }
  entry.fecha = toInputDateTime(entry.fecha) || nowLocal()

  return entry
}

function entryFromRecord(type, record) {
  const entry = emptyEntry()
  for (const field of entryFields[type] ?? []) {
    if (record?.[field] !== null && record?.[field] !== undefined) {
      entry[field] = String(record[field])
    }
  }
  entry.fecha = toInputDateTime(record?.fecha) || nowLocal()

  return entry
}

const openEntryModal = (type, preset = null, keepMoreOpen = false) => {
  editingEntry.value = null
  activeEntryModal.value = type
  entryValues.value = preset ? { ...emptyEntry(), ...preset } : emptyEntry()
  showMore.value = keepMoreOpen
  nextTick(() => entryDialog.value?.showModal())
}
const openEditModal = (type, entry) => {
  editingEntry.value = entry
  activeEntryModal.value = type
  entryValues.value = entryFromRecord(type, entry)
  showMore.value = true
  nextTick(() => entryDialog.value?.showModal())
}
const movementUrl = (type, id, remove = false) => {
  const segment = { fuel: 'combustible', expense: 'gastos', cash: 'caja' }[type]
  return `${props.data.links?.detail ?? ''}/${segment}/${id}${remove ? '/eliminar' : ''}`
}
const confirmDelete = (event, label) => {
  if (!window.confirm(`¿Quitar ${label}? Se conservará en el historial de auditoría y dejará de incluirse en los totales.`)) event.preventDefault()
}
const handleEntryDialogClose = () => {
  activeEntryModal.value = ''
  editingEntry.value = null
}
const payerExplanation = computed(() => ({
  EMPRESA: 'Es costo del viaje.',
  CHOFER: 'Es costo del viaje y queda como gasto a reintegrar al chofer.',
  CAJA: 'Es costo del viaje pagado con la caja entregada.',
}[entryValues.value.pagado_por] ?? ''))
const repeatOptions = (type) => ({
  fuel: [
    { field: 'fecha', label: 'Fecha' },
    { field: 'moneda', label: 'Moneda y cotización' },
    { field: 'tramo_id', label: 'Tramo' },
    { field: 'estacion', label: 'Estación' },
  ],
  expense: [
    { field: 'fecha', label: 'Fecha' },
    { field: 'moneda', label: 'Moneda y cotización' },
    { field: 'tramo_id', label: 'Tramo' },
    { field: 'categoria_id', label: 'Categoría' },
    { field: 'pagado_por', label: 'Pagador' },
    { field: 'medio_pago_id', label: 'Medio de pago' },
  ],
  cash: [
    { field: 'fecha', label: 'Fecha' },
    { field: 'moneda', label: 'Moneda y cotización' },
    { field: 'tipo', label: 'Tipo' },
  ],
}[type] ?? [])
onMounted(() => {
  const state = entryFormState.value
  if (!state) return

  const type = state.type
  if (state.mode !== 'error') {
    const selected = Object.keys(state.repeat ?? {})
    repeatChoices.value = {
      fecha: selected.includes('fecha'),
      moneda: selected.includes('moneda'),
      tramo_id: selected.includes('tramo_id'),
      estacion: selected.includes('estacion'),
      categoria_id: selected.includes('categoria_id'),
      pagado_por: selected.includes('pagado_por'),
      medio_pago_id: selected.includes('medio_pago_id'),
      tipo: selected.includes('tipo'),
    }
  }
  const preset = entryFromValues(Object.keys(state.values ?? {}).length ? state.values : (state.repeat ?? {}))
  if (state.editing_id) {
    const records = type === 'fuel' ? fuel.value : type === 'expense' ? expenses.value : cashMovements.value
    const record = records.find((row) => String(row.id) === String(state.editing_id))
    if (record) {
      openEditModal(type, record)
      if (Object.keys(state.values ?? {}).length) {
        entryValues.value = entryFromValues({ ...entryFromRecord(type, record), ...state.values })
        showMore.value = true
      }
      return
    }
  }
  openEntryModal(type, preset, true)
})
const statusTone = {
  PLANIFICADO: 'bg-info-subtle text-info-strong',
  EN_CURSO: 'bg-success-subtle text-success-strong',
  FINALIZADO: 'bg-surface-muted text-ink-muted',
  CANCELADO: 'bg-danger-subtle text-danger-strong',
}
const cashTypeLabel = { CAJA_INICIAL: 'Caja inicial', ANTICIPO: 'Anticipo', RETIRO: 'Retiro', DEVOLUCION: 'Devolución' }

const paidByLabel = { EMPRESA: 'Empresa', CHOFER: 'Chofer', CAJA: 'Caja del chofer' }
const movementTypeLabel = { fuel: 'Combustible', expense: 'Gasto', cash: 'Caja' }
const movementFilters = [
  { value: 'all', label: 'Todos' },
  { value: 'fuel', label: 'Combustible' },
  { value: 'expense', label: 'Gastos' },
  { value: 'cash', label: 'Caja' },
]
const moneyDetail = (row) => (row.moneda && row.moneda !== 'ARS'
  ? `${money(row.importe, row.moneda)} · cotización ${number(row.cotizacion)}`
  : null)
const movements = computed(() => {
  const rows = []
  for (const row of fuel.value) {
    rows.push({
      key: `fuel-${row.id}`,
      type: 'fuel',
      id: row.id,
      record: row,
      fecha: row.fecha,
      concepto: row.estacion ? `Combustible · ${row.estacion}` : 'Combustible',
      importe: row.importe,
      moneda: row.moneda,
      importe_ars: row.importe_ars,
      pagadorLabel: 'Empresa',
      detalle: [
        ['Litros', row.litros === null || row.litros === undefined ? null : `${number(row.litros)} L`],
        ['Odómetro', row.kilometraje === null || row.kilometraje === undefined ? null : `${number(row.kilometraje)} km`],
        ['Estación', row.estacion],
        ['Comprobante', row.comprobante],
        ['Tramo', legLabel(row.tramo_id)],
        ['Observaciones', row.observaciones],
        ['Moneda', moneyDetail(row)],
      ],
    })
  }
  for (const row of expenses.value) {
    rows.push({
      key: `expense-${row.id}`,
      type: 'expense',
      id: row.id,
      record: row,
      fecha: row.fecha,
      concepto: row.categoria || 'Gasto',
      importe: row.importe,
      moneda: row.moneda,
      importe_ars: row.importe_ars,
      pagadorLabel: paidByLabel[row.pagado_por] ?? 'Empresa',
      detalle: [
        ['Categoría', row.categoria || 'Sin categoría'],
        ['Medio de pago', row.medio_pago],
        ['Tramo', legLabel(row.tramo_id)],
        ['Pagador', paidByLabel[row.pagado_por] ?? 'Empresa'],
        ['Comprobante', row.comprobante],
        ['Observaciones', row.observaciones],
        ['Moneda', moneyDetail(row)],
      ],
    })
  }
  for (const row of cashMovements.value) {
    rows.push({
      key: `cash-${row.id}`,
      type: 'cash',
      id: row.id,
      record: row,
      fecha: row.fecha,
      concepto: cashTypeLabel[row.tipo] ?? row.tipo,
      importe: row.importe,
      moneda: row.moneda,
      importe_ars: row.importe_ars,
      pagadorLabel: '—',
      detalle: [
        ['Tipo', cashTypeLabel[row.tipo] ?? row.tipo],
        ['Observaciones', row.observaciones],
        ['Moneda', moneyDetail(row)],
      ],
    })
  }

  return rows.sort((left, right) => String(right.fecha ?? '').localeCompare(String(left.fecha ?? '')))
})
const movementCounts = computed(() => ({
  all: movements.value.length,
  fuel: fuel.value.length,
  expense: expenses.value.length,
  cash: cashMovements.value.length,
}))
const filteredMovements = computed(() => movementFilter.value === 'all'
  ? movements.value
  : movements.value.filter((movement) => movement.type === movementFilter.value))
const movementDetail = (movement) => (movement.detalle ?? []).filter(([, value]) => value !== null && value !== undefined && value !== '')
const movementDeleteLabel = (type) => ({ fuel: 'esta carga de combustible', expense: 'este gasto', cash: 'este movimiento de caja' }[type] ?? 'este movimiento')
const filterClass = (value) => movementFilter.value === value
  ? 'inline-flex items-center gap-1 rounded-full border border-primary bg-primary-subtle px-3 py-1.5 text-sm font-semibold text-primary'
  : 'inline-flex items-center gap-1 rounded-full border border-border bg-surface px-3 py-1.5 text-sm font-semibold text-ink-muted hover:border-primary hover:text-primary'
const drivers = computed(() => assignments.value.filter((row) => row.rol === 'CHOFER'))
const today = computed(() => new Date().toISOString().slice(0, 10))
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
  [started ? 'Salida real' : 'Salida', fmtDateTime(trip.value.fecha_salida_real ?? trip.value.fecha_salida_planificada)],
  [finished ? 'Regreso real' : (started ? 'Regreso estimado' : 'Regreso'), trip.value.fecha_regreso_real || trip.value.fecha_regreso_planificada ? fmtDateTime(trip.value.fecha_regreso_real ?? trip.value.fecha_regreso_planificada) : 'Sin estimar'],
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

    <section v-if="canRegisterCosts" aria-label="Cargar movimientos del viaje" class="mb-5 grid gap-3 sm:grid-cols-3">
      <button type="button" aria-haspopup="dialog" @click="openEntryModal('fuel')" class="group flex min-h-16 items-center gap-3 rounded-xl border border-border bg-surface-raised px-4 py-3 text-left shadow-card transition hover:border-primary hover:bg-primary-subtle focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary">
        <span class="grid size-10 shrink-0 place-items-center rounded-lg bg-primary-subtle text-primary"><BeakerIcon class="size-5" aria-hidden="true" /></span>
        <span><span class="block text-sm font-bold text-ink">+ Combustible</span><span class="mt-0.5 block text-xs text-ink-muted">Registrar una carga</span></span>
      </button>
      <button type="button" aria-haspopup="dialog" @click="openEntryModal('expense')" class="group flex min-h-16 items-center gap-3 rounded-xl border border-border bg-surface-raised px-4 py-3 text-left shadow-card transition hover:border-primary hover:bg-primary-subtle focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary">
        <span class="grid size-10 shrink-0 place-items-center rounded-lg bg-primary-subtle text-primary"><ReceiptPercentIcon class="size-5" aria-hidden="true" /></span>
        <span><span class="block text-sm font-bold text-ink">+ Gasto</span><span class="mt-0.5 block text-xs text-ink-muted">Peajes, viáticos y otros</span></span>
      </button>
      <button type="button" aria-haspopup="dialog" @click="openEntryModal('cash')" class="group flex min-h-16 items-center gap-3 rounded-xl border border-border bg-surface-raised px-4 py-3 text-left shadow-card transition hover:border-primary hover:bg-primary-subtle focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary">
        <span class="grid size-10 shrink-0 place-items-center rounded-lg bg-primary-subtle text-primary"><BanknotesIcon class="size-5" aria-hidden="true" /></span>
        <span><span class="block text-sm font-bold text-ink">+ Movimiento de caja</span><span class="mt-0.5 block text-xs text-ink-muted">Caja inicial, anticipo o devolución</span></span>
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
          <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Localidad de origen</span><AutocompleteSelect v-model="newLegOriginId" name="localidad_origen_id" :options="localityOptions" :search-url="data.links?.localities" placeholder="Buscá la localidad" required /></label>
          <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Localidad de destino</span><AutocompleteSelect v-model="newLegDestinationId" name="localidad_destino_id" :options="localityOptions" :search-url="data.links?.localities" placeholder="Buscá la localidad" required /></label>
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
      <div v-else class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]">
        <div class="rounded-xl border border-border-subtle bg-surface p-4">
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
          <p class="mt-2 text-xs text-ink-muted">Los anticipos no son costo del viaje. El saldo negativo queda a favor de la empresa.</p>
        </div>

        <div>
          <form v-if="canRegisterCosts && drivers.length === 1" method="post" :action="data.links?.earnings" class="rounded-xl border border-border-subtle bg-surface p-4">
            <input type="hidden" :name="csrf.name" :value="csrf.hash" />
            <input type="hidden" name="empleado_id" :value="drivers[0].empleado_id" />
            <h3 class="text-sm font-bold text-ink">Agregar otro haber</h3>
            <p class="mt-1 text-xs text-ink-muted">Viaje, espera, descarga u otro adicional acordado con el chofer.</p>
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
              <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Fecha</span><input type="date" name="fecha" :value="today" required :class="fieldClass" /></label>
              <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Importe</span><input type="number" name="importe" min="0.01" step="0.01" required :class="fieldClass" /></label>
              <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Moneda</span><select name="moneda" :class="fieldClass"><option v-for="currency in currencies" :key="currency.code" :value="currency.code" :selected="currency.code === 'ARS'">{{ currency.code }} · {{ currency.label }}</option></select></label>
              <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Cotización a ARS</span><input type="number" name="cotizacion" min="0.000001" step="0.000001" value="1" :class="fieldClass" /></label>
              <label class="block sm:col-span-2"><span class="mb-1 block text-xs font-semibold text-ink-muted">Concepto</span><input type="text" name="concepto" maxlength="120" required :class="fieldClass" /></label>
              <label class="block sm:col-span-2"><span class="mb-1 block text-xs font-semibold text-ink-muted">Observaciones</span><input type="text" name="observaciones" maxlength="500" :class="fieldClass" /></label>
            </div>
            <button type="submit" :class="[primaryButton, 'mt-3']">Guardar haber</button>
          </form>
          <p v-if="otherEarnings.length === 0" class="mt-4 text-sm text-ink-muted">Todavía no hay otros haberes cargados.</p>
          <div v-else class="mt-4 overflow-x-auto">
            <table class="w-full min-w-[32rem] text-left text-sm">
              <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted"><tr><th class="px-3 py-2">Fecha</th><th class="px-3 py-2">Concepto</th><th class="px-3 py-2">Chofer</th><th class="px-3 py-2 text-right">ARS</th></tr></thead>
              <tbody class="divide-y divide-border-subtle">
                <tr v-for="earning in otherEarnings" :key="earning.id"><td class="whitespace-nowrap px-3 py-2 text-ink-muted">{{ fmtDateTime(earning.fecha) }}</td><td class="px-3 py-2 text-ink">{{ earning.concepto }}</td><td class="px-3 py-2 text-ink-muted">{{ earning.empleado || '—' }}</td><td class="px-3 py-2 text-right font-semibold text-ink">{{ money(earning.importe_ars) }}</td></tr>
              </tbody>
            </table>
          </div>
        </div>
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
            </div>
            <p class="text-xs text-ink-muted">{{ fmtDateTime(assignment.desde) }} — {{ assignment.hasta ? fmtDateTime(assignment.hasta) : 'sin fin' }}</p>
          </li>
        </ul>
      </PanelCard>

      <PanelCard title="Acciones" v-if="data.canStart || data.canCancel || canClose">
        <form v-if="data.canStart" method="post" :action="`${data.links?.detail ?? ''}/iniciar`" class="mb-5 rounded-xl border border-border-subtle bg-surface p-4">
          <input type="hidden" :name="csrf.name" :value="csrf.hash" />
          <h3 class="flex items-center gap-2 text-sm font-bold text-ink"><CheckCircleIcon class="size-4 text-success" aria-hidden="true" />Registrar salida real</h3>
          <p class="mt-1 text-xs text-ink-muted">Al iniciar, el viaje pasa a En curso. Si no indicás la fecha y hora, se usa el momento actual.</p>
          <div class="mt-3 grid gap-3 sm:grid-cols-2">
            <label class="block">
              <span class="mb-1.5 block text-sm font-semibold text-ink">Fecha y hora de salida</span>
              <input type="datetime-local" name="fecha_salida_real" :class="fieldClass" />
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
              <span class="mb-1.5 block text-sm font-semibold text-ink">Fecha y hora de regreso</span>
              <input type="datetime-local" name="fecha_regreso_real" :value="nowLocal()" :class="fieldClass" />
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

    <PanelCard title="Movimientos del viaje" :count="movements.length" class="mt-5">
      <p v-if="!canRegisterCosts" class="mb-4 rounded-lg bg-surface p-3 text-sm text-ink-muted">El viaje está cancelado o finalizado; sus movimientos quedan de solo lectura.</p>
      <div class="mb-4 flex flex-wrap gap-2" role="group" aria-label="Filtrar movimientos">
        <button v-for="filter in movementFilters" :key="filter.value" type="button" :aria-pressed="movementFilter === filter.value" :class="filterClass(filter.value)" @click="movementFilter = filter.value">
          {{ filter.label }} <span class="rounded-full bg-surface px-1.5 py-0.5 text-xs">{{ movementCounts[filter.value] }}</span>
        </button>
      </div>
      <p v-if="movements.length === 0" class="text-sm text-ink-muted">Todavía no hay movimientos cargados en este viaje.</p>
      <p v-else-if="filteredMovements.length === 0" class="text-sm text-ink-muted">No hay movimientos de este tipo en el viaje.</p>
      <template v-else>
        <div class="hidden overflow-x-auto md:block">
          <table class="w-full min-w-[52rem] text-left text-sm">
            <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted">
              <tr>
                <th class="px-3 py-2">Fecha</th>
                <th class="px-3 py-2">Tipo</th>
                <th class="px-3 py-2">Concepto</th>
                <th class="px-3 py-2 text-right">Importe</th>
                <th class="px-3 py-2">Pagador</th>
                <th v-if="canRegisterCosts" class="px-3 py-2">Acciones</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border-subtle">
              <tr v-for="movement in filteredMovements" :key="movement.key">
                <td class="whitespace-nowrap px-3 py-2 text-ink-muted">{{ fmtDateTime(movement.fecha) }}</td>
                <td class="px-3 py-2"><span class="inline-flex rounded-full bg-surface-subtle px-2 py-0.5 text-xs font-semibold text-ink-muted">{{ movementTypeLabel[movement.type] }}</span></td>
                <td class="px-3 py-2">
                  <p class="font-semibold text-ink">{{ movement.concepto }}</p>
                  <details class="mt-1">
                    <summary class="cursor-pointer text-xs font-semibold text-primary">Ver detalle</summary>
                    <dl class="mt-2 grid gap-1 text-xs text-ink-muted sm:grid-cols-2">
                      <div v-for="([label, value]) in movementDetail(movement)" :key="label"><dt class="font-semibold">{{ label }}</dt><dd>{{ value }}</dd></div>
                    </dl>
                  </details>
                </td>
                <td class="px-3 py-2 text-right">
                  <p class="font-semibold text-ink">{{ money(movement.importe, movement.moneda) }}</p>
                  <p v-if="movement.moneda !== 'ARS'" class="text-xs text-ink-muted">{{ money(movement.importe_ars) }}</p>
                </td>
                <td class="px-3 py-2 text-ink-muted">{{ movement.pagadorLabel }}</td>
                <td v-if="canRegisterCosts" class="whitespace-nowrap px-3 py-2">
                  <div class="flex gap-2">
                    <button type="button" :class="secondaryButton" @click="openEditModal(movement.type, movement.record)">Editar</button>
                    <form method="post" :action="movementUrl(movement.type, movement.id, true)" @submit="confirmDelete($event, movementDeleteLabel(movement.type))">
                      <input type="hidden" :name="csrf.name" :value="csrf.hash" />
                      <button type="submit" :class="dangerButton">Quitar</button>
                    </form>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <ul class="space-y-3 md:hidden">
          <li v-for="movement in filteredMovements" :key="movement.key" class="rounded-xl border border-border-subtle bg-surface p-4">
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <p class="text-xs font-semibold text-ink-muted">{{ fmtDateTime(movement.fecha) }} · {{ movementTypeLabel[movement.type] }}</p>
                <p class="mt-1 break-words text-sm font-bold text-ink">{{ movement.concepto }}</p>
                <p class="mt-0.5 text-xs text-ink-muted">Pagador: {{ movement.pagadorLabel }}</p>
              </div>
              <div class="shrink-0 text-right">
                <p class="text-sm font-bold text-ink">{{ money(movement.importe, movement.moneda) }}</p>
                <p v-if="movement.moneda !== 'ARS'" class="text-xs text-ink-muted">{{ money(movement.importe_ars) }}</p>
              </div>
            </div>
            <details class="mt-3 border-t border-border-subtle pt-3">
              <summary class="cursor-pointer text-xs font-semibold text-primary">Ver detalle</summary>
              <dl class="mt-2 grid gap-1 text-xs text-ink-muted">
                <div v-for="([label, value]) in movementDetail(movement)" :key="label"><dt class="font-semibold">{{ label }}</dt><dd>{{ value }}</dd></div>
              </dl>
            </details>
            <div v-if="canRegisterCosts" class="mt-3 flex gap-2 border-t border-border-subtle pt-3">
              <button type="button" :class="secondaryButton" @click="openEditModal(movement.type, movement.record)">Editar</button>
              <form method="post" :action="movementUrl(movement.type, movement.id, true)" @submit="confirmDelete($event, movementDeleteLabel(movement.type))">
                <input type="hidden" :name="csrf.name" :value="csrf.hash" />
                <button type="submit" :class="dangerButton">Quitar</button>
              </form>
            </div>
          </li>
        </ul>
      </template>
    </PanelCard>

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

    <dialog v-if="canRegisterCosts" ref="entryDialog" aria-labelledby="entry-dialog-title" @close="handleEntryDialogClose" @click.self="entryDialog?.close()" class="fixed inset-0 m-0 ml-auto h-full max-h-none w-full max-w-none overflow-y-auto rounded-none border border-border bg-surface-raised p-0 text-ink shadow-xl backdrop:bg-black/60 sm:w-[min(36rem,100vw)] sm:rounded-l-xl sm:border-l">
      <div v-if="activeEntryModal" class="p-5 sm:p-6">
        <div class="mb-5 flex items-start justify-between gap-4 border-b border-border-subtle pb-4">
          <div>
            <h2 id="entry-dialog-title" class="text-lg font-bold text-ink">{{ editingEntry ? (activeEntryModal === 'fuel' ? 'Editar carga de combustible' : activeEntryModal === 'expense' ? 'Editar gasto' : 'Editar movimiento de caja') : (activeEntryModal === 'fuel' ? 'Combustible' : activeEntryModal === 'expense' ? 'Gasto' : 'Movimiento de caja') }}</h2>
            <p class="mt-1 text-sm text-ink-muted">Viaje #{{ trip.numero }} · Los campos con * son obligatorios.</p>
          </div>
          <button type="button" aria-label="Cerrar" @click="entryDialog?.close()" class="grid size-9 shrink-0 place-items-center rounded-lg text-ink-muted hover:bg-surface-subtle hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary">×</button>
        </div>

        <form v-if="activeEntryModal === 'fuel'" method="post" :action="editingEntry ? movementUrl('fuel', editingEntry.id) : data.links?.fuel">
          <input type="hidden" :name="csrf.name" :value="csrf.hash" />
          <p class="text-sm text-ink-muted">Cargá lo habitual. El resto queda en Más datos.</p>
          <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Fecha y hora <span class="text-danger-strong" aria-hidden="true">*</span></span><input v-model="entryValues.fecha" type="datetime-local" name="fecha" required :class="fieldClass" /></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Litros <span class="text-danger-strong" aria-hidden="true">*</span></span><input v-model="entryValues.litros" type="number" name="litros" min="0.01" step="0.01" required :class="fieldClass" /></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Importe <span class="text-danger-strong" aria-hidden="true">*</span></span><input v-model="entryValues.importe" type="number" name="importe" min="0.01" step="0.01" required :class="fieldClass" /></label>
          </div>
          <details class="mt-4 rounded-lg border border-border-subtle bg-surface p-3" :open="showMore" @toggle="showMore = $event.target.open">
            <summary class="cursor-pointer text-sm font-semibold text-ink">Agregar odómetro, estación, comprobante o tramo</summary>
            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Moneda</span><select v-model="entryValues.moneda" name="moneda" :class="fieldClass"><option v-for="currency in currencies" :key="currency.code" :value="currency.code">{{ currency.code }} · {{ currency.label }}</option></select></label>
              <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Cotización a ARS</span><input v-model="entryValues.cotizacion" type="number" name="cotizacion" min="0.000001" step="0.000001" :class="fieldClass" /></label>
              <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Odómetro</span><input v-model="entryValues.kilometraje" type="number" name="kilometraje" min="1" step="1" :class="fieldClass" /></label>
              <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Estación</span><input v-model="entryValues.estacion" type="text" name="estacion" maxlength="120" :class="fieldClass" /></label>
              <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Tramo</span><select v-model="entryValues.tramo_id" name="tramo_id" :class="fieldClass"><option value="">Todo el viaje</option><option v-for="leg in legs" :key="leg.id" :value="String(leg.id)">{{ leg.orden }} · {{ leg.origen }} → {{ leg.destino }}</option></select><span class="mt-1 block text-xs text-ink-muted">El combustible siempre afecta al resultado del viaje; solo se imputa a un tramo si lo asignás.</span></label>
              <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Comprobante</span><input v-model="entryValues.comprobante" type="text" name="comprobante" maxlength="80" :class="fieldClass" /></label>
              <label class="block sm:col-span-2 lg:col-span-3"><span class="mb-1 block text-xs font-semibold text-ink-muted">Observaciones</span><input v-model="entryValues.observaciones" type="text" name="observaciones" maxlength="500" :class="fieldClass" /></label>
            </div>
          </details>
          <div v-if="!editingEntry" class="mt-4 rounded-lg bg-surface p-3">
            <p class="text-xs font-semibold text-ink-muted">Al guardar y cargar otro, repetir</p>
            <div class="mt-2 flex flex-wrap gap-x-4 gap-y-2">
              <label v-for="choice in repeatOptions('fuel')" :key="choice.field" class="inline-flex items-center gap-1.5 text-sm text-ink">
                <input v-model="repeatChoices[choice.field]" type="checkbox" name="repetir[]" :value="choice.field" class="size-4 rounded border-border text-primary" />
                {{ choice.label }}
              </label>
            </div>
            <p class="mt-2 text-xs text-ink-muted">El importe, los litros y el comprobante siempre se limpian.</p>
          </div>
          <div class="mt-5 flex flex-wrap justify-end gap-2 border-t border-border-subtle pt-4">
            <button type="button" @click="entryDialog?.close()" :class="secondaryButton">Volver</button>
            <button type="submit" :class="primaryButton">{{ editingEntry ? 'Guardar cambios' : 'Guardar' }}</button>
            <button v-if="!editingEntry" type="submit" name="accion" value="otro" :class="secondaryButton">Guardar y cargar otro</button>
          </div>
        </form>

        <form v-else-if="activeEntryModal === 'expense'" method="post" :action="editingEntry ? movementUrl('expense', editingEntry.id) : data.links?.expense">
          <input type="hidden" :name="csrf.name" :value="csrf.hash" />
          <p class="text-sm text-ink-muted">Cargá lo habitual. El resto queda en Más datos.</p>
          <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Tipo</span><select v-model="entryValues.categoria_id" name="categoria_id" :class="fieldClass"><option value="">Sin categoría</option><option v-for="category in options.expenseCategories" :key="category.id" :value="String(category.id)">{{ category.label }}</option></select></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Fecha y hora <span class="text-danger-strong" aria-hidden="true">*</span></span><input v-model="entryValues.fecha" type="datetime-local" name="fecha" required :class="fieldClass" /></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Importe <span class="text-danger-strong" aria-hidden="true">*</span></span><input v-model="entryValues.importe" type="number" name="importe" min="0.01" step="0.01" required :class="fieldClass" /></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">¿Quién pagó? <span class="text-danger-strong" aria-hidden="true">*</span></span><select v-model="entryValues.pagado_por" name="pagado_por" :class="fieldClass"><option value="EMPRESA">Empresa</option><option value="CHOFER">Chofer</option><option value="CAJA">Caja del chofer</option></select></label>
          </div>
          <p v-if="payerExplanation" class="mt-2 rounded-lg border border-info/30 bg-info-subtle px-3 py-2 text-xs text-info-strong">{{ payerExplanation }}</p>
          <details class="mt-4 rounded-lg border border-border-subtle bg-surface p-3" :open="showMore" @toggle="showMore = $event.target.open">
            <summary class="cursor-pointer text-sm font-semibold text-ink">Agregar moneda, medio de pago, comprobante o tramo</summary>
            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Moneda</span><select v-model="entryValues.moneda" name="moneda" :class="fieldClass"><option v-for="currency in currencies" :key="currency.code" :value="currency.code">{{ currency.code }} · {{ currency.label }}</option></select></label>
              <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Cotización a ARS</span><input v-model="entryValues.cotizacion" type="number" name="cotizacion" min="0.000001" step="0.000001" :class="fieldClass" /></label>
              <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Medio de pago</span><select v-model="entryValues.medio_pago_id" name="medio_pago_id" :class="fieldClass"><option value="">Sin medio</option><option v-for="method in options.paymentMethods" :key="method.id" :value="String(method.id)">{{ method.label }}</option></select></label>
              <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Tramo</span><select v-model="entryValues.tramo_id" name="tramo_id" :class="fieldClass"><option value="">Todo el viaje</option><option v-for="leg in legs" :key="leg.id" :value="String(leg.id)">{{ leg.orden }} · {{ leg.origen }} → {{ leg.destino }}</option></select><span class="mt-1 block text-xs text-ink-muted">El gasto siempre afecta al resultado del viaje; solo afecta al margen de un tramo cuando lo asignás a uno.</span></label>
              <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Comprobante</span><input v-model="entryValues.comprobante" type="text" name="comprobante" maxlength="80" :class="fieldClass" /></label>
              <label class="block sm:col-span-2 lg:col-span-3"><span class="mb-1 block text-xs font-semibold text-ink-muted">Observaciones</span><input v-model="entryValues.observaciones" type="text" name="observaciones" maxlength="500" :class="fieldClass" /></label>
            </div>
          </details>
          <div v-if="!editingEntry" class="mt-4 rounded-lg bg-surface p-3">
            <p class="text-xs font-semibold text-ink-muted">Al guardar y cargar otro, repetir</p>
            <div class="mt-2 flex flex-wrap gap-x-4 gap-y-2">
              <label v-for="choice in repeatOptions('expense')" :key="choice.field" class="inline-flex items-center gap-1.5 text-sm text-ink">
                <input v-model="repeatChoices[choice.field]" type="checkbox" name="repetir[]" :value="choice.field" class="size-4 rounded border-border text-primary" />
                {{ choice.label }}
              </label>
            </div>
            <p class="mt-2 text-xs text-ink-muted">El importe y el comprobante siempre se limpian.</p>
          </div>
          <div class="mt-5 flex flex-wrap justify-end gap-2 border-t border-border-subtle pt-4">
            <button type="button" @click="entryDialog?.close()" :class="secondaryButton">Volver</button>
            <button type="submit" :class="primaryButton">{{ editingEntry ? 'Guardar cambios' : 'Guardar' }}</button>
            <button v-if="!editingEntry" type="submit" name="accion" value="otro" :class="secondaryButton">Guardar y cargar otro</button>
          </div>
        </form>

        <form v-else-if="activeEntryModal === 'cash'" method="post" :action="editingEntry ? movementUrl('cash', editingEntry.id) : data.links?.cash">
          <input type="hidden" :name="csrf.name" :value="csrf.hash" />
          <p class="text-sm text-ink-muted">La caja inicial, los anticipos y los retiros aumentan el importe a rendir, pero no son costo del viaje. Las devoluciones reducen el saldo pendiente.</p>
          <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Tipo <span class="text-danger-strong" aria-hidden="true">*</span></span><select v-model="entryValues.tipo" name="tipo" :class="fieldClass"><option value="CAJA_INICIAL">Caja inicial</option><option value="ANTICIPO">Anticipo</option><option value="RETIRO">Retiro</option><option value="DEVOLUCION">Devolución</option></select></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Fecha y hora <span class="text-danger-strong" aria-hidden="true">*</span></span><input v-model="entryValues.fecha" type="datetime-local" name="fecha" required :class="fieldClass" /></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Importe <span class="text-danger-strong" aria-hidden="true">*</span></span><input v-model="entryValues.importe" type="number" name="importe" min="0.01" step="0.01" required :class="fieldClass" /></label>
          </div>
          <details class="mt-4 rounded-lg border border-border-subtle bg-surface p-3" :open="showMore" @toggle="showMore = $event.target.open">
            <summary class="cursor-pointer text-sm font-semibold text-ink">Agregar moneda, cotización u observaciones</summary>
            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Moneda</span><select v-model="entryValues.moneda" name="moneda" :class="fieldClass"><option v-for="currency in currencies" :key="currency.code" :value="currency.code">{{ currency.code }} · {{ currency.label }}</option></select></label>
              <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Cotización a ARS</span><input v-model="entryValues.cotizacion" type="number" name="cotizacion" min="0.000001" step="0.000001" :class="fieldClass" /></label>
              <label class="block sm:col-span-2 lg:col-span-3"><span class="mb-1 block text-xs font-semibold text-ink-muted">Observaciones</span><input v-model="entryValues.observaciones" type="text" name="observaciones" maxlength="500" :class="fieldClass" /></label>
            </div>
          </details>
          <div v-if="!editingEntry" class="mt-4 rounded-lg bg-surface p-3">
            <p class="text-xs font-semibold text-ink-muted">Al guardar y cargar otro, repetir</p>
            <div class="mt-2 flex flex-wrap gap-x-4 gap-y-2">
              <label v-for="choice in repeatOptions('cash')" :key="choice.field" class="inline-flex items-center gap-1.5 text-sm text-ink">
                <input v-model="repeatChoices[choice.field]" type="checkbox" name="repetir[]" :value="choice.field" class="size-4 rounded border-border text-primary" />
                {{ choice.label }}
              </label>
            </div>
            <p class="mt-2 text-xs text-ink-muted">El importe siempre se limpia.</p>
          </div>
          <div class="mt-5 flex flex-wrap justify-end gap-2 border-t border-border-subtle pt-4">
            <button type="button" @click="entryDialog?.close()" :class="secondaryButton">Volver</button>
            <button type="submit" :class="primaryButton">{{ editingEntry ? 'Guardar cambios' : 'Guardar' }}</button>
            <button v-if="!editingEntry" type="submit" name="accion" value="otro" :class="secondaryButton">Guardar y cargar otro</button>
          </div>
        </form>
      </div>
    </dialog>
  </div>
</template>
