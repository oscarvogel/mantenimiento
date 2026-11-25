<script setup>
/**
 * Estado de cuenta de un cliente (#530): movimientos en ARS con saldo
 * acumulado. Cada cargo muestra el importe, la moneda y la cotización con que
 * nació; un cargo anulado queda visible junto a su contraasiento.
 */
import { computed, nextTick, ref } from 'vue'
import PageHeading from './components/PageHeading.vue'
import PanelCard from './components/PanelCard.vue'
import { fieldClass, primaryButton, secondaryButton, dangerButton } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })

const csrf = computed(() => props.data.csrf ?? {})
const links = computed(() => props.data.links ?? {})
const customer = computed(() => props.data.customer ?? {})
const movements = computed(() => props.data.movements ?? [])
const totals = computed(() => props.data.totals ?? {})
const canFormalize = computed(() => Boolean(props.data.canFormalize))
const canRegisterReceipt = computed(() => Boolean(props.data.canRegisterReceipt))
const canImpute = computed(() => Boolean(props.data.canImpute))
const canPrintReceipt = computed(() => Boolean(props.data.canPrintReceipt))
const canCancelReceipt = computed(() => Boolean(props.data.canCancelReceipt))
const collection = computed(() => props.data.summary ?? {})
const pendingDocuments = computed(() => props.data.pendingDocuments ?? [])
const recentReceipts = computed(() => props.data.receipts ?? [])
const advances = computed(() => props.data.advances ?? [])
const pendingInstruments = computed(() => props.data.pendingInstruments ?? [])
const currencies = computed(() => props.data.currencies ?? ['ARS'])
const currentRates = computed(() => props.data.currentRates ?? {})

const money = (value, currency = 'ARS') => `${currency} ${Number(value ?? 0).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
const rateText = (value) => Number(value ?? 0).toLocaleString('es-AR', { maximumFractionDigits: 6 })
const fmtDate = (value) => {
  const raw = String(value ?? '').slice(0, 10)
  if (!raw) return '—'
  const [year, month, day] = raw.split('-')

  return `${day}/${month}/${year}`
}
const today = () => {
  const now = new Date()
  const pad = (n) => String(n).padStart(2, '0')

  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`
}
const balanceClass = (value) => (value > 0 ? 'text-danger-strong' : (value < 0 ? 'text-success-strong' : 'text-ink-muted'))
const balanceLabel = computed(() => {
  const value = Number(totals.value.saldo_ars ?? 0)
  if (value > 0) return 'Saldo a cobrar'
  if (value < 0) return 'Saldo a favor del cliente'

  return 'Cuenta saldada'
})
const sourceLabel = (source) => ({ CATALOGO_EMPRESA: 'catálogo de la empresa', MANUAL: 'informada', PESOS: 'pesos' }[source] ?? source)
const origin = (row) => {
  if (!row.moneda || row.tipo === 'ANULACION') return null
  if (row.moneda === 'ARS') return money(row.importe_original)

  return `${money(row.importe_original, row.moneda)} × ${rateText(row.cotizacion)} (${sourceLabel(row.cotizacion_fuente)}, ${fmtDate(row.cotizacion_fecha)})`
}

// Saldo inicial
const openingDialog = ref(null)
const opening = ref({})
const openOpening = () => {
  opening.value = { sentido: 'DEBE', fecha: today(), importe: '', moneda: 'ARS', cotizacion: '', referencia: '', observaciones: '' }
  nextTick(() => openingDialog.value?.showModal())
}
const onOpeningCurrency = () => {
  opening.value.cotizacion = opening.value.moneda === 'ARS' ? '' : (currentRates.value[opening.value.moneda] ?? '')
}

