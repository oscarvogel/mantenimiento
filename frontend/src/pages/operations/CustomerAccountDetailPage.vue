<script setup>
/**
 * Estado de cuenta de un cliente multimoneda (#588): tarjetas por moneda, vista
 * consolidada en pesos, documentos con su estado de cobranza y cobros con sus
 * diferencias de cambio.
 */
import { computed, nextTick, ref } from 'vue'
import PageHeading from './components/PageHeading.vue'
import PanelCard from './components/PanelCard.vue'
import { fieldClass, formatMoney, formatRate, primaryButton, secondaryButton, dangerButton, rateSourceLabel } from './helpers.js'

const money = (value, currency = 'ARS') => formatMoney(value, currency)

const props = defineProps({ data: { type: Object, required: true } })

const csrf = computed(() => props.data.csrf ?? {})
const links = computed(() => props.data.links ?? {})
const customer = computed(() => props.data.customer ?? {})
const movements = computed(() => props.data.movements ?? [])
const documents = computed(() => props.data.documents ?? [])
const payments = computed(() => props.data.payments ?? [])
const balances = computed(() => props.data.balances ?? {})
const totals = computed(() => props.data.totals ?? {})
const valuation = computed(() => props.data.valuation ?? {})
const canFormalize = computed(() => Boolean(props.data.canFormalize))
const canCollect = computed(() => Boolean(props.data.canCollect))
const currencies = computed(() => props.data.currencies ?? ['ARS'])
const currentRates = computed(() => props.data.currentRates ?? {})

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
const balanceLabel = (value) => (value > 0 ? 'Saldo a cobrar' : (value < 0 ? 'Saldo a favor del cliente' : 'Cuenta saldada'))
const currencyBalance = (code) => balances.value[code] ?? { saldo_original: 0, pendiente: 0, a_favor: 0, saldo_ars: 0 }
// El saldo a favor se muestra en positivo: lo que el cliente tiene a favor.
const currencyAmount = (code) => {
  const balance = currencyBalance(code)

  return Number(balance.saldo_original ?? 0) < 0 ? Number(balance.a_favor ?? 0) : Number(balance.saldo_original ?? 0)
}
const balanceCodes = computed(() => Object.keys(balances.value).sort((a, b) => (a === 'ARS' ? -1 : (b === 'ARS' ? 1 : a.localeCompare(b)))))

// Filtro de movimientos por moneda: la consulta entera o una sola moneda.
const activeCurrency = ref(String(new URLSearchParams(window.location.search).get('moneda') ?? '').toUpperCase())
const visibleMovements = computed(() => (activeCurrency.value === '' ? movements.value : movements.value.filter((row) => row.moneda === activeCurrency.value)))
const setCurrency = (code) => {
  activeCurrency.value = code
  const query = new URLSearchParams(window.location.search)
  if (code === '') query.delete('moneda')
  else query.set('moneda', code)
  const suffix = query.toString()
  window.history.replaceState({}, '', `${window.location.pathname}${suffix ? `?${suffix}` : ''}`)
}

