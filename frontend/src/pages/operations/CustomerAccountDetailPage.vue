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

// Anular
const annulDialog = ref(null)
const annulling = ref(null)
const openAnnul = (row) => {
  annulling.value = row
  nextTick(() => annulDialog.value?.showModal())
}
</script>

<template>
  <div>
    <PageHeading eyebrow="Clientes · Cuenta corriente" :title="customer.nombre ?? 'Cliente'" :description="customer.identificacion_fiscal ? `CUIT/ID fiscal ${customer.identificacion_fiscal} · Estado de cuenta en pesos` : 'Estado de cuenta en pesos'" :back="{ href: links.accounts ?? '/clientes/cuenta-corriente', label: 'Volver a cuenta corriente' }">
      <template #actions>
        <button v-if="canFormalize && !data.hasOpeningBalance" type="button" :class="secondaryButton" data-testid="open-opening-balance" @click="openOpening">Cargar saldo inicial</button>
      </template>
    </PageHeading>

    <section aria-label="Saldo" class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
      <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card"><p class="text-xs font-semibold text-ink-muted">Debe</p><p class="mt-2 text-xl font-bold text-ink">{{ money(totals.debe_ars) }}</p></article>
      <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card"><p class="text-xs font-semibold text-ink-muted">Haber</p><p class="mt-2 text-xl font-bold text-ink">{{ money(totals.haber_ars) }}</p></article>
      <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card"><p class="text-xs font-semibold text-ink-muted">{{ balanceLabel }}</p><p class="mt-2 text-xl font-bold" :class="balanceClass(totals.saldo_ars)" data-testid="statement-balance">{{ money(totals.saldo_ars) }}</p></article>
    </section>

    <PanelCard title="Movimientos" :count="movements.length" flush>
      <p v-if="movements.length === 0" class="p-5 text-sm text-ink-muted" data-testid="statement-empty">Este cliente todavía no tiene movimientos.</p>
      <div v-else class="overflow-x-auto">
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
                <span class="block text-xs text-ink-muted">{{ row.descripcion }}</span>
                <a v-if="row.viaje_id" :href="`${links.tripsBase}/${row.viaje_id}`" class="text-xs font-semibold text-primary hover:underline">Ver viaje</a>
              </td>
              <td class="px-3 py-2 text-xs text-ink-muted">{{ origin(row) }}</td>
              <td class="px-3 py-2 text-right text-ink">{{ row.debe_ars ? money(row.debe_ars) : '' }}</td>
              <td class="px-3 py-2 text-right text-ink">{{ row.haber_ars ? money(row.haber_ars) : '' }}</td>
              <td class="px-3 py-2 text-right font-semibold" :class="balanceClass(row.saldo_ars)">{{ money(row.saldo_ars) }}</td>
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
  </div>
</template>