const openingPreviewArs = computed(() => {
  const amount = Number(opening.value.importe)
  const rate = opening.value.moneda === 'ARS' ? 1 : Number(opening.value.cotizacion)
  if (!(amount > 0) || !(rate > 0)) return null

  return Math.round(amount * rate * 100) / 100
})
// El asiento de saldo inicial se describe "Saldo inicial <referencia>": al lado del
// tipo, mostrar solo la referencia. El resto de las descripciones va completa.
const detail = (row) => {
  const text = String(row.descripcion ?? '')
  if (row.tipo !== 'SALDO_INICIAL' || !text.startsWith('Saldo inicial')) return text

  return text.slice('Saldo inicial'.length).trim() || 'Sin referencia'
}

// Anular
const annulDialog = ref(null)
const annulling = ref(null)
const openAnnul = (row) => {
  annulling.value = row
  nextTick(() => annulDialog.value?.showModal())
}

// Imputar anticipos disponibles a documentos pendientes.
const imputeDialog = ref(null)
const impute = ref({ recibo_id: '', cargo_id: '', importe: '' })
const openImpute = (advance = null) => {
  impute.value = { recibo_id: String(advance?.id ?? advances.value[0]?.id ?? ''), cargo_id: '', importe: '' }
  nextTick(() => imputeDialog.value?.showModal())
}
const selectedAdvance = computed(() => advances.value.find((advance) => String(advance.id) === String(impute.value.recibo_id)) ?? null)
const selectedDocument = computed(() => pendingDocuments.value.find((document) => String(document.id) === String(impute.value.cargo_id)) ?? null)
const applyImputeAll = () => {
  if (!selectedDocument.value || !selectedAdvance.value) return
  const available = Number(selectedAdvance.value.disponible_ars ?? 0)
  const pending = Number(selectedDocument.value.pendiente_ars ?? 0)
  impute.value.importe = Math.min(available, pending).toFixed(2)
}
const statusClass = (status) => ({
  PENDIENTE: 'bg-warning-subtle text-warning-strong',
  PARCIAL: 'bg-info-subtle text-info-strong',
  COBRADO: 'bg-success-subtle text-success-strong',
}[status] ?? 'bg-surface-muted text-ink-muted')

// Anular un recibo desde la cuenta corriente.
const receiptAnnulDialog = ref(null)
const annullingReceipt = ref(null)
const openReceiptAnnul = (receipt) => {
  annullingReceipt.value = receipt
  nextTick(() => receiptAnnulDialog.value?.showModal())
}
</script>

