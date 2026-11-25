<script setup>
/**
 * Detalle de un recibo de cobranza (#536).
 *
 * Muestra los medios con su estado de acreditación, los documentos imputados,
 * los saldos antes/después y las acciones permitidas: confirmar, acreditar un
 * medio pendiente, imputar el crédito disponible, adjuntar comprobantes,
 * imprimir y anular con motivo.
 */
import { computed, nextTick, ref } from 'vue'
import PageHeading from './components/PageHeading.vue'
import PanelCard from './components/PanelCard.vue'
import { fieldClass, primaryButton, secondaryButton, dangerButton } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })

const csrf = computed(() => props.data.csrf ?? {})
const links = computed(() => props.data.links ?? {})
const receipt = computed(() => props.data.receipt ?? {})
const customer = computed(() => props.data.customer ?? {})
const company = computed(() => props.data.company ?? {})
const branch = computed(() => props.data.branch ?? {})
const instruments = computed(() => props.data.instruments ?? [])
const imputations = computed(() => props.data.imputations ?? [])
const attachments = computed(() => props.data.attachments ?? [])
const history = computed(() => props.data.history ?? [])
const pendingDocuments = computed(() => (props.data.pendingDocuments ?? []).filter((document) => Number(document.pendiente_ars) > 0))
const canConfirm = computed(() => Boolean(props.data.canConfirm))
const canCancel = computed(() => Boolean(props.data.canCancel))
const canImpute = computed(() => Boolean(props.data.canImpute))
const canPrint = computed(() => Boolean(props.data.canPrint))
const canRegister = computed(() => Boolean(props.data.canRegister))
const isDraft = computed(() => receipt.value.estado === 'BORRADOR')
const isConfirmed = computed(() => receipt.value.estado === 'CONFIRMADO')
const isCancelled = computed(() => receipt.value.estado === 'ANULADO')

