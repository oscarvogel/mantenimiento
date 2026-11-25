<script setup>
/**
 * Formulario de recibo de cobranza (#536).
 *
 * Permite combinar efectivo, transferencias, cheques físicos y eCheq en ARS,
 * USD o BRL. Cada línea conserva su cotización; la vista previa muestra el
 * total en ARS, lo que se acredita al confirmar y cómo queda el saldo.
 */
import { computed, reactive, ref } from 'vue'
import PageHeading from './components/PageHeading.vue'
import PanelCard from './components/PanelCard.vue'
import { fieldClass, primaryButton, secondaryButton } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })

const csrf = computed(() => props.data.csrf ?? {})
const links = computed(() => props.data.links ?? {})
const customer = computed(() => props.data.customer ?? {})
const branches = computed(() => props.data.branches ?? [])
const documents = computed(() => props.data.pendingDocuments ?? [])
const summary = computed(() => props.data.summary ?? {})
const currencies = computed(() => props.data.currencies ?? ['ARS'])
const currentRates = computed(() => props.data.currentRates ?? {})
const canConfirm = computed(() => Boolean(props.data.canConfirm))
const canImpute = computed(() => Boolean(props.data.canImpute))

const today = () => String(props.data.today ?? new Date().toISOString().slice(0, 10))
const emptyLine = () => ({
  medio: 'EFECTIVO',
  moneda: 'ARS',
  importe: '',
  cotizacion: '',
  banco: '',
  cuenta_destino: '',
  titular: '',
  fecha_transferencia: today(),
  referencia: '',
  estado_verificacion: 'PENDIENTE',
  cheque_numero: '',
  librador: '',
  cuit_librador: '',
  cheque_fecha_emision: today(),
  cheque_fecha_pago: today(),
  cheque_fecha_recepcion: today(),
  observaciones: '',
})
const form = reactive({
  sucursal_id: String(props.data.defaultBranchId ?? ''),
  serie: props.data.defaultSeries ?? 'R',
  fecha_emision: today(),
  fecha_recepcion: today(),
  observaciones: '',
})
const lines = ref([emptyLine()])
const allocation = reactive({})