const isDifference = (row) => row.tipo === 'DIFERENCIA_CAMBIO' || row.tipo === 'REVERSION_DIFERENCIA_CAMBIO'
const isReversal = (row) => row.tipo === 'ANULACION' || row.tipo === 'ANULACION_COBRO' || row.tipo === 'REVERSION_DIFERENCIA_CAMBIO'
const origin = (row) => {
  if (isDifference(row)) return 'Diferencia de cambio'
  if (row.tipo === 'ANULACION') return null
  if (row.tipo === 'COBRO' || row.tipo === 'ANULACION_COBRO') {
    return row.moneda === 'ARS' ? formatMoney(row.haber_original || row.debe_original, 'ARS') : formatMoney(row.haber_original || row.debe_original, row.moneda)
  }
  if (!row.moneda) return null
  if (row.moneda === 'ARS') return formatMoney(row.importe_original)
  return `${formatMoney(row.importe_original, row.moneda)} × ${formatRate(row.cotizacion)} (${rateSourceLabel(row.cotizacion_fuente)}, ${fmtDate(row.cotizacion_fecha)})`
}
const documentLabel = (document) => {
  if (document.documento_numero) return document.documento_numero
  if (document.viaje_numero) return `Viaje #${document.viaje_numero}`
  return document.modalidad_label
}
const detail = (row) => {
  const text = String(row.descripcion ?? '')
  if (row.tipo !== 'SALDO_INICIAL' || !text.startsWith('Saldo inicial')) return text

  return text.slice('Saldo inicial'.length).trim() || 'Sin referencia'
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

// Anular un cargo
const annulDialog = ref(null)
const annulling = ref(null)
const openAnnul = (row) => {
  annulling.value = row
  nextTick(() => annulDialog.value?.showModal())
}

// Registrar un cobro
const paymentDialog = ref(null)
const payment = ref({})
const openPayment = () => {
  payment.value = { fecha: today(), moneda: 'ARS', importe: '', cotizacion: '', medio: 'EFECTIVO', referencia: '', observaciones: '', diferido: false, imputaciones: {} }
  nextTick(() => paymentDialog.value?.showModal())
}
const onPaymentCurrency = () => {
  payment.value.cotizacion = payment.value.moneda === 'ARS' ? '' : (currentRates.value[payment.value.moneda] ?? '')
}
// Documentos a los que se puede imputar el cobro: los que están pendientes y
// están en la misma moneda del cobro.
const imputableDocuments = computed(() => documents.value.filter((document) => !document.anulado && document.estado === 'VIGENTE' && Number(document.saldo_pendiente ?? 0) > 0 && document.moneda === payment.value.moneda))
const paymentPreviewArs = computed(() => {
  const amount = Number(payment.value.importe)
  const rate = payment.value.moneda === 'ARS' ? 1 : Number(payment.value.cotizacion)
  if (!(amount > 0) || !(rate > 0)) return null

  return Math.round(amount * rate * 100) / 100
})
const imputedTotal = computed(() => Object.values(payment.value.imputaciones ?? {}).reduce((total, value) => total + (Number(value) || 0), 0))
const imputedExceeds = computed(() => {
  const amount = Number(payment.value.importe)

  return amount > 0 && imputedTotal.value > amount + 0.000001
})

// Anular un cobro
const paymentAnnulDialog = ref(null)
const annullingPayment = ref(null)
const openPaymentAnnul = (row) => {
  annullingPayment.value = row
  nextTick(() => paymentAnnulDialog.value?.showModal())
}
</script>

<template>
  <div>
    <PageHeading eyebrow="Clientes · Cuenta corriente" :title="customer.nombre ?? 'Cliente'" :description="customer.identificacion_fiscal ? `CUIT/ID fiscal ${customer.identificacion_fiscal} · Estado de cuenta multimoneda` : 'Estado de cuenta multimoneda'" :back="{ href: links.accounts ?? '/clientes/cuenta-corriente', label: 'Volver a cuenta corriente' }">
      <template #actions>
        <button v-if="canCollect" type="button" :class="primaryButton" data-testid="open-payment" @click="openPayment">Registrar cobro</button>
        <button v-if="canFormalize && !data.hasOpeningBalance" type="button" :class="secondaryButton" data-testid="open-opening-balance" @click="openOpening">Cargar saldo inicial</button>
      </template>
    </PageHeading>

    <section aria-label="Saldos por moneda" class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
      <article v-for="code in balanceCodes" :key="code" class="rounded-xl border border-border bg-surface-raised p-4 shadow-card" :data-testid="`balance-card-${code}`">
        <p class="text-xs font-semibold text-ink-muted">{{ code }}</p>
        <p class="mt-2 text-xl font-bold" :class="balanceClass(currencyBalance(code).saldo_original)">{{ formatMoney(currencyAmount(code), code) }}</p>
        <p class="text-xs text-ink-muted">{{ balanceLabel(currencyBalance(code).saldo_original) }}</p>
        <p class="mt-1 text-xs text-ink-muted">Histórico {{ formatMoney(currencyBalance(code).saldo_ars) }}</p>
      </article>
      <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card">
        <p class="text-xs font-semibold text-ink-muted">Consolidado histórico</p>
        <p class="mt-2 text-xl font-bold" :class="balanceClass(totals.saldo_ars)" data-testid="statement-balance">{{ formatMoney(totals.saldo_ars) }}</p>
        <p class="text-xs text-ink-muted">Debe {{ formatMoney(totals.debe_ars) }} · Haber {{ formatMoney(totals.haber_ars) }}</p>
      </article>
      <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card">
        <p class="text-xs font-semibold text-ink-muted">Valorización actual</p>
        <p class="mt-2 text-xl font-bold text-ink" data-testid="statement-valuation">{{ valuation.disponible ? formatMoney(valuation.ars) : 'No disponible' }}</p>
        <p class="text-xs text-ink-muted">{{ valuation.disponible ? 'Saldo pendiente a cotizaciones vigentes' : `Sin cotización para ${(valuation.monedas_sin_cotizacion ?? []).join(', ')}` }}</p>
      </article>
    </section>

    <PanelCard title="Documentos" :count="documents.length" flush>
      <p v-if="documents.length === 0" class="p-5 text-sm text-ink-muted" data-testid="documents-empty">Este cliente todavía no tiene documentos de deuda.</p>
      <ul v-else class="divide-y divide-border-subtle sm:hidden" data-testid="documents-cards">
        <li v-for="document in documents" :key="document.id" class="space-y-1 px-4 py-3 text-sm" :class="document.anulado ? 'opacity-60' : ''">
          <div class="flex items-start justify-between gap-3">
            <div>
              <p class="font-semibold text-ink">{{ documentLabel(document) }}<span v-if="document.anulado" class="ml-2 rounded-full bg-surface-muted px-2 py-0.5 text-xs font-semibold text-ink-muted">Anulado</span></p>
              <p class="text-xs text-ink-muted">{{ fmtDate(document.fecha) }} · {{ document.modalidad_label }}</p>
              <p class="text-xs text-ink-muted">Cotización {{ formatRate(document.cotizacion) }} ({{ rateSourceLabel(document.cotizacion_fuente) }}, {{ fmtDate(document.cotizacion_fecha) }})</p>
            </div>
            <div class="whitespace-nowrap text-right">
              <p class="font-semibold text-ink">{{ formatMoney(document.saldo_pendiente, document.moneda) }}</p>
              <p class="text-xs text-ink-muted">de {{ formatMoney(document.importe_original, document.moneda) }}</p>
              <p class="text-xs font-semibold" :class="document.estado_cobranza === 'CANCELADO' ? 'text-success-strong' : 'text-ink-muted'">{{ document.estado_cobranza_label }}</p>
            </div>
          </div>
        </li>
      </ul>
      <div v-if="documents.length > 0" class="hidden overflow-x-auto sm:block">
        <table class="w-full min-w-[64rem] text-left text-sm" data-testid="documents-table">
          <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted">
            <tr><th class="px-3 py-2">Fecha</th><th class="px-3 py-2">Documento</th><th class="px-3 py-2">Moneda</th><th class="px-3 py-2 text-right">Importe original</th><th class="px-3 py-2 text-right">Pagado</th><th class="px-3 py-2 text-right">Saldo pendiente</th><th class="px-3 py-2 text-right">Cotización</th><th class="px-3 py-2 text-right">Equivalente ARS</th><th class="px-3 py-2">Cobranza</th></tr>
          </thead>
          <tbody class="divide-y divide-border-subtle">
            <tr v-for="document in documents" :key="document.id" :class="document.anulado ? 'opacity-60' : ''">
              <td class="px-3 py-2 text-ink-muted">{{ fmtDate(document.fecha) }}</td>
              <td class="px-3 py-2">
                <span class="font-semibold text-ink">{{ documentLabel(document) }}</span>
                <span v-if="document.anulado" class="ml-2 rounded-full bg-surface-muted px-2 py-0.5 text-xs font-semibold text-ink-muted">Anulado</span>
                <span class="block text-xs text-ink-muted">{{ document.modalidad_label }}<a v-if="document.viaje_id" :href="`${links.tripsBase}/${document.viaje_id}`" class="ml-2 font-semibold text-primary hover:underline">Ver viaje</a></span>
              </td>
              <td class="px-3 py-2 text-ink">{{ document.moneda }}</td>
              <td class="whitespace-nowrap px-3 py-2 text-right text-ink">{{ formatMoney(document.importe_original, document.moneda) }}</td>
              <td class="whitespace-nowrap px-3 py-2 text-right text-ink">{{ formatMoney(document.importe_pagado, document.moneda) }}</td>
              <td class="whitespace-nowrap px-3 py-2 text-right font-semibold" :class="balanceClass(document.saldo_pendiente)">{{ formatMoney(document.saldo_pendiente, document.moneda) }}</td>
              <td class="whitespace-nowrap px-3 py-2 text-right text-xs text-ink-muted">{{ formatRate(document.cotizacion) }}<span class="block">{{ fmtDate(document.cotizacion_fecha) }}</span></td>
              <td class="whitespace-nowrap px-3 py-2 text-right text-ink">{{ formatMoney(document.importe_ars) }}</td>
              <td class="px-3 py-2 text-xs" :class="document.estado_cobranza === 'CANCELADO' ? 'text-success-strong' : 'text-ink-muted'">{{ document.estado_cobranza_label }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </PanelCard>

    <PanelCard v-if="payments.length > 0" title="Cobros" :count="payments.length" flush>
      <ul class="divide-y divide-border-subtle" data-testid="payments-list">
        <li v-for="paymentRow in payments" :key="paymentRow.id" class="space-y-1 px-4 py-3 text-sm" :class="paymentRow.anulado ? 'opacity-60' : ''">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <p class="font-semibold text-ink">{{ fmtDate(paymentRow.fecha) }} · {{ paymentRow.medio_label }}<span v-if="paymentRow.anulado" class="ml-2 rounded-full bg-surface-muted px-2 py-0.5 text-xs font-semibold text-ink-muted">Anulado</span><span v-else-if="paymentRow.estado === 'PENDIENTE'" class="ml-2 rounded-full bg-surface-muted px-2 py-0.5 text-xs font-semibold text-ink-muted">Sin acreditar</span></p>
              <p class="text-xs text-ink-muted">{{ formatMoney(paymentRow.importe, paymentRow.moneda) }} × {{ formatRate(paymentRow.cotizacion) }} ({{ rateSourceLabel(paymentRow.cotizacion_fuente) }}, {{ fmtDate(paymentRow.cotizacion_fecha) }}) = {{ formatMoney(paymentRow.importe_ars) }}</p>
              <p v-if="paymentRow.referencia" class="text-xs text-ink-muted">Referencia {{ paymentRow.referencia }}</p>
              <p v-if="paymentRow.motivo_anulacion" class="text-xs text-danger-strong">Anulado: {{ paymentRow.motivo_anulacion }}</p>
            </div>
            <div class="flex gap-3">
              <form v-if="canCollect && paymentRow.estado === 'PENDIENTE'" method="post" :action="`${links.paymentsBase}${paymentRow.id}/acreditar`" class="inline">
                <input type="hidden" :name="csrf.name" :value="csrf.hash" />
                <input type="hidden" name="volver" :value="data.returnTo" />
                <button type="submit" class="text-xs font-semibold text-primary hover:underline">Acreditar</button>
              </form>
              <button v-if="canCollect && !paymentRow.anulado" type="button" class="text-xs font-semibold text-danger-strong hover:underline" @click="openPaymentAnnul(paymentRow)">Anular cobro</button>
            </div>
          </div>
          <ul v-if="paymentRow.imputaciones?.length" class="mt-2 space-y-1 text-xs text-ink-muted">
            <li v-for="imputation in paymentRow.imputaciones" :key="imputation.id" class="flex flex-wrap gap-x-2">
              <span>Documento #{{ imputation.cargo_id }} · {{ formatMoney(imputation.importe_original, imputation.moneda) }} (doc {{ formatRate(imputation.cotizacion_cargo) }} / cobro {{ formatRate(imputation.cotizacion_pago) }})</span>
              <span :class="Number(imputation.diferencia_cambio) > 0 ? 'text-success-strong' : (Number(imputation.diferencia_cambio) < 0 ? 'text-danger-strong' : '')">Diferencia de cambio {{ formatMoney(imputation.diferencia_cambio) }}</span>
            </li>
          </ul>
        </li>
      </ul>
    </PanelCard>

    <PanelCard title="Movimientos" :count="visibleMovements.length" flush>
      <div class="flex flex-wrap gap-2 border-b border-border-subtle px-4 py-3">
        <button type="button" :class="secondaryButton" data-testid="filter-all" @click="setCurrency('')">Todos</button>
        <button v-for="code in balanceCodes" :key="code" type="button" :class="secondaryButton" :data-testid="`filter-${code}`" @click="setCurrency(code)">{{ code }}</button>
      </div>
      <p v-if="visibleMovements.length === 0" class="p-5 text-sm text-ink-muted" data-testid="statement-empty">Este cliente todavía no tiene movimientos.</p>
      <ul v-else class="divide-y divide-border-subtle sm:hidden" data-testid="statement-cards">
        <li v-for="row in visibleMovements" :key="row.id" class="space-y-1 px-4 py-3 text-sm" :class="row.cargo_anulado && row.tipo !== 'ANULACION' ? 'opacity-60' : ''">
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
            <button v-if="canFormalize && row.cargo_vigente" type="button" class="text-xs font-semibold text-danger-strong hover:underline" @click="openAnnul(row)">Anular</button>
          </div>
        </li>
      </ul>
      <div v-if="visibleMovements.length > 0" class="hidden overflow-x-auto sm:block">
        <table class="w-full min-w-[60rem] text-left text-sm" data-testid="statement-table">
          <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted">
            <tr><th class="px-3 py-2">Fecha</th><th class="px-3 py-2">Concepto</th><th class="px-3 py-2">Origen</th><th class="px-3 py-2 text-right">Debe</th><th class="px-3 py-2 text-right">Haber</th><th class="px-3 py-2 text-right">Saldo</th><th v-if="canFormalize" class="px-3 py-2"></th></tr>
          </thead>
          <tbody class="divide-y divide-border-subtle">
            <tr v-for="row in visibleMovements" :key="row.id" :class="(row.cargo_anulado && row.tipo !== 'ANULACION') || isReversal(row) ? 'opacity-70' : ''">
              <td class="px-3 py-2 text-ink-muted">{{ fmtDate(row.fecha) }}</td>
              <td class="px-3 py-2">
                <span class="font-semibold text-ink">{{ row.tipo_label }}</span>
                <span v-if="row.cargo_anulado && row.tipo !== 'ANULACION'" class="ml-2 rounded-full bg-surface-muted px-2 py-0.5 text-xs font-semibold text-ink-muted">Anulado</span>
                <span v-if="isDifference(row)" class="ml-2 rounded-full bg-info-subtle px-2 py-0.5 text-xs font-semibold text-info-strong">Diferencia de cambio</span>
                <span class="block text-xs text-ink-muted">{{ detail(row) }}</span>
                <a v-if="row.viaje_id" :href="`${links.tripsBase}/${row.viaje_id}`" class="text-xs font-semibold text-primary hover:underline">Ver viaje</a>
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

    <dialog v-if="canCollect" ref="paymentDialog" aria-labelledby="payment-title" @click.self="paymentDialog?.close()" class="m-auto w-[min(46rem,calc(100vw-2rem))] max-h-[calc(100vh-2rem)] overflow-y-auto rounded-xl border border-border bg-surface-raised p-0 text-ink shadow-xl backdrop:bg-black/60">
      <form method="post" :action="`${links.accounts}/${customer.id}/cobranzas`" class="p-5 sm:p-6" data-testid="payment-form">
        <input type="hidden" :name="csrf.name" :value="csrf.hash" />
        <h2 id="payment-title" class="text-lg font-bold text-ink">Registrar un cobro</h2>
        <p class="mt-1 text-sm text-ink-muted">El cobro se registra en su propia moneda, con la cotización de ese momento. Si lo imputás a un documento, tiene que ser de la misma moneda: las monedas distintas no se compensan solas.</p>
        <div class="mt-4 grid gap-3 sm:grid-cols-2">
          <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Fecha</span><input v-model="payment.fecha" type="date" name="fecha" :max="today()" required :class="fieldClass" /></label>
          <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Medio de pago</span>
            <select v-model="payment.medio" name="medio" :class="fieldClass"><option value="EFECTIVO">Efectivo</option><option value="TRANSFERENCIA">Transferencia</option><option value="CHEQUE">Cheque</option><option value="E_CHEQ">eCheq</option></select>
          </label>
          <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Importe</span><input v-model="payment.importe" type="number" name="importe" min="0.01" step="0.01" required :class="fieldClass" /></label>
          <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Moneda</span>
            <select v-model="payment.moneda" name="moneda" :class="fieldClass" @change="onPaymentCurrency"><option v-for="currency in currencies" :key="currency" :value="currency">{{ currency }}</option></select>
          </label>
          <label v-if="payment.moneda !== 'ARS'" class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Cotización a ARS</span><input v-model="payment.cotizacion" type="number" name="cotizacion" min="0.000001" step="0.000001" required :class="fieldClass" /></label>
          <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Referencia</span><input v-model="payment.referencia" type="text" name="referencia" maxlength="100" placeholder="Ej.: número de recibo" :class="fieldClass" /></label>
          <label class="block sm:col-span-2"><span class="mb-1 block text-xs font-semibold text-ink-muted">Observaciones</span><input v-model="payment.observaciones" type="text" name="observaciones" maxlength="500" :class="fieldClass" /></label>
          <label class="block sm:col-span-2 flex items-center gap-2 text-sm text-ink"><input v-model="payment.diferido" type="checkbox" name="diferido" value="1" />El cobro todavía no impacta en la cuenta corriente (transferencia sin verificar, cheque no recibido)</label>
        </div>

        <div v-if="imputableDocuments.length > 0" class="mt-4">
          <p class="text-xs font-semibold text-ink-muted">Imputar a documentos en {{ payment.moneda }}</p>
          <p class="mt-1 text-xs text-ink-muted">Lo que no imputes queda como anticipo, es decir, saldo a favor del cliente en esa moneda.</p>
          <ul class="mt-2 divide-y divide-border-subtle rounded-lg border border-border-subtle">
            <li v-for="document in imputableDocuments" :key="document.id" class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
              <div>
                <p class="font-semibold text-ink">{{ documentLabel(document) }}</p>
                <p class="text-xs text-ink-muted">Pendiente {{ formatMoney(document.saldo_pendiente, document.moneda) }} · cotiza {{ formatRate(document.cotizacion) }}</p>
              </div>
              <div class="flex items-center gap-2">
                <input type="hidden" name="cargo_id[]" :value="document.id" />
                <input v-model="payment.imputaciones[document.id]" type="number" name="importe_cargo[]" min="0" step="0.01" :max="document.saldo_pendiente" placeholder="0,00" :class="[fieldClass, 'w-32']" />
              </div>
            </li>
          </ul>
        </div>

        <p class="mt-4 rounded-lg border border-info/30 bg-info-subtle px-3 py-2 text-sm text-info-strong" data-testid="payment-preview">
          <template v-if="paymentPreviewArs !== null">Se acredita <strong>{{ formatMoney(paymentPreviewArs) }}</strong> y se imputa <strong>{{ formatMoney(imputedTotal, payment.moneda) }}</strong>.</template>
          <template v-else>Completá el importe{{ payment.moneda === 'ARS' ? '' : ' y la cotización' }} para ver el equivalente en pesos.</template>
        </p>
        <p v-if="imputedExceeds" class="mt-2 rounded-lg border border-danger/30 bg-danger-subtle px-3 py-2 text-sm text-danger-strong" data-testid="payment-error">La suma de las imputaciones supera el importe del cobro.</p>
        <div class="mt-5 flex justify-end gap-2 border-t border-border-subtle pt-4">
          <button type="button" :class="secondaryButton" @click="paymentDialog?.close()">Volver</button>
          <button type="submit" :class="primaryButton" :disabled="imputedExceeds">Registrar cobro</button>
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

    <dialog v-if="canCollect" ref="paymentAnnulDialog" aria-labelledby="payment-annul-title" @click.self="paymentAnnulDialog?.close()" class="m-auto w-[min(36rem,calc(100vw-2rem))] rounded-xl border border-border bg-surface-raised p-0 text-ink shadow-xl backdrop:bg-black/60">
      <form v-if="annullingPayment" method="post" :action="`${links.paymentsBase}${annullingPayment.id}/anular`" class="p-5 sm:p-6" data-testid="payment-annul-form">
        <input type="hidden" :name="csrf.name" :value="csrf.hash" />
        <input type="hidden" name="volver" :value="data.returnTo" />
        <h2 id="payment-annul-title" class="text-lg font-bold text-ink">Anular el cobro</h2>
        <p class="mt-1 text-sm text-ink-muted">{{ fmtDate(annullingPayment.fecha) }} · {{ annullingPayment.medio_label }} · {{ formatMoney(annullingPayment.importe, annullingPayment.moneda) }}</p>
        <p class="mt-3 text-sm text-ink-muted">Se restituye la deuda en su moneda original con un contraasiento y se revierten las diferencias de cambio asociadas. El cobro queda visible como anulado.</p>
        <label class="mt-3 block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Motivo</span><textarea name="motivo" rows="3" minlength="5" maxlength="500" required :class="fieldClass"></textarea></label>
        <div class="mt-5 flex justify-end gap-2 border-t border-border-subtle pt-4">
          <button type="button" :class="secondaryButton" @click="paymentAnnulDialog?.close()">Volver</button>
          <button type="submit" :class="dangerButton">Anular cobro</button>
        </div>
      </form>
    </dialog>
  </div>
</template>
