<script setup>
import * as format from '../../ui/format.js'
/**
 * Control de cierre comercial (#530).
 *
 * Lista los tramos de viajes finalizados y en qué quedó su cobro. Cerrar el
 * viaje no genera deuda: el cargo en la cuenta corriente nace recién cuando
 * Administración formaliza el tramo por memo, factura o cierre interno, con la
 * cotización de ese momento. La estimación del viaje se muestra solo como
 * referencia.
 */
import { computed, nextTick, ref } from 'vue'
import PageHeading from './components/PageHeading.vue'
import PanelCard from './components/PanelCard.vue'
import { fieldClass, primaryButton, secondaryButton, dangerButton } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })

const csrf = computed(() => props.data.csrf ?? {})
const links = computed(() => props.data.links ?? {})
const rows = computed(() => props.data.rows ?? [])
const counts = computed(() => props.data.counts ?? {})
const status = computed(() => props.data.status ?? 'PENDIENTE')
const canFormalize = computed(() => Boolean(props.data.canFormalize))
const canOperateTrips = computed(() => Boolean(props.data.canOperateTrips))
const hasActions = computed(() => canFormalize.value || (status.value === 'SIN_CLIENTE' && canOperateTrips.value))
const currentRates = computed(() => props.data.currentRates ?? {})
const currencies = computed(() => props.data.currencies ?? ['ARS'])
const modalities = computed(() => props.data.modalities ?? [])
const invoiceTypes = computed(() => props.data.invoiceTypes ?? [])

const tabs = [
  { key: 'PENDIENTE', label: 'Pendientes' },
  { key: 'SIN_CLIENTE', label: 'Sin cliente' },
  { key: 'FORMALIZADO', label: 'Formalizados' },
  { key: 'NO_COBRABLE', label: 'No cobrables' },
]
const tabUrl = (key) => `${links.value.queue ?? ''}?estado=${key}`
const tabLabels = Object.fromEntries(tabs.map((tab) => [tab.key, tab.label]))

