<script setup>
import { secondaryButton } from '../../ui/buttons.js'
import * as format from '../../ui/format.js'
/**
 * Liquidación del chofer (#533).
 *
 * Vista para que Administración revise el cierre junto al chofer. Recibe del
 * servidor solo la proyección segura de la liquidación: el flete de
 * facturación, el margen y la rentabilidad no llegan a esta página.
 */
import { computed } from 'vue'
import { PrinterIcon } from '@heroicons/vue/24/outline'
import PageHeading from './components/PageHeading.vue'
import PanelCard from './components/PanelCard.vue'

const props = defineProps({ data: { type: Object, required: true } })

const trip = computed(() => props.data.trip ?? {})
const totals = computed(() => props.data.totals ?? {})
const legs = computed(() => props.data.legs ?? [])
const otherEarnings = computed(() => props.data.otherEarnings ?? [])
const driverExpenses = computed(() => props.data.driverExpenses ?? [])
const cashMovements = computed(() => props.data.cashMovements ?? [])
const cashExpenses = computed(() => props.data.cashExpenses ?? [])
const draft = computed(() => props.data.status !== 'DEFINITIVA')
const available = computed(() => Boolean(props.data.available))

const money = (value, currency = 'ARS') => format.formatMoney(value ?? 0, { currency, cents: true })
const moneyOrNoData = (value, currency = 'ARS') => format.formatMoney(value, { currency, cents: true })
const number = (value, digits = 2) => Number(value ?? 0).toLocaleString('es-AR', { minimumFractionDigits: digits, maximumFractionDigits: digits })
const fmtDate = (value) => format.formatDate(value)
const legDetail = (leg) => {
  if (leg.modo !== 'POR_TONELADA') return 'Importe global'
  if (leg.toneladas === null || leg.toneladas === undefined || leg.precio_unitario === null || leg.precio_unitario === undefined) return 'Sin cargar'

  return `${number(leg.toneladas, 3)} t × ${money(leg.precio_unitario, leg.moneda)}`
}
/** Importe en su moneda; si no es ARS, se aclara el equivalente. */
const original = (row) => (row.moneda && row.moneda !== 'ARS' ? `${money(row.importe, row.moneda)} · cotización ${number(row.cotizacion, 4)}` : null)

const identity = computed(() => [
  ['Chofer', props.data.driver?.label ?? 'Sin chofer responsable'],
  ['Unidad', props.data.unit ?? 'Sin dato'],
  ['Sucursal', trip.value.sucursal ?? 'Sin dato'],
  ['Salida', fmtDate(trip.value.fecha_salida_real)],
  ['Regreso', trip.value.fecha_regreso_real ? fmtDate(trip.value.fecha_regreso_real) : 'Viaje sin cerrar'],
  ['Porcentaje del chofer', props.data.percentage === null || props.data.percentage === undefined ? 'Sin definir' : `${number(props.data.percentage)} %`],
])
</script>