const money = (value) => `ARS ${Number(value ?? 0).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
const fmtDate = (value) => {
  const raw = String(value ?? '').slice(0, 10)
  if (!raw) return '—'
  const [year, month, day] = raw.split('-')

  return `${day}/${month}/${year}`
}
const rateText = (value) => Number(value ?? 0).toLocaleString('es-AR', { maximumFractionDigits: 6 })
const origin = (instrument) => instrument.moneda === 'ARS'
  ? money(instrument.importe)
  : `${instrument.moneda} ${Number(instrument.importe).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} × ${rateText(instrument.cotizacion)}`
const stateClass = (state) => ({
  BORRADOR: 'bg-surface-muted text-ink-muted',
  CONFIRMADO: 'bg-success-subtle text-success-strong',
  ANULADO: 'bg-danger-subtle text-danger-strong',
}[state] ?? 'bg-surface-muted text-ink-muted')
const accreditationClass = (state) => ({
  ACREDITADO: 'bg-success-subtle text-success-strong',
  PENDIENTE: 'bg-warning-subtle text-warning-strong',
  ANULADO: 'bg-danger-subtle text-danger-strong',
}[state] ?? 'bg-surface-muted text-ink-muted')
const balanceClass = (value) => (value > 0 ? 'text-danger-strong' : (value < 0 ? 'text-success-strong' : 'text-ink-muted'))

const annulDialog = ref(null)
const openAnnul = () => nextTick(() => annulDialog.value?.showModal())

const impute = ref({ cargo_id: '', importe: '' })
const imputeDocument = computed(() => pendingDocuments.value.find((document) => String(document.id) === String(impute.value.cargo_id)) ?? null)
const applyAll = () => {
  if (imputeDocument.value) impute.value.importe = Number(imputeDocument.value.pendiente_ars).toFixed(2)
}
</script>

<template>
  <div>
    <PageHeading eyebrow="Clientes · Cobranzas" :title="`Recibo ${receipt.numero_visible}`" :description="`${customer.nombre ?? ''} · ${branch.nombre ?? ''} · Emitido ${fmtDate(receipt.fecha_emision)}`" :back="{ href: links.receipts ?? '/clientes/recibos', label: 'Volver a recibos' }">
      <template #actions>
        <a v-if="canPrint" :href="`${links.receiptsBase}${receipt.id}/imprimir`" target="_blank" rel="noopener" :class="secondaryButton">Imprimir PDF</a>
        <form v-if="isDraft && canConfirm" method="post" :action="`${links.receiptsBase}${receipt.id}/confirmar`">
          <input type="hidden" :name="csrf.name" :value="csrf.hash" />
          <button type="submit" :class="primaryButton" data-testid="receipt-confirm">Confirmar y numerar</button>
        </form>
        <form v-if="isDraft && canRegister" method="post" :action="`${links.receiptsBase}${receipt.id}/descartar`" data-confirm data-confirm-title="Descartar el borrador" data-confirm-text="Se elimina este borrador. No hay asientos contabilizados." data-confirm-button="Descartar" data-confirm-danger="true">
          <input type="hidden" :name="csrf.name" :value="csrf.hash" />
          <button type="submit" :class="dangerButton">Descartar borrador</button>
        </form>
        <button v-if="isConfirmed && canCancel" type="button" :class="dangerButton" data-testid="receipt-annul-open" @click="openAnnul">Anular recibo</button>
      </template>
    </PageHeading>

    <p v-if="isCancelled" class="mb-4 rounded-xl border border-danger/30 bg-danger-subtle px-4 py-3 text-sm font-semibold text-danger-strong" data-testid="receipt-cancelled">
      Recibo anulado el {{ fmtDate(receipt.anulado_at) }} por {{ receipt.anulado_por ?? 'usuario' }}. Motivo: {{ receipt.motivo_anulacion }}
    </p>

    <section aria-label="Resumen" class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card"><p class="text-xs font-semibold text-ink-muted">Total del recibo</p><p class="mt-2 text-xl font-bold text-ink" data-testid="receipt-detail-total">{{ money(receipt.total_ars) }}</p><span class="mt-1 inline-flex rounded-full px-2 py-0.5 text-xs font-semibold" :class="stateClass(receipt.estado)">{{ receipt.estado_label }}</span></article>
      <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card"><p class="text-xs font-semibold text-ink-muted">Acreditado</p><p class="mt-2 text-xl font-bold text-success-strong">{{ money(receipt.acreditado_ars) }}</p><p v-if="Number(receipt.pendiente_ars) > 0" class="text-xs text-warning-strong">{{ money(receipt.pendiente_ars) }} pendiente</p></article>
      <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card"><p class="text-xs font-semibold text-ink-muted">Imputado a documentos</p><p class="mt-2 text-xl font-bold text-ink">{{ money(receipt.imputado_ars) }}</p><p class="text-xs text-ink-muted">Disponible {{ money(receipt.disponible_ars) }}</p></article>
      <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card"><p class="text-xs font-semibold text-ink-muted">Saldo del cliente</p><p class="mt-2 text-base font-semibold text-ink-muted">Antes {{ money(receipt.saldo_anterior_ars) }}</p><p class="text-xl font-bold" :class="balanceClass(receipt.saldo_posterior_ars)" data-testid="receipt-detail-after">{{ money(receipt.saldo_posterior_ars) }}</p></article>
    </section>

    <PanelCard title="Medios de pago" :count="instruments.length">
      <div class="space-y-3">
        <article v-for="instrument in instruments" :key="instrument.id" class="rounded-lg border border-border-subtle p-3" :data-testid="`receipt-instrument-${instrument.id}`">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <p class="font-semibold text-ink">{{ instrument.medio_label }} · {{ origin(instrument) }} = {{ money(instrument.importe_ars) }}</p>
              <p class="text-xs text-ink-muted">{{ instrument.cotizacion_fecha }} · fuente {{ instrument.cotizacion_fuente }}</p>
              <p v-if="instrument.banco" class="text-xs text-ink-muted">Banco {{ instrument.banco }}<span v-if="instrument.referencia"> · Ref. {{ instrument.referencia }}</span><span v-if="instrument.fecha_transferencia"> · {{ fmtDate(instrument.fecha_transferencia) }}</span></p>
              <p v-if="instrument.cheque" class="text-xs text-ink-muted">Cheque {{ instrument.cheque.numero }} · {{ instrument.cheque.banco }} · {{ instrument.cheque.librador }} · vence {{ fmtDate(instrument.cheque.fecha_pago) }} · {{ instrument.cheque.estado_label }}</p>
              <p v-if="instrument.observaciones" class="text-xs text-ink-muted">{{ instrument.observaciones }}</p>
            </div>
            <div class="flex flex-col items-end gap-2">
              <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold" :class="accreditationClass(instrument.estado_acreditacion)">{{ instrument.estado_acreditacion_label }}</span>
              <form v-if="isConfirmed && canConfirm && instrument.estado_acreditacion === 'PENDIENTE'" method="post" :action="`${links.receiptsBase}${receipt.id}/medios/${instrument.id}/acreditar`" data-confirm data-confirm-title="Acreditar el medio" data-confirm-text="Se registra el Haber en la cuenta corriente por este medio. La acción no se puede repetir." data-confirm-button="Acreditar">
                <input type="hidden" :name="csrf.name" :value="csrf.hash" />
                <button type="submit" :class="secondaryButton" :data-testid="`accredit-${instrument.id}`">Acreditar {{ money(instrument.importe_ars) }}</button>
              </form>
            </div>
          </div>
        </article>
      </div>
    </PanelCard>

    <div class="mt-4">
      <PanelCard title="Documentos imputados" :count="imputations.length">
        <p v-if="imputations.length === 0" class="text-sm text-ink-muted" data-testid="receipt-imputations-empty">Sin imputaciones: el crédito acreditado queda como anticipo a favor del cliente.</p>
        <div v-else class="overflow-x-auto">
          <table class="w-full min-w-[44rem] text-left text-sm" data-testid="receipt-imputations-table">
            <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted"><tr><th class="px-3 py-2">Documento</th><th class="px-3 py-2">Fecha</th><th class="px-3 py-2">Estado</th><th class="px-3 py-2 text-right">Importe imputado</th></tr></thead>
            <tbody class="divide-y divide-border-subtle">
              <tr v-for="imputation in imputations" :key="imputation.id" :class="imputation.estado !== 'VIGENTE' ? 'opacity-60' : ''">
                <td class="px-3 py-2 font-semibold text-ink">{{ imputation.documento }}</td>
                <td class="px-3 py-2 text-ink-muted">{{ fmtDate(imputation.documento_fecha) }}</td>
                <td class="px-3 py-2"><span class="rounded-full bg-surface-muted px-2 py-0.5 text-xs font-semibold text-ink-muted">{{ imputation.estado_label }}</span></td>
                <td class="px-3 py-2 text-right text-ink">{{ money(imputation.importe_ars) }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <form v-if="isConfirmed && canImpute && Number(receipt.disponible_ars) > 0 && pendingDocuments.length" method="post" :action="`${links.receiptsBase}${receipt.id}/imputar`" class="mt-4 grid gap-2 rounded-lg border border-info/30 bg-info-subtle p-3 sm:grid-cols-[1fr_10rem_auto]" data-testid="receipt-impute-form">
          <input type="hidden" :name="csrf.name" :value="csrf.hash" />
          <input type="hidden" name="imputaciones[0][cargo_id]" :value="impute.cargo_id" />
          <label class="block"><span class="mb-1 block text-xs font-semibold text-info-strong">Aplicar el anticipo disponible ({{ money(receipt.disponible_ars) }}) a</span>
            <select v-model="impute.cargo_id" :class="fieldClass" required>
              <option value="" disabled>Elegí un documento pendiente</option>
              <option v-for="document in pendingDocuments" :key="document.id" :value="document.id">{{ document.documento }} · {{ fmtDate(document.documento_fecha) }} · pendiente {{ money(document.pendiente_ars) }}</option>
            </select>
          </label>
          <label class="block"><span class="mb-1 block text-xs font-semibold text-info-strong">Importe</span><input v-model="impute.importe" type="text" inputmode="decimal" name="imputaciones[0][importe]" required :class="fieldClass" /></label>
          <div class="flex items-end gap-2"><button type="button" :class="secondaryButton" @click="applyAll">Todo</button><button type="submit" :class="primaryButton">Imputar</button></div>
        </form>
      </PanelCard>
    </div>

    <div class="mt-4 grid gap-4 lg:grid-cols-2">
      <PanelCard title="Comprobantes adjuntos" :count="attachments.length">
        <p v-if="attachments.length === 0" class="text-sm text-ink-muted">Sin comprobantes adjuntos.</p>
        <ul v-else class="space-y-2 text-sm">
          <li v-for="attachment in attachments" :key="attachment.id" class="flex items-center justify-between gap-3">
            <div><p class="font-semibold text-ink">{{ attachment.nombre }}</p><p class="text-xs text-ink-muted">{{ attachment.descripcion ?? 'Sin descripción' }} · {{ Math.max(1, Math.round(attachment.tamanio / 1024)) }} KB</p></div>
            <a :href="`${links.receiptsBase}${receipt.id}/adjuntos/${attachment.id}`" class="text-xs font-semibold text-primary hover:underline">Descargar</a>
          </li>
        </ul>
        <form v-if="canRegister && !isCancelled" method="post" :action="`${links.receiptsBase}${receipt.id}/adjuntos`" enctype="multipart/form-data" class="mt-3 grid gap-2 border-t border-border-subtle pt-3" data-testid="receipt-attachment-form">
          <input type="hidden" :name="csrf.name" :value="csrf.hash" />
          <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Archivo (PDF, JPG, PNG o WEBP, hasta 10 MB)</span><input type="file" name="archivo" accept=".pdf,.jpg,.jpeg,.png,.webp" required :class="fieldClass" /></label>
          <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Descripción</span><input type="text" name="descripcion" maxlength="255" :class="fieldClass" /></label>
          <label v-if="instruments.length > 1" class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Asociar a un medio (opcional)</span>
            <select name="recibo_medio_id" :class="fieldClass"><option value="">Todo el recibo</option><option v-for="instrument in instruments" :key="instrument.id" :value="instrument.id">{{ instrument.medio_label }} · {{ money(instrument.importe_ars) }}</option></select>
          </label>
          <button type="submit" :class="secondaryButton">Adjuntar comprobante</button>
        </form>
      </PanelCard>

      <PanelCard title="Datos del recibo">
        <dl class="grid gap-2 text-sm sm:grid-cols-2">
          <div><dt class="text-xs font-semibold text-ink-muted">Empresa</dt><dd class="text-ink">{{ company.nombre_fantasia || company.razon_social || '—' }}</dd></div>
          <div><dt class="text-xs font-semibold text-ink-muted">CUIT</dt><dd class="text-ink">{{ company.cuit || '—' }}</dd></div>
          <div><dt class="text-xs font-semibold text-ink-muted">Cliente</dt><dd class="text-ink">{{ customer.nombre }}</dd></div>
          <div><dt class="text-xs font-semibold text-ink-muted">CUIT / ID</dt><dd class="text-ink">{{ customer.identificacion_fiscal || '—' }}</dd></div>
          <div><dt class="text-xs font-semibold text-ink-muted">Recepción efectiva</dt><dd class="text-ink">{{ fmtDate(receipt.fecha_recepcion) }}</dd></div>
          <div><dt class="text-xs font-semibold text-ink-muted">Responsable</dt><dd class="text-ink">{{ receipt.usuario || '—' }}</dd></div>
          <div><dt class="text-xs font-semibold text-ink-muted">Confirmado</dt><dd class="text-ink">{{ receipt.confirmado_por ? `${receipt.confirmado_por} · ${fmtDate(receipt.confirmado_at)}` : 'Todavía no' }}</dd></div>
          <div><dt class="text-xs font-semibold text-ink-muted">Saldo posterior previsto</dt><dd class="text-ink">{{ money(receipt.saldo_posterior_previsto_ars) }}</dd></div>
        </dl>
        <p v-if="receipt.observaciones" class="mt-3 rounded-lg bg-surface-subtle px-3 py-2 text-sm text-ink-muted">{{ receipt.observaciones }}</p>
        <p class="mt-3 text-xs text-ink-muted">Documento interno de cobranza. No es un comprobante fiscal autorizado por ARCA.</p>
      </PanelCard>
    </div>

    <div class="mt-4">
      <PanelCard title="Historial de operaciones" :count="history.length">
        <p v-if="history.length === 0" class="text-sm text-ink-muted">La auditoría registrará cada operación del recibo.</p>
        <ol v-else class="space-y-2 text-sm" data-testid="receipt-history">
          <li v-for="(event, index) in history" :key="index" class="flex items-start justify-between gap-3 border-b border-border-subtle pb-2 last:border-0">
            <div><p class="font-semibold text-ink">{{ event.accion_label }}</p><p v-if="event.motivo" class="text-xs text-ink-muted">Motivo: {{ event.motivo }}</p></div>
            <p class="whitespace-nowrap text-xs text-ink-muted">{{ fmtDate(event.fecha) }}<span v-if="event.usuario" class="block">{{ event.usuario }}</span></p>
          </li>
        </ol>
      </PanelCard>
    </div>

    <dialog ref="annulDialog" aria-labelledby="receipt-annul-detail-title" @click.self="annulDialog?.close()" class="m-auto w-[min(36rem,calc(100vw-2rem))] rounded-xl border border-border bg-surface-raised p-0 text-ink shadow-xl backdrop:bg-black/60">
      <form method="post" :action="`${links.receiptsBase}${receipt.id}/anular`" class="p-5 sm:p-6" data-testid="receipt-annul-detail-form">
        <input type="hidden" :name="csrf.name" :value="csrf.hash" />
        <h2 id="receipt-annul-detail-title" class="text-lg font-bold text-ink">Anular el recibo {{ receipt.numero_visible }}</h2>
        <p class="mt-1 text-sm text-ink-muted">Se agrega un contraasiento por {{ money(receipt.acreditado_ars) }} (lo efectivamente acreditado), se revierten las imputaciones y los documentos recuperan su saldo. El recibo original se conserva.</p>
        <p v-if="Number(receipt.pendiente_ars) > 0" class="mt-2 rounded-lg border border-warning/30 bg-warning-subtle px-3 py-2 text-xs text-warning-strong">Los medios pendientes ({{ money(receipt.pendiente_ars) }}) se anulan sin generar asiento porque nunca impactaron el saldo.</p>
        <label class="mt-3 block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Motivo</span><textarea name="motivo" rows="3" minlength="5" maxlength="500" required :class="fieldClass"></textarea></label>
        <div class="mt-5 flex justify-end gap-2 border-t border-border-subtle pt-4">
          <button type="button" :class="secondaryButton" @click="annulDialog?.close()">Volver</button>
          <button type="submit" :class="dangerButton">Anular recibo</button>
        </div>
      </form>
    </dialog>
  </div>
</template>