const money = (value) => `ARS ${Number(value ?? 0).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
const fmtDate = (value) => {
  const raw = String(value ?? '').slice(0, 10)
  if (!raw) return '—'
  const [year, month, day] = raw.split('-')

  return `${day}/${month}/${year}`
}
const parseCents = (value) => {
  let raw = String(value ?? '').trim().replace(/\s/g, '')
  if (!raw) return 0
  if (raw.includes(',') && raw.includes('.')) {
    raw = raw.lastIndexOf(',') > raw.lastIndexOf('.') ? raw.replace(/\./g, '').replace(',', '.') : raw.replace(/,/g, '')
  } else {
    raw = raw.replace(',', '.')
  }
  const number = Number(raw)
  if (!Number.isFinite(number) || number <= 0) return 0

  return Math.round(number * 100)
}
const rateOf = (line) => (line.moneda === 'ARS' ? 1 : Number(line.cotizacion))
const arsOf = (line) => {
  const cents = parseCents(line.importe)
  const rate = rateOf(line)
  if (!(cents > 0) || !(rate > 0)) return 0

  return Math.round((cents * Math.round(rate * 1e6)) / 1e6)
}
const creditsOnConfirm = (line) => line.medio === 'EFECTIVO' || (line.medio === 'TRANSFERENCIA' && line.estado_verificacion !== 'PENDIENTE')
const isCheque = (line) => line.medio === 'CHEQUE' || line.medio === 'E_CHEQ'
const isTransfer = (line) => line.medio === 'TRANSFERENCIA'

const totalCents = computed(() => lines.value.reduce((sum, line) => sum + arsOf(line), 0))
const creditedCents = computed(() => lines.value.reduce((sum, line) => sum + (creditsOnConfirm(line) ? arsOf(line) : 0), 0))
const imputedCents = computed(() => documents.value.reduce((sum, document) => sum + parseCents(allocation[document.id]), 0))
const availableCents = computed(() => totalCents.value - imputedCents.value)
const forecastCents = computed(() => Math.round(Number(summary.value.saldo_ars ?? 0) * 100) - creditedCents.value)
const overImputed = computed(() => imputedCents.value > totalCents.value)
const overPending = computed(() => documents.value.some((document) => parseCents(allocation[document.id]) > Math.round(Number(document.pendiente_ars ?? 0) * 100)))

const addLine = () => lines.value.push(emptyLine())
const removeLine = (index) => {
  if (lines.value.length === 1) return
  lines.value.splice(index, 1)
}
const onCurrency = (line) => {
  line.cotizacion = line.moneda === 'ARS' ? '' : String(currentRates.value[line.moneda] ?? '')
}
const quickApply = () => {
  let remaining = totalCents.value
  documents.value.forEach((document) => {
    const pending = Math.round(Number(document.pendiente_ars ?? 0) * 100)
    const apply = Math.max(0, Math.min(remaining, pending))
    allocation[document.id] = apply > 0 ? (apply / 100).toFixed(2) : ''
    remaining -= apply
  })
}
</script>

<template>
  <div>
    <PageHeading eyebrow="Clientes · Cobranzas" :title="`Nuevo recibo de ${customer.nombre ?? 'cliente'}`" :description="customer.identificacion_fiscal ? `CUIT/ID ${customer.identificacion_fiscal} · El total del recibo se expresa en ARS y cada medio conserva su moneda.` : 'El total del recibo se expresa en ARS y cada medio conserva su moneda.'" :back="{ href: data.returnTo ?? '/clientes/cuenta-corriente', label: 'Volver a la cuenta corriente' }" />

    <form method="post" :action="links.receipts" data-testid="receipt-form">
      <input type="hidden" :name="csrf.name" :value="csrf.hash" />
      <input type="hidden" name="cliente_id" :value="customer.id" />

      <PanelCard title="Datos del recibo">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Sucursal</span>
            <select v-model="form.sucursal_id" name="sucursal_id" :class="fieldClass" required data-testid="receipt-branch">
              <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.nombre }}</option>
            </select>
          </label>
          <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Serie</span><input v-model="form.serie" name="serie" maxlength="10" required :class="fieldClass" /></label>
          <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Fecha de emisión</span><input v-model="form.fecha_emision" type="date" name="fecha_emision" :max="today()" required :class="fieldClass" /></label>
          <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Recepción efectiva del pago</span><input v-model="form.fecha_recepcion" type="date" name="fecha_recepcion" :max="today()" required :class="fieldClass" /></label>
          <label class="block sm:col-span-2 lg:col-span-4"><span class="mb-1 block text-xs font-semibold text-ink-muted">Observaciones</span><input v-model="form.observaciones" name="observaciones" maxlength="1000" :class="fieldClass" /></label>
        </div>
      </PanelCard>

      <div class="mt-4">
        <PanelCard title="Medios de pago" :count="lines.length">
          <template #header-actions><button type="button" :class="secondaryButton" data-testid="add-receipt-line" @click="addLine">Agregar medio</button></template>
          <div class="space-y-4">
            <article v-for="(line, index) in lines" :key="index" class="rounded-xl border border-border-subtle p-3" :data-testid="`receipt-line-${index}`">
              <div class="flex items-start justify-between gap-3">
                <p class="text-sm font-semibold text-ink">Medio {{ index + 1 }}</p>
                <button v-if="lines.length > 1" type="button" class="text-xs font-semibold text-danger-strong hover:underline" @click="removeLine(index)">Quitar</button>
              </div>
              <div class="mt-2 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Tipo</span>
                  <select v-model="line.medio" :name="`medios[${index}][medio]`" :class="fieldClass" data-testid="receipt-line-kind">
                    <option value="EFECTIVO">Efectivo</option><option value="TRANSFERENCIA">Transferencia bancaria</option><option value="CHEQUE">Cheque físico</option><option value="E_CHEQ">eCheq</option>
                  </select>
                </label>
                <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Moneda</span>
                  <select v-model="line.moneda" :name="`medios[${index}][moneda]`" :class="fieldClass" @change="onCurrency(line)">
                    <option v-for="currency in currencies" :key="currency" :value="currency">{{ currency }}</option>
                  </select>
                </label>
                <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Importe</span><input v-model="line.importe" type="text" inputmode="decimal" :name="`medios[${index}][importe]`" required placeholder="0,00" :class="fieldClass" /></label>
                <label v-if="line.moneda !== 'ARS'" class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Cotización a ARS</span><input v-model="line.cotizacion" type="number" min="0.000001" step="0.000001" :name="`medios[${index}][cotizacion]`" required :class="fieldClass" /></label>
                <div v-else class="flex items-end"><p class="text-sm text-ink-muted">ARS no se cotiza.</p></div>

                <template v-if="isTransfer(line)">
                  <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Banco</span><input v-model="line.banco" :name="`medios[${index}][banco]`" maxlength="100" required :class="fieldClass" /></label>
                  <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Cuenta de destino</span><input v-model="line.cuenta_destino" :name="`medios[${index}][cuenta_destino]`" maxlength="100" :class="fieldClass" /></label>
                  <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Titular</span><input v-model="line.titular" :name="`medios[${index}][titular]`" maxlength="150" :class="fieldClass" /></label>
                  <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Fecha de transferencia</span><input v-model="line.fecha_transferencia" type="date" :max="today()" :name="`medios[${index}][fecha_transferencia]`" required :class="fieldClass" /></label>
                  <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Referencia / operación</span><input v-model="line.referencia" :name="`medios[${index}][referencia]`" maxlength="100" required :class="fieldClass" /></label>
                  <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Verificación</span>
                    <select v-model="line.estado_verificacion" :name="`medios[${index}][estado_verificacion]`" :class="fieldClass">
                      <option value="PENDIENTE">Pendiente (no acredita al confirmar)</option>
                      <option value="VERIFICADA">Verificada (acredita al confirmar)</option>
                    </select>
                  </label>
                </template>

                <template v-if="isCheque(line)">
                  <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Banco emisor</span><input v-model="line.banco" :name="`medios[${index}][banco]`" maxlength="100" required :class="fieldClass" /></label>
                  <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Número / identificador</span><input v-model="line.cheque_numero" :name="`medios[${index}][cheque_numero]`" maxlength="60" required :class="fieldClass" /></label>
                  <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Librador</span><input v-model="line.librador" :name="`medios[${index}][librador]`" maxlength="150" required :class="fieldClass" /></label>
                  <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">CUIT del librador</span><input v-model="line.cuit_librador" :name="`medios[${index}][cuit_librador]`" maxlength="20" :class="fieldClass" /></label>
                  <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Fecha de emisión</span><input v-model="line.cheque_fecha_emision" type="date" :max="today()" :name="`medios[${index}][cheque_fecha_emision]`" required :class="fieldClass" /></label>
                  <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Fecha de pago / vencimiento</span><input v-model="line.cheque_fecha_pago" type="date" :name="`medios[${index}][cheque_fecha_pago]`" required :class="fieldClass" /></label>
                  <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Fecha de recepción</span><input v-model="line.cheque_fecha_recepcion" type="date" :max="today()" :name="`medios[${index}][cheque_fecha_recepcion]`" required :class="fieldClass" /></label>
                </template>

                <label class="block sm:col-span-2 lg:col-span-4"><span class="mb-1 block text-xs font-semibold text-ink-muted">Observaciones del medio</span><input v-model="line.observaciones" :name="`medios[${index}][observaciones]`" maxlength="500" :class="fieldClass" /></label>
              </div>
              <p class="mt-2 rounded-lg border border-info/30 bg-info-subtle px-3 py-2 text-xs text-info-strong" :data-testid="`receipt-line-preview-${index}`">
                Equivalente <strong>{{ money(arsOf(line) / 100) }}</strong>
                <template v-if="creditsOnConfirm(line)"> · se acredita al confirmar</template>
                <template v-else> · queda pendiente de acreditación</template>
              </p>
            </article>
          </div>
        </PanelCard>
      </div>

      <div v-if="canImpute && documents.length" class="mt-4">
        <PanelCard title="Imputar a documentos pendientes" :count="documents.length">
          <template #header-actions><button type="button" :class="secondaryButton" @click="quickApply">Aplicar por antigüedad</button></template>
          <p class="mb-3 text-sm text-ink-muted">Podés dejar parte sin imputar: queda como anticipo a favor del cliente. Lo imputado no puede superar lo que se acredita al confirmar.</p>
          <div class="space-y-2">
            <div v-for="(document, index) in documents" :key="document.id" class="grid items-center gap-2 rounded-lg border border-border-subtle p-3 text-sm sm:grid-cols-[1fr_8rem_8rem]">
              <div>
                <p class="font-semibold text-ink">{{ document.documento }}</p>
                <p class="text-xs text-ink-muted">{{ fmtDate(document.documento_fecha) }} · {{ document.estado_cobranza_label }} · pendiente {{ money(document.pendiente_ars) }}</p>
              </div>
              <input type="hidden" :name="`imputaciones[${index}][cargo_id]`" :value="document.id" />
              <input v-model="allocation[document.id]" type="text" inputmode="decimal" :name="`imputaciones[${index}][importe]`" placeholder="0,00" :class="fieldClass" :aria-label="`Importe a imputar a ${document.documento}`" />
              <button type="button" class="text-left text-xs font-semibold text-primary hover:underline" @click="allocation[document.id] = Number(document.pendiente_ars).toFixed(2)">Imputar todo ({{ money(document.pendiente_ars) }})</button>
            </div>
          </div>
          <p class="mt-3 text-sm" :class="overImputed || overPending ? 'font-semibold text-danger-strong' : 'text-ink-muted'" data-testid="receipt-imputation-warning">
            <template v-if="overImputed">La suma imputada supera el total del recibo.</template>
            <template v-else-if="overPending">Hay un documento con más importe que su saldo pendiente.</template>
            <template v-else>Total imputado: {{ money(imputedCents / 100) }} · sin imputar: {{ money(availableCents / 100) }}</template>
          </p>
        </PanelCard>
      </div>

      <section aria-label="Resumen del recibo" class="mt-4 rounded-xl border border-border bg-surface-raised p-4 shadow-card" data-testid="receipt-summary">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <div><p class="text-xs font-semibold text-ink-muted">Total del recibo</p><p class="mt-1 text-xl font-bold text-ink" data-testid="receipt-total">{{ money(totalCents / 100) }}</p></div>
          <div><p class="text-xs font-semibold text-ink-muted">Se acredita al confirmar</p><p class="mt-1 text-xl font-bold text-success-strong" data-testid="receipt-credited">{{ money(creditedCents / 100) }}</p></div>
          <div><p class="text-xs font-semibold text-ink-muted">Queda pendiente de acreditación</p><p class="mt-1 text-xl font-bold text-warning-strong">{{ money((totalCents - creditedCents) / 100) }}</p></div>
          <div><p class="text-xs font-semibold text-ink-muted">Saldo anterior del cliente</p><p class="mt-1 text-xl font-bold text-ink">{{ money(summary.saldo_ars ?? 0) }}</p></div>
        </div>
        <p class="mt-3 text-sm text-ink-muted" data-testid="receipt-forecast">
          Si confirmás ahora, el saldo del cliente queda en <strong class="text-ink">{{ money(forecastCents / 100) }}</strong>.
          <span v-if="totalCents !== creditedCents"> Los medios pendientes no reducen la deuda hasta acreditarse.</span>
        </p>
      </section>

      <div class="mt-5 flex flex-wrap justify-end gap-2">
        <a :href="data.returnTo ?? '/clientes/cuenta-corriente'" :class="secondaryButton">Cancelar</a>
        <button type="submit" name="accion" value="borrador" :class="secondaryButton" :disabled="overImputed || overPending" data-testid="receipt-save-draft">Guardar borrador</button>
        <button v-if="canConfirm" type="submit" name="accion" value="confirmar" :class="primaryButton" :disabled="overImputed || overPending" data-testid="receipt-save-confirm">Confirmar recibo</button>
      </div>
    </form>
  </div>
</template>