const signedMoney = (value) => `${value > 0 ? '+' : '−'}${money(Math.abs(value))}`
const money = (value, currency = 'ARS') => format.formatMoney(value ?? 0, { currency, cents: true })
const rateText = (value) => Number(value ?? 0).toLocaleString('es-AR', { maximumFractionDigits: 6 })
const fmtDate = (value) => format.formatDate(value)
const today = () => {
  const now = new Date()
  const pad = (n) => String(n).padStart(2, '0')

  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`
}
const estimate = (row) => {
  if (row.flete_estimado === null || row.flete_estimado === undefined) return 'Sin flete cargado'
  if (row.moneda === 'ARS') return money(row.flete_estimado)

  return `${money(row.flete_estimado, row.moneda)} · est. ${money(row.flete_estimado_ars)}`
}

// Formalizar
const formalizeDialog = ref(null)
const selected = ref(null)
const form = ref({})
const openFormalize = (row) => {
  selected.value = row
  const currency = row.moneda ?? 'ARS'
  form.value = {
    modalidad: 'MEMO_AUTORIZADO',
    documento_fecha: today(),
    documento_numero: '',
    documento_tipo: 'FACTURA_A',
    importe: row.flete_estimado ?? '',
    moneda: currency,
    cotizacion: currency === 'ARS' ? '' : (currentRates.value[currency] ?? ''),
    observaciones: '',
  }
  nextTick(() => formalizeDialog.value?.showModal())
}
const isPesos = computed(() => form.value.moneda === 'ARS')
const previewArs = computed(() => {
  const amount = Number(form.value.importe)
  const rate = isPesos.value ? 1 : Number(form.value.cotizacion)
  if (!(amount > 0) || !(rate > 0)) return null

  return Math.round(amount * rate * 100) / 100
})
const previewDifference = computed(() => {
  const estimated = selected.value?.flete_estimado_ars
  if (previewArs.value === null || estimated === null || estimated === undefined) return null

  return Math.round((previewArs.value - Number(estimated)) * 100) / 100
})
const onCurrencyChange = () => {
  form.value.cotizacion = isPesos.value ? '' : (currentRates.value[form.value.moneda] ?? '')
}
const documentHelp = computed(() => ({
  MEMO_AUTORIZADO: 'La deuda nace con el memo autorizado, a la cotización de su fecha. Si después se factura, la factura no vuelve a generar deuda.',
  FACTURA_EMITIDA: 'La deuda nace con la factura emitida, a la cotización de su fecha de emisión. Registrá la factura hecha en el sistema de facturación.',
  CIERRE_COMERCIAL_APROBADO: 'Para viajes cobrables sin memo ni factura. El sistema emite una liquidación interna no fiscal con número propio.',
}[form.value.modalidad] ?? ''))

// No cobrable, reabrir y anular comparten un diálogo con motivo.
const reasonDialog = ref(null)
const reasonAction = ref(null)
const openReason = (kind, row) => {
  const config = {
    'no-cobrable': { title: 'Marcar el tramo como no cobrable', help: 'El tramo se cierra sin generar deuda. Es distinto de "sin factura": si se cobra por memo o por cierre interno, formalizalo.', submit: 'Marcar no cobrable', url: `${links.value.legsBase}${row.tramo_id}/no-cobrable`, danger: true },
    reabrir: { title: 'Reabrir el tramo', help: 'El tramo vuelve a pendientes de formalizar. Queda registrado quién lo reabrió y por qué.', submit: 'Reabrir', url: `${links.value.legsBase}${row.tramo_id}/reabrir`, danger: false },
    anular: { title: 'Anular el cargo', help: 'Se agrega un contraasiento en la cuenta corriente y el tramo vuelve a pendientes. El cargo original queda visible como anulado.', submit: 'Anular cargo', url: `${links.value.chargesBase}${row.cargo?.id}/anular`, danger: true },
  }[kind]
  reasonAction.value = { ...config, row }
  nextTick(() => reasonDialog.value?.showModal())
}
</script>

<template>
  <div>
    <PageHeading eyebrow="Viajes · Cuenta corriente" title="Cierre comercial" description="Tramos de viajes finalizados y en qué quedó su cobro. Cerrar el viaje no genera deuda: el cargo nace al formalizar cada tramo." :back="{ href: links.accounts ?? '/clientes/cuenta-corriente', label: 'Ver cuenta corriente de clientes' }" />

    <nav class="mb-4 flex flex-wrap gap-2" aria-label="Estado comercial" data-testid="closing-tabs">
      <a v-for="tab in tabs" :key="tab.key" :href="tabUrl(tab.key)" :aria-current="status === tab.key ? 'page' : undefined" class="inline-flex min-h-10 items-center gap-2 rounded-lg border px-3 py-2 text-sm font-semibold" :class="status === tab.key ? 'border-primary bg-primary-subtle text-primary' : 'border-border bg-surface-raised text-ink hover:bg-surface-muted'">
        {{ tab.label }}<span class="rounded-full bg-surface-muted px-2 py-0.5 text-xs text-ink-muted">{{ counts[tab.key] ?? 0 }}</span>
      </a>
    </nav>

    <form method="get" :action="links.queue" class="mb-4 flex flex-wrap gap-2">
      <input type="hidden" name="estado" :value="status" />
      <input type="search" name="q" :value="data.search ?? ''" placeholder="Buscar cliente, origen, destino o N° de viaje" :class="[fieldClass, 'max-w-md']" />
      <button type="submit" :class="secondaryButton">Buscar</button>
    </form>

    <PanelCard :title="tabLabels[status] ?? 'Tramos'" :count="rows.length" flush>
      <p v-if="rows.length === 0" class="p-5 text-sm text-ink-muted" data-testid="closing-empty">No hay tramos en este estado.</p>
      <ul v-else class="divide-y divide-border-subtle sm:hidden" data-testid="closing-cards">
        <li v-for="row in rows" :key="row.tramo_id" class="space-y-2 px-4 py-3 text-sm" :data-testid="`closing-card-${row.tramo_id}`">
          <div class="flex items-start justify-between gap-3">
            <div>
              <a :href="`${links.tripsBase}/${row.viaje_id}`" class="font-semibold text-primary hover:underline">Viaje #{{ row.numero }} · Tramo {{ row.orden }}</a>
              <p class="text-ink">{{ row.origen }} → {{ row.destino }}</p>
              <p :class="row.cliente ? 'text-ink-muted' : 'text-ink-muted italic'">{{ row.cliente ?? 'Sin cliente' }} · regreso {{ fmtDate(row.fecha_regreso_real) }}</p>
            </div>
            <div class="whitespace-nowrap text-right">
              <template v-if="row.cargo">
                <p class="font-semibold text-ink">{{ money(row.cargo.importe_ars) }}</p>
                <p v-if="row.cargo.moneda !== 'ARS'" class="text-xs text-ink-muted">{{ money(row.cargo.importe, row.cargo.moneda) }} × {{ rateText(row.cargo.cotizacion) }}</p>
              </template>
              <p v-else class="text-xs text-ink-muted">{{ estimate(row) }}</p>
            </div>
          </div>
          <p v-if="row.cargo" class="text-xs text-ink-muted">{{ row.cargo.modalidad_label }} {{ row.cargo.documento_numero ?? '' }} · {{ fmtDate(row.cargo.documento_fecha) }}</p>
          <p v-else-if="row.motivo_no_cobrable" class="text-xs text-ink-muted">Motivo: {{ row.motivo_no_cobrable }}</p>
          <div v-if="hasActions" class="flex flex-wrap gap-2">
            <template v-if="status === 'PENDIENTE' && canFormalize">
              <button type="button" :class="primaryButton" @click="openFormalize(row)">Formalizar</button>
              <button type="button" :class="secondaryButton" @click="openReason('no-cobrable', row)">No cobrable</button>
            </template>
            <template v-else-if="status === 'SIN_CLIENTE'">
              <a v-if="canOperateTrips" :href="`${links.tripsBase}/${row.viaje_id}`" :class="secondaryButton">Cargar cliente en el viaje</a>
              <button v-if="canFormalize" type="button" :class="secondaryButton" @click="openReason('no-cobrable', row)">No cobrable</button>
            </template>
            <button v-else-if="status === 'FORMALIZADO' && canFormalize && row.cargo" type="button" :class="secondaryButton" @click="openReason('anular', row)">Anular</button>
            <button v-else-if="status === 'NO_COBRABLE' && canFormalize" type="button" :class="secondaryButton" @click="openReason('reabrir', row)">Reabrir</button>
          </div>
        </li>
      </ul>
      <div v-if="rows.length > 0" class="hidden overflow-x-auto sm:block">
        <table class="ui-table w-full min-w-[60rem] text-left text-sm" data-testid="closing-table">
          <thead class="text-xs font-semibold text-ink-subtle">
            <tr>
              <th class="px-3 py-2">Viaje</th>
              <th class="px-3 py-2">Recorrido</th>
              <th class="px-3 py-2">Cliente</th>
              <th class="px-3 py-2">Regreso</th>
              <th class="px-3 py-2 text-right">{{ status === 'FORMALIZADO' ? 'Cargo en ARS' : 'Flete estimado' }}</th>
              <th class="px-3 py-2">{{ status === 'FORMALIZADO' ? 'Documento' : (status === 'NO_COBRABLE' ? 'Motivo' : '') }}</th>
              <th v-if="hasActions" class="px-3 py-2">Acciones</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border-subtle">
            <tr v-for="row in rows" :key="row.tramo_id" :data-testid="`closing-row-${row.tramo_id}`">
              <td class="px-3 py-2"><a :href="`${links.tripsBase}/${row.viaje_id}`" class="font-semibold text-primary hover:underline">#{{ row.numero }}</a><span class="block text-xs text-ink-muted">Tramo {{ row.orden }}<template v-if="row.sucursal"> · {{ row.sucursal }}</template></span></td>
              <td class="px-3 py-2 text-ink">{{ row.origen }} → {{ row.destino }}</td>
              <td class="px-3 py-2" :class="row.cliente ? 'text-ink' : 'text-ink-muted'">{{ row.cliente ?? 'Sin cliente' }}</td>
              <td class="px-3 py-2 text-ink-muted">{{ fmtDate(row.fecha_regreso_real) }}</td>
              <td class="whitespace-nowrap px-3 py-2 text-right">
                <template v-if="row.cargo">
                  <span class="font-semibold text-ink">{{ money(row.cargo.importe_ars) }}</span>
                  <span v-if="row.cargo.moneda !== 'ARS'" class="block text-xs text-ink-muted">{{ money(row.cargo.importe, row.cargo.moneda) }} × {{ rateText(row.cargo.cotizacion) }}</span>
                </template>
                <span v-else class="text-ink-muted">{{ estimate(row) }}</span>
              </td>
              <td class="px-3 py-2 text-ink-muted">
                <template v-if="row.cargo">{{ row.cargo.modalidad_label }}<span class="block text-xs">{{ row.cargo.documento_numero ?? '' }} · {{ fmtDate(row.cargo.documento_fecha) }}</span></template>
                <template v-else-if="row.motivo_no_cobrable">{{ row.motivo_no_cobrable }}</template>
              </td>
              <td v-if="hasActions" class="whitespace-nowrap px-3 py-2">
                <div class="flex gap-2">
                  <template v-if="status === 'PENDIENTE' && canFormalize">
                    <button type="button" :class="primaryButton" @click="openFormalize(row)">Formalizar</button>
                    <button type="button" :class="secondaryButton" @click="openReason('no-cobrable', row)">No cobrable</button>
                  </template>
                  <template v-else-if="status === 'SIN_CLIENTE'">
                    <a v-if="canOperateTrips" :href="`${links.tripsBase}/${row.viaje_id}`" :class="secondaryButton">Cargar cliente en el viaje</a>
                    <button v-if="canFormalize" type="button" :class="secondaryButton" @click="openReason('no-cobrable', row)">No cobrable</button>
                  </template>
                  <button v-else-if="status === 'FORMALIZADO' && canFormalize && row.cargo" type="button" :class="secondaryButton" @click="openReason('anular', row)">Anular</button>
                  <button v-else-if="status === 'NO_COBRABLE' && canFormalize" type="button" :class="secondaryButton" @click="openReason('reabrir', row)">Reabrir</button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </PanelCard>

    <dialog v-if="canFormalize" ref="formalizeDialog" aria-labelledby="formalize-title" @click.self="formalizeDialog?.close()" class="m-auto w-[min(48rem,calc(100vw-2rem))] max-h-[calc(100vh-2rem)] overflow-y-auto rounded-card bg-surface-raised p-0 text-ink shadow-xl backdrop:bg-black/60">
      <form v-if="selected" method="post" :action="`${links.legsBase}${selected.tramo_id}/formalizar`" class="p-5 sm:p-6" data-testid="formalize-form">
        <input type="hidden" :name="csrf.name" :value="csrf.hash" />
        <input type="hidden" name="volver" :value="data.returnTo" />
        <h2 id="formalize-title" class="text-lg font-bold text-ink">Formalizar el cobro · Viaje #{{ selected.numero }} · Tramo {{ selected.orden }}</h2>
        <p class="mt-1 text-sm text-ink-muted">{{ selected.cliente }} · {{ selected.origen }} → {{ selected.destino }} · Estimado del viaje: {{ estimate(selected) }}</p>

        <div class="mt-4 grid gap-3 sm:grid-cols-2">
          <label class="block sm:col-span-2"><span class="mb-1 block text-xs font-semibold text-ink-muted">Cómo se formaliza</span>
            <select v-model="form.modalidad" name="modalidad" :class="fieldClass" required>
              <option v-for="option in modalities" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
            <span class="mt-1 block text-xs text-ink-muted">{{ documentHelp }}</span>
          </label>
          <label v-if="form.modalidad === 'FACTURA_EMITIDA'" class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Tipo de factura</span>
            <select v-model="form.documento_tipo" name="documento_tipo" :class="fieldClass" required>
              <option v-for="type in invoiceTypes" :key="type" :value="type">{{ type.replace('_', ' ').replace('FACTURA', 'Factura') }}</option>
            </select>
          </label>
          <label v-if="form.modalidad !== 'CIERRE_COMERCIAL_APROBADO'" class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">{{ form.modalidad === 'FACTURA_EMITIDA' ? 'Punto de venta y número' : 'Número o referencia del memo' }}</span>
            <input v-model="form.documento_numero" type="text" name="documento_numero" maxlength="60" required :class="fieldClass" />
          </label>
          <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">{{ form.modalidad === 'FACTURA_EMITIDA' ? 'Fecha de emisión' : (form.modalidad === 'MEMO_AUTORIZADO' ? 'Fecha de autorización del memo' : 'Fecha de aprobación') }}</span>
            <input v-model="form.documento_fecha" type="date" name="documento_fecha" :max="today()" required :class="fieldClass" />
          </label>
          <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Importe del documento</span>
            <input v-model="form.importe" type="number" name="importe" min="0.01" step="0.01" required :class="fieldClass" />
          </label>
          <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Moneda</span>
            <select v-model="form.moneda" name="moneda" :class="fieldClass" @change="onCurrencyChange">
              <option v-for="currency in currencies" :key="currency" :value="currency">{{ currency }}</option>
            </select>
          </label>
          <label v-if="!isPesos" class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Cotización definitiva a ARS</span>
            <input v-model="form.cotizacion" type="number" name="cotizacion" min="0.000001" step="0.000001" required :class="fieldClass" />
            <span class="mt-1 block text-xs text-ink-muted">La de la fecha del documento. Queda congelada: si el {{ form.moneda }} cambia después, el cargo no se recalcula.</span>
          </label>
          <label class="block sm:col-span-2"><span class="mb-1 block text-xs font-semibold text-ink-muted">Observaciones</span>
            <input v-model="form.observaciones" type="text" name="observaciones" maxlength="500" :class="fieldClass" />
          </label>
        </div>

        <div class="mt-4 rounded-lg border border-info/30 bg-info-subtle px-3 py-2 text-sm text-info-strong" data-testid="formalize-preview">
          <template v-if="previewArs !== null">
            Se registra un cargo de <strong>{{ money(previewArs) }}</strong> en la cuenta corriente de {{ selected.cliente }}.
            <span v-if="previewDifference !== null && previewDifference !== 0" class="block text-xs">Diferencia con la estimación del viaje: {{ signedMoney(previewDifference) }}.</span>
          </template>
          <template v-else>Completá el importe{{ isPesos ? '' : ' y la cotización' }} para ver el cargo en ARS.</template>
        </div>

        <div class="mt-5 flex justify-end gap-2 border-t border-border-subtle pt-4">
          <button type="button" :class="secondaryButton" @click="formalizeDialog?.close()">Volver</button>
          <button type="submit" :class="primaryButton">Formalizar y registrar cargo</button>
        </div>
      </form>
    </dialog>

    <dialog v-if="canFormalize" ref="reasonDialog" aria-labelledby="reason-title" @click.self="reasonDialog?.close()" class="m-auto w-[min(36rem,calc(100vw-2rem))] rounded-card bg-surface-raised p-0 text-ink shadow-xl backdrop:bg-black/60">
      <form v-if="reasonAction" method="post" :action="reasonAction.url" class="p-5 sm:p-6" data-testid="reason-form">
        <input type="hidden" :name="csrf.name" :value="csrf.hash" />
        <input type="hidden" name="volver" :value="data.returnTo" />
        <h2 id="reason-title" class="text-lg font-bold text-ink">{{ reasonAction.title }}</h2>
        <p class="mt-1 text-sm text-ink-muted">Viaje #{{ reasonAction.row.numero }} · Tramo {{ reasonAction.row.orden }} · {{ reasonAction.row.cliente ?? 'Sin cliente' }}</p>
        <p class="mt-3 text-sm text-ink-muted">{{ reasonAction.help }}</p>
        <label class="mt-3 block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Motivo</span>
          <textarea name="motivo" rows="3" minlength="5" maxlength="500" required :class="fieldClass"></textarea>
        </label>
        <div class="mt-5 flex justify-end gap-2 border-t border-border-subtle pt-4">
          <button type="button" :class="secondaryButton" @click="reasonDialog?.close()">Volver</button>
          <button type="submit" :class="reasonAction.danger ? dangerButton : primaryButton">{{ reasonAction.submit }}</button>
        </div>
      </form>
    </dialog>
  </div>
</template>
