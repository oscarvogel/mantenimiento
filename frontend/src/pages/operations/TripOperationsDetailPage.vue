<script setup>
import { computed, ref } from 'vue'
import { ArrowDownTrayIcon, BanknotesIcon, BeakerIcon, CheckCircleIcon, ReceiptPercentIcon, XCircleIcon } from '@heroicons/vue/24/outline'
import PageHeading from './components/PageHeading.vue'
import PanelCard from './components/PanelCard.vue'
import AutocompleteSelect from './components/AutocompleteSelect.vue'
import { fieldClass, primaryButton, dangerButton, nowLocal } from './helpers.js'

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
const statusTone = {
  PLANIFICADO: 'bg-info-subtle text-info-strong',
  EN_CURSO: 'bg-success-subtle text-success-strong',
  FINALIZADO: 'bg-surface-muted text-ink-muted',
  CANCELADO: 'bg-danger-subtle text-danger-strong',
}
const cashTypeLabel = { ANTICIPO: 'Anticipo', RETIRO: 'Retiro', DEVOLUCION: 'Devolución' }

const paidByLabel = { EMPRESA: 'Empresa', CHOFER: 'Chofer', CAJA: 'Caja del chofer' }
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
  [finished ? 'Regreso real' : (started ? 'Regreso estimado' : 'Regreso'), fmtDateTime(trip.value.fecha_regreso_real ?? trip.value.fecha_regreso_planificada)],
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
        <span class="inline-flex rounded-full px-3 py-1.5 text-sm font-semibold" :class="statusTone[trip.estado] ?? 'bg-surface-muted text-ink-muted'">{{ trip.estado_label }}</span>
      </template>
    </PageHeading>

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
              <th class="px-3 py-2 text-right">Flete facturación</th>
              <th class="px-3 py-2 text-right">Flete liquidación</th>
              <th class="px-3 py-2 text-right">Comisión</th>
              <th class="px-3 py-2 text-right">Costos asignados</th>
              <th class="px-3 py-2 text-right">Margen</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border-subtle">
            <tr v-for="leg in legs" :key="leg.id">
              <td class="px-3 py-2 text-ink-muted">{{ leg.orden }}</td>
              <td class="px-3 py-2">
                <p class="font-semibold text-ink"><span v-if="leg.codigo_origen_destino" class="mr-1 text-ink-muted">{{ leg.codigo_origen_destino }} ·</span>{{ leg.origen }} → {{ leg.destino }}</p>
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
          <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Código ruta anterior (opcional)</span><input type="number" name="codigo_origen_destino" min="1" step="1" :class="fieldClass" /></label>
          <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Localidad de origen</span><AutocompleteSelect v-model="newLegOriginId" name="localidad_origen_id" :options="localityOptions" :search-url="data.links?.localities" placeholder="Buscá la localidad" required /></label>
          <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Localidad de destino</span><AutocompleteSelect v-model="newLegDestinationId" name="localidad_destino_id" :options="localityOptions" :search-url="data.links?.localities" placeholder="Buscá la localidad" required /></label>
          <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Cliente</span><AutocompleteSelect name="cliente_id" :options="customerOptions" placeholder="Elegí el cliente" required /></label>
          <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Toneladas</span><input type="number" name="toneladas" min="0" step="0.01" :class="fieldClass" /></label>
          <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Km</span><input type="number" name="km" min="0" step="0.01" :class="fieldClass" /></label>
          <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Moneda</span><select name="moneda" :class="fieldClass"><option v-for="currency in currencies" :key="currency.code" :value="currency.code" :selected="currency.code === 'ARS'">{{ currency.code }} · {{ currency.label }}</option></select></label>
          <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Flete facturación</span><input type="number" name="flete_facturacion" min="0" step="0.01" required :class="fieldClass" /></label>
          <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Flete liquidación</span><input type="number" name="flete_liquidacion" min="0" step="0.01" required :class="fieldClass" /></label>
          <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Cotización a ARS</span><input type="number" name="cotizacion" min="0.000001" step="0.000001" value="1" :class="fieldClass" /></label>
          <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Observaciones</span><input type="text" name="observaciones" maxlength="500" :class="fieldClass" /></label>
        </div>
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
            <div class="flex items-center justify-between gap-3"><dt class="text-ink-muted">Anticipos y retiros</dt><dd class="font-semibold text-danger-strong">- {{ money(settlement.advances_ars) }}</dd></div>
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

    <div class="space-y-5">
      <PanelCard title="Combustible" :count="fuel.length">
        <form v-if="canRegisterCosts" method="post" :action="data.links?.fuel" class="mb-5 rounded-xl border border-border-subtle bg-surface p-4">
          <input type="hidden" :name="csrf.name" :value="csrf.hash" />
          <h3 class="flex items-center gap-2 text-sm font-bold text-ink"><BeakerIcon class="size-4 text-primary" aria-hidden="true" />Cargar combustible</h3>
          <p class="mt-1 text-xs text-ink-muted">Los litros y el odómetro permiten calcular el consumo del viaje.</p>
          <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Fecha y hora</span><input type="datetime-local" name="fecha" :value="nowLocal()" required :class="fieldClass" /></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Litros</span><input type="number" name="litros" min="0.01" step="0.01" required :class="fieldClass" /></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Importe</span><input type="number" name="importe" min="0.01" step="0.01" required :class="fieldClass" /></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Moneda</span><select name="moneda" :class="fieldClass"><option v-for="currency in currencies" :key="currency.code" :value="currency.code" :selected="currency.code === 'ARS'">{{ currency.code }} · {{ currency.label }}</option></select></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Cotización a ARS</span><input type="number" name="cotizacion" min="0.000001" step="0.000001" value="1" :class="fieldClass" /></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Odómetro</span><input type="number" name="kilometraje" min="1" step="1" :class="fieldClass" /></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Estación</span><input type="text" name="estacion" maxlength="120" :class="fieldClass" /></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Tramo (opcional)</span><select name="tramo_id" :class="fieldClass"><option value="">Todo el viaje</option><option v-for="leg in legs" :key="leg.id" :value="leg.id">{{ leg.orden }} · {{ leg.origen }} → {{ leg.destino }}</option></select></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Comprobante</span><input type="text" name="comprobante" maxlength="80" :class="fieldClass" /></label>
            <label class="block md:col-span-2"><span class="mb-1 block text-xs font-semibold text-ink-muted">Observaciones</span><input type="text" name="observaciones" maxlength="500" :class="fieldClass" /></label>
          </div>
          <button type="submit" :class="[primaryButton, 'mt-3']">Guardar combustible</button>
        </form>
        <p v-else class="mb-4 rounded-lg bg-surface p-3 text-sm text-ink-muted">El viaje está cancelado o finalizado; sus costos quedan de solo lectura.</p>
        <p v-if="fuel.length === 0" class="text-sm text-ink-muted">Todavía no hay cargas de combustible.</p>
        <div v-else class="overflow-x-auto">
          <table class="w-full min-w-[52rem] text-left text-sm">
            <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted"><tr><th class="px-3 py-2">Fecha</th><th class="px-3 py-2 text-right">Litros</th><th class="px-3 py-2 text-right">Importe</th><th class="px-3 py-2 text-right">ARS</th><th class="px-3 py-2 text-right">Odómetro</th><th class="px-3 py-2">Tramo</th><th class="px-3 py-2">Estación</th><th class="px-3 py-2">Comprobante</th><th class="px-3 py-2">Observaciones</th></tr></thead>
            <tbody class="divide-y divide-border-subtle">
              <tr v-for="load in fuel" :key="load.id"><td class="whitespace-nowrap px-3 py-2 text-ink-muted">{{ fmtDateTime(load.fecha) }}</td><td class="px-3 py-2 text-right text-ink">{{ number(load.litros) }}</td><td class="px-3 py-2 text-right text-ink-muted">{{ money(load.importe, load.moneda) }}</td><td class="px-3 py-2 text-right font-semibold text-ink">{{ money(load.importe_ars) }}</td><td class="px-3 py-2 text-right text-ink-muted">{{ load.kilometraje === null ? '—' : number(load.kilometraje) }}</td><td class="px-3 py-2 text-ink-muted">{{ legLabel(load.tramo_id) }}</td><td class="px-3 py-2 text-ink-muted">{{ load.estacion || '—' }}</td><td class="px-3 py-2 text-ink-muted">{{ load.comprobante || '—' }}</td><td class="px-3 py-2 text-ink-muted">{{ load.observaciones || '—' }}</td></tr>
            </tbody>
          </table>
        </div>
      </PanelCard>

      <PanelCard title="Gastos" :count="expenses.length">
        <form v-if="canRegisterCosts" method="post" :action="data.links?.expense" class="mb-5 rounded-xl border border-border-subtle bg-surface p-4">
          <input type="hidden" :name="csrf.name" :value="csrf.hash" />
          <h3 class="flex items-center gap-2 text-sm font-bold text-ink"><ReceiptPercentIcon class="size-4 text-primary" aria-hidden="true" />Cargar gasto</h3>
          <p class="mt-1 text-xs text-ink-muted">Peajes, viáticos, carga/descarga y otros gastos del viaje.</p>
          <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Categoría</span><select name="categoria_id" :class="fieldClass"><option value="">Sin categoría</option><option v-for="category in options.expenseCategories" :key="category.id" :value="category.id">{{ category.label }}</option></select></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Fecha y hora</span><input type="datetime-local" name="fecha" :value="nowLocal()" required :class="fieldClass" /></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Importe</span><input type="number" name="importe" min="0.01" step="0.01" required :class="fieldClass" /></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Moneda</span><select name="moneda" :class="fieldClass"><option v-for="currency in currencies" :key="currency.code" :value="currency.code" :selected="currency.code === 'ARS'">{{ currency.code }} · {{ currency.label }}</option></select></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Cotización a ARS</span><input type="number" name="cotizacion" min="0.000001" step="0.000001" value="1" :class="fieldClass" /></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Medio de pago</span><select name="medio_pago_id" :class="fieldClass"><option value="">Sin medio</option><option v-for="method in options.paymentMethods" :key="method.id" :value="method.id">{{ method.label }}</option></select></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Tramo (opcional)</span><select name="tramo_id" :class="fieldClass"><option value="">Todo el viaje</option><option v-for="leg in legs" :key="leg.id" :value="leg.id">{{ leg.orden }} · {{ leg.origen }} → {{ leg.destino }}</option></select></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Pagado por</span><select name="pagado_por" :class="fieldClass"><option value="EMPRESA">Empresa</option><option value="CHOFER">Chofer (a reintegrar)</option><option value="CAJA">Caja del chofer</option></select></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Comprobante</span><input type="text" name="comprobante" maxlength="80" :class="fieldClass" /></label>
            <label class="block md:col-span-2"><span class="mb-1 block text-xs font-semibold text-ink-muted">Observaciones</span><input type="text" name="observaciones" maxlength="500" :class="fieldClass" /></label>
          </div>
          <button type="submit" :class="[primaryButton, 'mt-3']">Guardar gasto</button>
        </form>
        <p v-else class="mb-4 rounded-lg bg-surface p-3 text-sm text-ink-muted">El viaje está cancelado o finalizado; sus costos quedan de solo lectura.</p>
        <p v-if="expenses.length === 0" class="text-sm text-ink-muted">Todavía no hay gastos cargados.</p>
        <div v-else class="overflow-x-auto">
          <table class="w-full min-w-[52rem] text-left text-sm">
            <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted"><tr><th class="px-3 py-2">Fecha</th><th class="px-3 py-2">Categoría</th><th class="px-3 py-2 text-right">Importe</th><th class="px-3 py-2 text-right">ARS</th><th class="px-3 py-2">Medio</th><th class="px-3 py-2">Tramo</th><th class="px-3 py-2">Pagado por</th><th class="px-3 py-2">Comprobante</th><th class="px-3 py-2">Observaciones</th></tr></thead>
            <tbody class="divide-y divide-border-subtle">
              <tr v-for="expense in expenses" :key="expense.id"><td class="whitespace-nowrap px-3 py-2 text-ink-muted">{{ fmtDateTime(expense.fecha) }}</td><td class="px-3 py-2 text-ink">{{ expense.categoria || 'Sin categoría' }}</td><td class="px-3 py-2 text-right text-ink-muted">{{ money(expense.importe, expense.moneda) }}</td><td class="px-3 py-2 text-right font-semibold text-ink">{{ money(expense.importe_ars) }}</td><td class="px-3 py-2 text-ink-muted">{{ expense.medio_pago || '—' }}</td><td class="px-3 py-2 text-ink-muted">{{ legLabel(expense.tramo_id) }}</td><td class="px-3 py-2 text-ink-muted">{{ paidByLabel[expense.pagado_por] ?? 'Empresa' }}</td><td class="px-3 py-2 text-ink-muted">{{ expense.comprobante || '—' }}</td><td class="px-3 py-2 text-ink-muted">{{ expense.observaciones || '—' }}</td></tr>
            </tbody>
          </table>
        </div>
      </PanelCard>

      <PanelCard title="Caja del chofer" :count="cashMovements.length">
        <form v-if="canRegisterCosts" method="post" :action="data.links?.cash" class="mb-5 rounded-xl border border-border-subtle bg-surface p-4">
          <input type="hidden" :name="csrf.name" :value="csrf.hash" />
          <h3 class="flex items-center gap-2 text-sm font-bold text-ink"><BanknotesIcon class="size-4 text-primary" aria-hidden="true" />Movimiento de caja</h3>
          <p class="mt-1 text-xs text-ink-muted">Anticipos y retiros no son costo del viaje. Las devoluciones reducen el saldo pendiente.</p>
          <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Tipo</span><select name="tipo" :class="fieldClass"><option value="ANTICIPO">Anticipo</option><option value="RETIRO">Retiro</option><option value="DEVOLUCION">Devolución</option></select></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Fecha y hora</span><input type="datetime-local" name="fecha" :value="nowLocal()" required :class="fieldClass" /></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Importe</span><input type="number" name="importe" min="0.01" step="0.01" required :class="fieldClass" /></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Moneda</span><select name="moneda" :class="fieldClass"><option v-for="currency in currencies" :key="currency.code" :value="currency.code" :selected="currency.code === 'ARS'">{{ currency.code }} · {{ currency.label }}</option></select></label>
            <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Cotización a ARS</span><input type="number" name="cotizacion" min="0.000001" step="0.000001" value="1" :class="fieldClass" /></label>
            <label class="block md:col-span-2"><span class="mb-1 block text-xs font-semibold text-ink-muted">Observaciones</span><input type="text" name="observaciones" maxlength="500" :class="fieldClass" /></label>
          </div>
          <button type="submit" :class="[primaryButton, 'mt-3']">Guardar movimiento</button>
        </form>
        <p v-else class="mb-4 rounded-lg bg-surface p-3 text-sm text-ink-muted">El viaje está cancelado o finalizado; la caja queda de solo lectura.</p>
        <p v-if="cashMovements.length === 0" class="text-sm text-ink-muted">Todavía no hay movimientos de caja.</p>
        <div v-else class="overflow-x-auto">
          <table class="w-full min-w-[42rem] text-left text-sm">
            <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted"><tr><th class="px-3 py-2">Fecha</th><th class="px-3 py-2">Tipo</th><th class="px-3 py-2 text-right">Importe</th><th class="px-3 py-2 text-right">ARS</th><th class="px-3 py-2">Observaciones</th></tr></thead>
            <tbody class="divide-y divide-border-subtle">
              <tr v-for="movement in cashMovements" :key="movement.id"><td class="whitespace-nowrap px-3 py-2 text-ink-muted">{{ fmtDateTime(movement.fecha) }}</td><td class="px-3 py-2 text-ink">{{ cashTypeLabel[movement.tipo] ?? movement.tipo }}</td><td class="px-3 py-2 text-right text-ink-muted">{{ money(movement.importe, movement.moneda) }}</td><td class="px-3 py-2 text-right font-semibold text-ink">{{ money(movement.importe_ars) }}</td><td class="px-3 py-2 text-ink-muted">{{ movement.observaciones || '—' }}</td></tr>
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
  </div>
</template>