<template>
  <div>
    <PageHeading eyebrow="Clientes · Cuenta corriente" :title="customer.nombre ?? 'Cliente'" :description="customer.identificacion_fiscal ? `CUIT/ID fiscal ${customer.identificacion_fiscal} · Estado de cuenta en pesos` : 'Estado de cuenta en pesos'" :back="{ href: links.accounts ?? '/clientes/cuenta-corriente', label: 'Volver a cuenta corriente' }">
      <template #actions>
        <a v-if="canRegisterReceipt" :href="`${links.newReceiptBase}${customer.id}`" :class="primaryButton" data-testid="new-receipt">Nuevo recibo</a>
        <a :href="`${links.receipts ?? '/clientes/recibos'}?q=${encodeURIComponent(customer.nombre ?? '')}`" :class="secondaryButton">Ver recibos</a>
        <button v-if="canImpute && advances.length" type="button" :class="secondaryButton" data-testid="open-impute-advance" @click="openImpute()">Imputar anticipos</button>
        <button v-if="canFormalize && !data.hasOpeningBalance" type="button" :class="secondaryButton" data-testid="open-opening-balance" @click="openOpening">Cargar saldo inicial</button>
      </template>
    </PageHeading>

    <section aria-label="Saldo" class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
      <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card"><p class="text-xs font-semibold text-ink-muted">Debe</p><p class="mt-2 text-xl font-bold text-ink">{{ money(totals.debe_ars) }}</p></article>
      <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card"><p class="text-xs font-semibold text-ink-muted">Haber</p><p class="mt-2 text-xl font-bold text-ink">{{ money(totals.haber_ars) }}</p></article>
      <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card"><p class="text-xs font-semibold text-ink-muted">{{ balanceLabel }}</p><p class="mt-2 text-xl font-bold" :class="balanceClass(totals.saldo_ars)" data-testid="statement-balance">{{ money(totals.saldo_ars) }}</p></article>
    </section>

    <section aria-label="Cobranzas" class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card"><p class="text-xs font-semibold text-ink-muted">Saldo deudor</p><p class="mt-2 text-xl font-bold text-danger-strong">{{ money(collection.saldo_deudor_ars) }}</p></article>
      <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card"><p class="text-xs font-semibold text-ink-muted">Saldo a favor</p><p class="mt-2 text-xl font-bold text-success-strong">{{ money(collection.saldo_favor_ars) }}</p></article>
      <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card"><p class="text-xs font-semibold text-ink-muted">Total cobrado</p><p class="mt-2 text-xl font-bold text-ink" data-testid="collection-collected">{{ money(collection.total_cobrado_ars) }}</p></article>
      <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card"><p class="text-xs font-semibold text-ink-muted">Anticipos disponibles</p><p class="mt-2 text-xl font-bold text-ink" data-testid="collection-advances">{{ money(collection.anticipos_disponibles_ars) }}</p><p v-if="Number(collection.medios_pendientes_ars) > 0" class="text-xs text-warning-strong">{{ money(collection.medios_pendientes_ars) }} en medios pendientes</p></article>
    </section>

    <div class="mb-5 grid gap-4 lg:grid-cols-2">
      <PanelCard title="Documentos pendientes de cobro" :count="pendingDocuments.length">
        <p v-if="pendingDocuments.length === 0" class="text-sm text-ink-muted" data-testid="pending-documents-empty">No hay documentos a cobrar. Formalizá tramos desde el cierre comercial o cargá un saldo inicial.</p>
        <ul v-else class="divide-y divide-border-subtle text-sm" data-testid="pending-documents">
          <li v-for="document in pendingDocuments" :key="document.id" class="flex items-start justify-between gap-3 py-2">
            <div>
              <p class="font-semibold text-ink">{{ document.documento }}</p>
              <p class="text-xs text-ink-muted">{{ fmtDate(document.documento_fecha) }} · {{ document.modalidad_label }}</p>
              <p v-if="document.recibos.length" class="text-xs text-ink-muted">Cancelado por: {{ document.recibos.map((receipt) => receipt.numero_visible).join(', ') }}</p>
            </div>
            <div class="text-right">
              <p class="font-semibold text-ink">{{ money(document.pendiente_ars) }}</p>
              <span class="mt-1 inline-flex rounded-full px-2 py-0.5 text-xs font-semibold" :class="statusClass(document.estado_cobranza)">{{ document.estado_cobranza_label }}</span>
              <p class="text-xs text-ink-muted">de {{ money(document.importe_ars) }}</p>
            </div>
          </li>
        </ul>
      </PanelCard>

      <PanelCard title="Recibos recientes" :count="recentReceipts.length">
        <p v-if="recentReceipts.length === 0" class="text-sm text-ink-muted">Todavía no hay recibos confirmados.</p>
        <ul v-else class="divide-y divide-border-subtle text-sm" data-testid="recent-receipts">
          <li v-for="receipt in recentReceipts" :key="receipt.id" class="flex items-start justify-between gap-3 py-2">
            <div>
              <a :href="`${links.receiptsBase}${receipt.id}`" class="font-semibold text-primary hover:underline">{{ receipt.numero_visible }}</a>
              <p class="text-xs text-ink-muted">{{ fmtDate(receipt.fecha_emision) }} · acreditado {{ money(receipt.acreditado_ars) }}</p>
              <p v-if="Number(receipt.disponible_ars) > 0 && canImpute" class="text-xs text-info-strong">Anticipo disponible {{ money(receipt.disponible_ars) }} · <button type="button" class="font-semibold underline" @click="openImpute(receipt)">imputar</button></p>
            </div>
            <div class="text-right"><p class="font-semibold text-ink">{{ money(receipt.total_ars) }}</p><a v-if="canPrintReceipt" :href="`${links.receiptsBase}${receipt.id}/imprimir`" target="_blank" rel="noopener" class="text-xs font-semibold text-primary hover:underline">Imprimir</a><button v-if="canCancelReceipt && receipt.estado === 'CONFIRMADO'" type="button" class="ml-2 text-xs font-semibold text-danger-strong hover:underline" @click="openReceiptAnnul(receipt)">Anular</button></div>
          </li>
        </ul>
      </PanelCard>
    </div>

    <PanelCard v-if="pendingInstruments.length" title="Medios pendientes de acreditación" :count="pendingInstruments.length" class="mb-5">
      <ul class="divide-y divide-border-subtle text-sm" data-testid="pending-instruments">
        <li v-for="instrument in pendingInstruments" :key="instrument.id" class="flex items-start justify-between gap-3 py-2">
          <div><p class="font-semibold text-ink">{{ instrument.medio_label }} · {{ instrument.recibo_numero }}</p><p class="text-xs text-ink-muted">{{ instrument.banco ?? '' }} {{ instrument.referencia ?? '' }} · {{ fmtDate(instrument.fecha) }}</p></div>
          <div class="text-right"><p class="font-semibold text-warning-strong">{{ money(instrument.importe_ars) }}</p><a :href="`${links.receiptsBase}${instrument.recibo_id}`" class="text-xs font-semibold text-primary hover:underline">Ver recibo</a></div>
        </li>
      </ul>
      <p class="mt-2 text-xs text-ink-muted">Estos importes todavía no reducen el saldo: se acreditan cuando el cheque o la transferencia se hacen efectivos.</p>
    </PanelCard>

    <PanelCard title="Movimientos" :count="movements.length" flush>
      <p v-if="movements.length === 0" class="p-5 text-sm text-ink-muted" data-testid="statement-empty">Este cliente todavía no tiene movimientos.</p>
      <ul v-else class="divide-y divide-border-subtle sm:hidden" data-testid="statement-cards">
        <li v-for="row in movements" :key="row.id" class="space-y-1 px-4 py-3 text-sm" :class="row.cargo_anulado && row.tipo !== 'ANULACION' ? 'opacity-60' : ''">
          <div class="flex items-start justify-between gap-3">
            <div>
              <p class="font-semibold text-ink">{{ row.tipo_label }}<span v-if="row.cargo_anulado && row.tipo !== 'ANULACION'" class="ml-2 rounded-full bg-surface-muted px-2 py-0.5 text-xs font-semibold text-ink-muted">Anulado</span></p>
              <p class="text-xs text-ink-muted">{{ fmtDate(row.fecha) }} · {{ detail(row) }}</p>
              <p v-if="origin(row)" class="text-xs text-ink-muted">{{ origin(row) }}</p>
            </div>
            <div class="whitespace-nowrap text-right">
              <p class="font-semibold text-ink">{{ row.debe_ars ? money(row.debe_ars) : `−${money(row.haber_ars)}` }}</p>
              <p class="text-xs" :class="balanceClass(row.saldo_ars)">Saldo {{ money(row.saldo_ars) }}</p>
            </div>
          </div>
          <div class="flex gap-3">
            <a v-if="row.viaje_id" :href="`${links.tripsBase}/${row.viaje_id}`" class="text-xs font-semibold text-primary hover:underline">Ver viaje</a>
            <a v-if="row.recibo_id" :href="`${links.receiptsBase}${row.recibo_id}`" class="text-xs font-semibold text-primary hover:underline">Ver recibo {{ row.recibo_numero_visible }}</a>
            <button v-if="canFormalize && row.cargo_vigente" type="button" class="text-xs font-semibold text-danger-strong hover:underline" @click="openAnnul(row)">Anular</button>
          </div>
        </li>
      </ul>
      <div v-if="movements.length > 0" class="hidden overflow-x-auto sm:block">
        <table class="w-full min-w-[60rem] text-left text-sm" data-testid="statement-table">
          <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted">
            <tr><th class="px-3 py-2">Fecha</th><th class="px-3 py-2">Concepto</th><th class="px-3 py-2">Origen</th><th class="px-3 py-2 text-right">Debe</th><th class="px-3 py-2 text-right">Haber</th><th class="px-3 py-2 text-right">Saldo</th><th v-if="canFormalize" class="px-3 py-2"></th></tr>
          </thead>
          <tbody class="divide-y divide-border-subtle">
            <tr v-for="row in movements" :key="row.id" :class="row.cargo_anulado && row.tipo !== 'ANULACION' ? 'opacity-60' : ''">
              <td class="px-3 py-2 text-ink-muted">{{ fmtDate(row.fecha) }}</td>
              <td class="px-3 py-2">
                <span class="font-semibold text-ink">{{ row.tipo_label }}</span>
                <span v-if="row.cargo_anulado && row.tipo !== 'ANULACION'" class="ml-2 rounded-full bg-surface-muted px-2 py-0.5 text-xs font-semibold text-ink-muted">Anulado</span>
                <span class="block text-xs text-ink-muted">{{ detail(row) }}</span>
                <a v-if="row.viaje_id" :href="`${links.tripsBase}/${row.viaje_id}`" class="text-xs font-semibold text-primary hover:underline">Ver viaje</a>
                <a v-if="row.recibo_id" :href="`${links.receiptsBase}${row.recibo_id}`" class="text-xs font-semibold text-primary hover:underline">Ver recibo {{ row.recibo_numero_visible }}</a>
              </td>
              <td class="px-3 py-2 text-xs text-ink-muted">{{ origin(row) }}</td>
              <td class="whitespace-nowrap px-3 py-2 text-right text-ink">{{ row.debe_ars ? money(row.debe_ars) : '' }}</td>
              <td class="whitespace-nowrap px-3 py-2 text-right text-ink">{{ row.haber_ars ? money(row.haber_ars) : '' }}</td>
              <td class="whitespace-nowrap px-3 py-2 text-right font-semibold" :class="balanceClass(row.saldo_ars)">{{ money(row.saldo_ars) }}</td>
              <td v-if="canFormalize" class="whitespace-nowrap px-3 py-2"><button v-if="row.cargo_vigente" type="button" :class="secondaryButton" @click="openAnnul(row)">Anular</button></td>
            </tr>
          </tbody>
        </table>
      </div>
    </PanelCard>

    <dialog v-if="canFormalize" ref="openingDialog" aria-labelledby="opening-title" @click.self="openingDialog?.close()" class="m-auto w-[min(40rem,calc(100vw-2rem))] max-h-[calc(100vh-2rem)] overflow-y-auto rounded-xl border border-border bg-surface-raised p-0 text-ink shadow-xl backdrop:bg-black/60">
      <form method="post" :action="links.openingBalance" class="p-5 sm:p-6" data-testid="opening-form">
        <input type="hidden" :name="csrf.name" :value="csrf.hash" />
        <h2 id="opening-title" class="text-lg font-bold text-ink">Saldo inicial de {{ customer.nombre }}</h2>
        <p class="mt-1 text-sm text-ink-muted">El saldo con el que el cliente arranca en esta cuenta corriente, a una fecha y con su respaldo. Es uno solo: si te equivocás, anulalo y cargalo de nuevo.</p>
        <div class="mt-4 grid gap-3 sm:grid-cols-2">
          <label class="block sm:col-span-2"><span class="mb-1 block text-xs font-semibold text-ink-muted">El saldo es</span>
            <select v-model="opening.sentido" name="sentido" :class="fieldClass"><option value="DEBE">A cobrar al cliente</option><option value="HABER">A favor del cliente</option></select>
          </label>
          <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Fecha</span><input v-model="opening.fecha" type="date" name="fecha" :max="today()" required :class="fieldClass" /></label>
          <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Referencia de respaldo</span><input v-model="opening.referencia" type="text" name="referencia" maxlength="60" placeholder="Ej.: resumen del sistema anterior" :class="fieldClass" /></label>
          <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Importe</span><input v-model="opening.importe" type="number" name="importe" min="0.01" step="0.01" required :class="fieldClass" /></label>
          <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Moneda</span>
            <select v-model="opening.moneda" name="moneda" :class="fieldClass" @change="onOpeningCurrency"><option v-for="currency in currencies" :key="currency" :value="currency">{{ currency }}</option></select>
          </label>
          <label v-if="opening.moneda !== 'ARS'" class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Cotización a ARS</span><input v-model="opening.cotizacion" type="number" name="cotizacion" min="0.000001" step="0.000001" required :class="fieldClass" /></label>
          <label class="block sm:col-span-2"><span class="mb-1 block text-xs font-semibold text-ink-muted">Observaciones</span><input v-model="opening.observaciones" type="text" name="observaciones" maxlength="500" :class="fieldClass" /></label>
        </div>
        <p class="mt-4 rounded-lg border border-info/30 bg-info-subtle px-3 py-2 text-sm text-info-strong" data-testid="opening-preview">
          <template v-if="openingPreviewArs !== null">Se registra {{ opening.sentido === 'HABER' ? 'un saldo a favor' : 'un saldo a cobrar' }} de <strong>{{ money(openingPreviewArs) }}</strong>.</template>
          <template v-else>Completá el importe{{ opening.moneda === 'ARS' ? '' : ' y la cotización' }} para ver el saldo en ARS.</template>
        </p>
        <div class="mt-5 flex justify-end gap-2 border-t border-border-subtle pt-4">
          <button type="button" :class="secondaryButton" @click="openingDialog?.close()">Volver</button>
          <button type="submit" :class="primaryButton">Registrar saldo inicial</button>
        </div>
      </form>
    </dialog>

    <dialog v-if="canFormalize" ref="annulDialog" aria-labelledby="annul-title" @click.self="annulDialog?.close()" class="m-auto w-[min(36rem,calc(100vw-2rem))] rounded-xl border border-border bg-surface-raised p-0 text-ink shadow-xl backdrop:bg-black/60">
      <form v-if="annulling" method="post" :action="`${links.chargesBase}${annulling.cargo_id}/anular`" class="p-5 sm:p-6" data-testid="annul-form">
        <input type="hidden" :name="csrf.name" :value="csrf.hash" />
        <input type="hidden" name="volver" :value="data.returnTo" />
        <h2 id="annul-title" class="text-lg font-bold text-ink">Anular el cargo</h2>
        <p class="mt-1 text-sm text-ink-muted">{{ annulling.tipo_label }} · {{ annulling.descripcion }} · {{ money(annulling.debe_ars || annulling.haber_ars) }}</p>
        <p class="mt-3 text-sm text-ink-muted">Se agrega un contraasiento con la fecha de hoy. El cargo queda visible como anulado y, si era de un tramo, el tramo vuelve a pendientes del cierre comercial.</p>
        <label class="mt-3 block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Motivo</span><textarea name="motivo" rows="3" minlength="5" maxlength="500" required :class="fieldClass"></textarea></label>
        <div class="mt-5 flex justify-end gap-2 border-t border-border-subtle pt-4">
          <button type="button" :class="secondaryButton" @click="annulDialog?.close()">Volver</button>
          <button type="submit" :class="dangerButton">Anular cargo</button>
        </div>
      </form>
    </dialog>

    <dialog v-if="canImpute" ref="imputeDialog" aria-labelledby="impute-title" @click.self="imputeDialog?.close()" class="m-auto w-[min(38rem,calc(100vw-2rem))] rounded-xl border border-border bg-surface-raised p-0 text-ink shadow-xl backdrop:bg-black/60">
      <form method="post" :action="`${links.receiptsBase}${impute.recibo_id}/imputar`" class="p-5 sm:p-6" data-testid="advance-impute-form">
        <input type="hidden" :name="csrf.name" :value="csrf.hash" />
        <h2 id="impute-title" class="text-lg font-bold text-ink">Imputar anticipo a un documento</h2>
        <p class="mt-1 text-sm text-ink-muted">Se aplica crédito ya acreditado de un recibo confirmado. No genera un nuevo movimiento en la cuenta corriente.</p>
        <div class="mt-4 grid gap-3 sm:grid-cols-2">
          <label class="block sm:col-span-2"><span class="mb-1 block text-xs font-semibold text-ink-muted">Recibo con saldo disponible</span>
            <select v-model="impute.recibo_id" :class="fieldClass" required data-testid="advance-receipt">
              <option v-for="advance in advances" :key="advance.id" :value="advance.id">{{ advance.numero_visible }} · disponible {{ money(advance.disponible_ars) }}</option>
            </select>
          </label>
          <label class="block sm:col-span-2"><span class="mb-1 block text-xs font-semibold text-ink-muted">Documento pendiente</span>
            <select v-model="impute.cargo_id" :class="fieldClass" required data-testid="advance-document">
              <option value="" disabled>Elegí un documento</option>
              <option v-for="document in pendingDocuments.filter((doc) => Number(doc.pendiente_ars) > 0)" :key="document.id" :value="document.id">{{ document.documento }} · pendiente {{ money(document.pendiente_ars) }}</option>
            </select>
          </label>
          <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Importe</span>
            <input v-model="impute.importe" type="text" inputmode="decimal" name="imputaciones[0][importe]" required :class="fieldClass" />
            <input type="hidden" name="imputaciones[0][cargo_id]" :value="impute.cargo_id" />
          </label>
          <div class="flex items-end"><button type="button" :class="secondaryButton" @click="applyImputeAll">Aplicar lo máximo posible</button></div>
        </div>
        <div class="mt-5 flex justify-end gap-2 border-t border-border-subtle pt-4">
          <button type="button" :class="secondaryButton" @click="imputeDialog?.close()">Volver</button>
          <button type="submit" :class="primaryButton">Imputar el anticipo</button>
        </div>
      </form>
    </dialog>

    <dialog v-if="canCancelReceipt" ref="receiptAnnulDialog" aria-labelledby="receipt-annul-account-title" @click.self="receiptAnnulDialog?.close()" class="m-auto w-[min(36rem,calc(100vw-2rem))] rounded-xl border border-border bg-surface-raised p-0 text-ink shadow-xl backdrop:bg-black/60">
      <form v-if="annullingReceipt" method="post" :action="`${links.receiptsBase}${annullingReceipt.id}/anular`" class="p-5 sm:p-6" data-testid="account-receipt-annul-form">
        <input type="hidden" :name="csrf.name" :value="csrf.hash" />
        <h2 id="receipt-annul-account-title" class="text-lg font-bold text-ink">Anular el recibo {{ annullingReceipt.numero_visible }}</h2>
        <p class="mt-1 text-sm text-ink-muted">{{ money(annullingReceipt.total_ars) }} · Se agrega un contraasiento por lo acreditado y los documentos recuperan su saldo. El recibo queda conservado como anulado.</p>
        <label class="mt-3 block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Motivo</span><textarea name="motivo" rows="3" minlength="5" maxlength="500" required :class="fieldClass"></textarea></label>
        <div class="mt-5 flex justify-end gap-2 border-t border-border-subtle pt-4">
          <button type="button" :class="secondaryButton" @click="receiptAnnulDialog?.close()">Volver</button>
          <button type="submit" :class="dangerButton">Anular recibo</button>
        </div>
      </form>
    </dialog>
  </div>
</template>