<template>
  <div>
    <PageHeading eyebrow="Viajes · Liquidación" :title="`Liquidación del chofer · Viaje #${trip.numero ?? ''}`" description="Lo que corresponde al chofer por este viaje: base acordada, comisión, haberes, gastos, anticipos y saldo final." :back="{ href: data.links?.back ?? '/viajes/operativos', label: 'Volver al viaje' }">
      <template #actions>
        <a v-if="data.links?.pdf" :href="data.links.pdf" target="_blank" rel="noopener" data-testid="driver-settlement-pdf" :class="secondaryButton">
          <PrinterIcon class="size-4" aria-hidden="true" />{{ draft ? 'Ver o imprimir borrador (PDF)' : 'Ver o imprimir PDF' }}
        </a>
      </template>
    </PageHeading>

    <p data-testid="driver-settlement-status" class="mb-5 rounded-lg border px-4 py-3 text-sm font-semibold" :class="draft ? 'border-warning/40 bg-warning-subtle text-warning-strong' : 'border-success/40 bg-success-subtle text-success-strong'">
      {{ draft ? 'BORRADOR' : 'LIQUIDACIÓN DEFINITIVA' }} · {{ data.status_label }}
    </p>

    <PanelCard title="Viaje y chofer" class="mb-5">
      <dl class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
        <div v-for="[label, value] in identity" :key="label"><dt class="text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ label }}</dt><dd class="mt-0.5 font-semibold text-ink">{{ value }}</dd></div>
      </dl>
    </PanelCard>

    <p v-if="!available && data.pending" data-testid="driver-settlement-pending" class="mb-5 rounded-lg border border-info/30 bg-info-subtle px-3 py-2 text-sm text-info-strong">{{ data.pending }}</p>

    <PanelCard title="Base de liquidación y comisión por tramo" :count="legs.length" class="mb-5" flush>
      <p v-if="legs.length === 0" class="p-5 text-sm text-ink-muted">Todavía no hay tramos cargados.</p>
      <div v-else class="overflow-x-auto">
        <table class="w-full min-w-[52rem] text-left text-sm" data-testid="driver-settlement-legs">
          <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted">
            <tr>
              <th class="px-3 py-2">#</th>
              <th class="px-3 py-2">Recorrido</th>
              <th class="px-3 py-2">Modalidad</th>
              <th class="px-3 py-2">Detalle</th>
              <th class="px-3 py-2 text-right">Base</th>
              <th class="px-3 py-2 text-right">Base en ARS</th>
              <th class="px-3 py-2 text-right">Comisión</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border-subtle">
            <tr v-for="leg in legs" :key="leg.orden">
              <td class="px-3 py-2 text-ink-muted">{{ leg.orden }}</td>
              <td class="px-3 py-2 font-semibold text-ink">{{ leg.origen }} → {{ leg.destino }}</td>
              <td class="px-3 py-2"><span class="whitespace-nowrap rounded-full bg-surface-muted px-2 py-0.5 text-xs font-bold text-ink">{{ leg.modo_label }}</span></td>
              <td class="px-3 py-2 text-ink-muted">{{ legDetail(leg) }}</td>
              <td class="px-3 py-2 text-right text-ink">{{ moneyOrNoData(leg.importe, leg.moneda) }}<span v-if="leg.cotizacion" class="block text-xs text-ink-muted">Cotización pactada {{ number(leg.cotizacion, 4) }}</span></td>
              <td class="px-3 py-2 text-right text-ink">{{ moneyOrNoData(leg.importe_ars) }}</td>
              <td class="px-3 py-2 text-right font-semibold text-ink">{{ moneyOrNoData(leg.comision_ars) }}</td>
            </tr>
          </tbody>
          <tfoot class="border-t border-border-subtle text-sm">
            <tr><td colspan="5" class="px-3 py-2 text-right font-semibold text-ink-muted">Total</td><td class="px-3 py-2 text-right font-semibold text-ink">{{ moneyOrNoData(totals.base_ars) }}</td><td class="px-3 py-2 text-right font-bold text-ink">{{ moneyOrNoData(totals.commission_ars) }}</td></tr>
          </tfoot>
        </table>
      </div>
    </PanelCard>

    <div class="mb-5 grid gap-5 lg:grid-cols-2">
      <PanelCard title="Otros haberes" :count="otherEarnings.length">
        <p v-if="otherEarnings.length === 0" class="text-sm text-ink-muted">No hay otros haberes cargados.</p>
        <ul v-else class="divide-y divide-border-subtle text-sm">
          <li v-for="(row, index) in otherEarnings" :key="`earning-${index}`" class="flex items-start justify-between gap-3 py-2">
            <span><span class="block font-semibold text-ink">{{ row.concepto }}</span><span class="text-xs text-ink-muted">{{ fmtDate(row.fecha) }}<template v-if="original(row)"> · {{ original(row) }}</template></span></span>
            <span class="font-semibold text-ink">{{ money(row.importe_ars) }}</span>
          </li>
        </ul>
      </PanelCard>
      <PanelCard title="Gastos adelantados por el chofer" :count="driverExpenses.length">
        <p v-if="driverExpenses.length === 0" class="text-sm text-ink-muted">No hay gastos adelantados por el chofer.</p>
        <ul v-else class="divide-y divide-border-subtle text-sm">
          <li v-for="(row, index) in driverExpenses" :key="`expense-${index}`" class="flex items-start justify-between gap-3 py-2">
            <span><span class="block font-semibold text-ink">{{ row.concepto }}</span><span class="text-xs text-ink-muted">{{ fmtDate(row.fecha) }}<template v-if="row.comprobante"> · {{ row.comprobante }}</template><template v-if="original(row)"> · {{ original(row) }}</template></span></span>
            <span class="font-semibold text-ink">{{ money(row.importe_ars) }}</span>
          </li>
        </ul>
      </PanelCard>
    </div>

    <PanelCard title="Rendición de caja: anticipos, devoluciones y gastos con caja" class="mb-5">
      <div class="grid gap-5 lg:grid-cols-2">
        <div>
          <h3 class="mb-2 text-xs font-bold uppercase tracking-wide text-ink-muted">Movimientos de caja</h3>
          <p v-if="cashMovements.length === 0" class="text-sm text-ink-muted">No hubo movimientos de caja.</p>
          <ul v-else class="divide-y divide-border-subtle text-sm">
            <li v-for="(row, index) in cashMovements" :key="`cash-${index}`" class="flex items-start justify-between gap-3 py-2">
              <span><span class="block font-semibold text-ink">{{ row.tipo_label }}</span><span class="text-xs text-ink-muted">{{ fmtDate(row.fecha) }}<template v-if="original(row)"> · {{ original(row) }}</template></span></span>
              <span class="font-semibold text-ink">{{ money(row.importe_ars) }}</span>
            </li>
          </ul>
        </div>
        <div>
          <h3 class="mb-2 text-xs font-bold uppercase tracking-wide text-ink-muted">Gastos pagados con caja</h3>
          <p v-if="cashExpenses.length === 0" class="text-sm text-ink-muted">No hay gastos pagados con caja.</p>
          <ul v-else class="divide-y divide-border-subtle text-sm">
            <li v-for="(row, index) in cashExpenses" :key="`cash-expense-${index}`" class="flex items-start justify-between gap-3 py-2">
              <span><span class="block font-semibold text-ink">{{ row.concepto }}</span><span class="text-xs text-ink-muted">{{ fmtDate(row.fecha) }}<template v-if="row.comprobante"> · {{ row.comprobante }}</template></span></span>
              <span class="font-semibold text-ink">{{ money(row.importe_ars) }}</span>
            </li>
          </ul>
        </div>
      </div>
      <p class="mt-4 text-sm text-ink-muted">Saldo de caja pendiente de rendir: <span class="font-semibold text-ink">{{ money(totals.cash_balance_ars) }}</span></p>
    </PanelCard>

    <PanelCard title="Saldo final" class="mb-5">
      <dl v-if="available" class="space-y-2 text-sm" data-testid="driver-settlement-summary">
        <div class="flex items-center justify-between gap-3"><dt class="text-ink-muted">Comisión</dt><dd class="font-semibold text-ink">{{ money(totals.commission_ars) }}</dd></div>
        <div class="flex items-center justify-between gap-3"><dt class="text-ink-muted">Otros haberes</dt><dd class="font-semibold text-ink">{{ money(totals.other_earnings_ars) }}</dd></div>
        <div class="flex items-center justify-between gap-3"><dt class="text-ink-muted">Gastos adelantados por el chofer</dt><dd class="font-semibold text-ink">{{ money(totals.driver_expenses_ars) }}</dd></div>
        <div class="flex items-center justify-between gap-3"><dt class="text-ink-muted">Gastos pagados con caja</dt><dd class="font-semibold text-ink">{{ money(totals.cash_expenses_ars) }}</dd></div>
        <div class="flex items-center justify-between gap-3"><dt class="text-ink-muted">Caja inicial, anticipos y adelantos de sueldo</dt><dd class="font-semibold text-danger-strong">- {{ money(totals.advances_ars) }}</dd></div>
        <div class="flex items-center justify-between gap-3"><dt class="text-ink-muted">Devoluciones</dt><dd class="font-semibold text-ink">{{ money(totals.returns_ars) }}</dd></div>
        <div class="flex items-center justify-between gap-3 border-t border-border-subtle pt-3"><dt class="text-base font-bold text-ink">{{ draft ? 'Saldo final (borrador)' : 'Saldo final de liquidación' }}</dt><dd class="text-xl font-extrabold" :class="totals.total_ars < 0 ? 'text-danger-strong' : 'text-primary'" data-testid="driver-settlement-total">{{ money(totals.total_ars) }}</dd></div>
        <p class="text-xs text-ink-muted">Importe positivo: a cobrar por el chofer. Importe negativo: saldo a rendir por el chofer.</p>
      </dl>
      <p v-else class="text-sm text-ink-muted">El saldo final se calcula cuando estén completos el chofer responsable, su porcentaje y las bases de liquidación de todos los tramos.</p>
    </PanelCard>
  </div>
</template>
