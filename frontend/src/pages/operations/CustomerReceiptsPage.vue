<script setup>
/**
 * Listado general de recibos de cobranza (#536): paginación real, búsqueda por
 * cliente/número/referencia, filtros por fecha, estado, medio y moneda.
 */
import { computed, nextTick, ref } from 'vue'
import PageHeading from './components/PageHeading.vue'
import PanelCard from './components/PanelCard.vue'
import PaginationBar from './components/PaginationBar.vue'
import { fieldClass, primaryButton, secondaryButton, dangerButton } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })

const csrf = computed(() => props.data.csrf ?? {})
const links = computed(() => props.data.links ?? {})
const rows = computed(() => props.data.rows ?? [])
const totals = computed(() => props.data.totals ?? {})
const pagination = computed(() => props.data.pagination ?? {})
const filters = computed(() => props.data.filters ?? {})
const statuses = computed(() => props.data.statuses ?? [])
const media = computed(() => props.data.media ?? [])
const canCancel = computed(() => Boolean(props.data.canCancel))
const canPrint = computed(() => Boolean(props.data.canPrint))

const money = (value) => `ARS ${Number(value ?? 0).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
const fmtDate = (value) => {
  const raw = String(value ?? '').slice(0, 10)
  if (!raw) return '—'
  const [year, month, day] = raw.split('-')

  return `${day}/${month}/${year}`
}
const stateClass = (state) => ({
  BORRADOR: 'bg-surface-muted text-ink-muted',
  CONFIRMADO: 'bg-success-subtle text-success-strong',
  ANULADO: 'bg-danger-subtle text-danger-strong',
}[state] ?? 'bg-surface-muted text-ink-muted')

const annulDialog = ref(null)
const annulling = ref(null)
const openAnnul = (row) => {
  annulling.value = row
  nextTick(() => annulDialog.value?.showModal())
}
</script>

<template>
  <div>
    <PageHeading eyebrow="Clientes · Cobranzas" title="Recibos de cobranza" description="Cada recibo conserva la moneda y la cotización de sus medios. Confirmarlo acredita el dinero real y permite imputarlo a los documentos del cliente." :back="{ href: links.accounts ?? '/clientes/cuenta-corriente', label: 'Volver a cuenta corriente' }">
      <template #actions>
        <a :href="links.accounts ?? '/clientes/cuenta-corriente'" :class="secondaryButton">Elegir cliente para un recibo</a>
      </template>
    </PageHeading>

    <section aria-label="Totales" class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
      <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card"><p class="text-xs font-semibold text-ink-muted">Total confirmado</p><p class="mt-2 text-xl font-bold text-ink">{{ money(totals.total_ars) }}</p></article>
      <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card"><p class="text-xs font-semibold text-ink-muted">Acreditado</p><p class="mt-2 text-xl font-bold text-success-strong">{{ money(totals.acreditado_ars) }}</p></article>
      <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card"><p class="text-xs font-semibold text-ink-muted">Pendiente de acreditación</p><p class="mt-2 text-xl font-bold text-warning-strong">{{ money(totals.pendiente_ars) }}</p></article>
    </section>

    <form method="get" :action="links.receipts" class="mb-4 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-6" data-testid="receipts-filters">
      <input type="search" name="q" :value="filters.q ?? ''" placeholder="Cliente, número o referencia" :class="[fieldClass, 'lg:col-span-2']" />
      <select name="estado" :class="fieldClass"><option value="">Todos los estados</option><option v-for="status in statuses" :key="status.value" :value="status.value" :selected="filters.estado === status.value">{{ status.label }}</option></select>
      <select name="medio" :class="fieldClass"><option value="">Todos los medios</option><option v-for="medium in media" :key="medium.value" :value="medium.value" :selected="filters.medio === medium.value">{{ medium.label }}</option></select>
      <select name="moneda" :class="fieldClass"><option value="">Toda moneda</option><option value="ARS" :selected="filters.moneda === 'ARS'">ARS</option><option value="USD" :selected="filters.moneda === 'USD'">USD</option><option value="BRL" :selected="filters.moneda === 'BRL'">BRL</option></select>
      <div class="flex gap-2 lg:col-span-6">
        <label class="flex items-center gap-2 text-xs font-semibold text-ink-muted">Desde <input type="date" name="desde" :value="filters.desde ?? ''" :class="fieldClass" /></label>
        <label class="flex items-center gap-2 text-xs font-semibold text-ink-muted">Hasta <input type="date" name="hasta" :value="filters.hasta ?? ''" :class="fieldClass" /></label>
        <button type="submit" :class="secondaryButton">Filtrar</button>
        <a :href="links.receipts" class="inline-flex items-center text-sm font-semibold text-primary hover:underline">Limpiar</a>
      </div>
    </form>

    <PanelCard title="Recibos" :count="pagination.total ?? rows.length" flush>
      <p v-if="rows.length === 0" class="p-5 text-sm text-ink-muted" data-testid="receipts-empty">No hay recibos con esos filtros. Entrá a la cuenta corriente de un cliente para registrar el primero.</p>
      <ul v-else class="divide-y divide-border-subtle sm:hidden" data-testid="receipts-cards">
        <li v-for="row in rows" :key="row.id" class="space-y-2 px-4 py-3 text-sm">
          <div class="flex items-start justify-between gap-3">
            <div>
              <a :href="`${links.receiptsBase}${row.id}`" class="font-semibold text-primary hover:underline">{{ row.numero_visible }}</a>
              <p class="text-xs text-ink-muted">{{ row.cliente }} · {{ fmtDate(row.fecha_emision) }}</p>
              <p class="text-xs text-ink-muted">{{ (row.medios || []).map((m) => m.label).join(' + ') }}</p>
            </div>
            <div class="text-right"><p class="font-semibold text-ink">{{ money(row.total_ars) }}</p><span class="mt-1 inline-flex rounded-full px-2 py-0.5 text-xs font-semibold" :class="stateClass(row.estado)">{{ row.estado_label }}</span></div>
          </div>
          <div class="flex flex-wrap gap-3">
            <a :href="`${links.receiptsBase}${row.id}`" class="text-xs font-semibold text-primary hover:underline">Ver detalle</a>
            <a v-if="canPrint" :href="`${links.receiptsBase}${row.id}/imprimir`" target="_blank" rel="noopener" class="text-xs font-semibold text-primary hover:underline">Imprimir</a>
            <button v-if="canCancel && row.estado === 'CONFIRMADO'" type="button" class="text-xs font-semibold text-danger-strong hover:underline" @click="openAnnul(row)">Anular</button>
          </div>
        </li>
      </ul>
      <div v-if="rows.length > 0" class="hidden overflow-x-auto sm:block">
        <table class="w-full min-w-[64rem] text-left text-sm" data-testid="receipts-table">
          <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted">
            <tr><th class="px-3 py-2">Recibo</th><th class="px-3 py-2">Cliente</th><th class="px-3 py-2">Emisión</th><th class="px-3 py-2">Medios</th><th class="px-3 py-2 text-right">Total ARS</th><th class="px-3 py-2 text-right">Acreditado</th><th class="px-3 py-2">Estado</th><th class="px-3 py-2"></th></tr>
          </thead>
          <tbody class="divide-y divide-border-subtle">
            <tr v-for="row in rows" :key="row.id">
              <td class="px-3 py-2"><a :href="`${links.receiptsBase}${row.id}`" class="font-semibold text-primary hover:underline">{{ row.numero_visible }}</a><span class="block text-xs text-ink-muted">{{ row.sucursal }}</span></td>
              <td class="px-3 py-2"><span class="font-semibold text-ink">{{ row.cliente }}</span><span v-if="row.identificacion_fiscal" class="block text-xs text-ink-muted">{{ row.identificacion_fiscal }}</span></td>
              <td class="px-3 py-2 text-ink-muted">{{ fmtDate(row.fecha_emision) }}<span class="block text-xs">Cobrado {{ fmtDate(row.fecha_recepcion) }}</span></td>
              <td class="px-3 py-2 text-xs text-ink-muted">{{ (row.medios || []).map((m) => m.label).join(' + ') }}<span v-if="row.medios_pendientes" class="block font-semibold text-warning-strong">{{ row.medios_pendientes }} pendiente(s)</span></td>
              <td class="whitespace-nowrap px-3 py-2 text-right font-semibold text-ink">{{ money(row.total_ars) }}</td>
              <td class="whitespace-nowrap px-3 py-2 text-right text-ink">{{ money(row.acreditado_ars) }}<span v-if="row.pendiente_ars" class="block text-xs text-warning-strong">{{ money(row.pendiente_ars) }} pendiente</span></td>
              <td class="px-3 py-2"><span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold" :class="stateClass(row.estado)">{{ row.estado_label }}</span></td>
              <td class="whitespace-nowrap px-3 py-2 text-right">
                <a v-if="canPrint" :href="`${links.receiptsBase}${row.id}/imprimir`" target="_blank" rel="noopener" class="mr-3 text-xs font-semibold text-primary hover:underline">Imprimir</a>
                <button v-if="canCancel && row.estado === 'CONFIRMADO'" type="button" class="text-xs font-semibold text-danger-strong hover:underline" @click="openAnnul(row)">Anular</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <PaginationBar v-if="rows.length > 0" :pagination="pagination" />
    </PanelCard>

    <dialog ref="annulDialog" aria-labelledby="receipt-annul-title" @click.self="annulDialog?.close()" class="m-auto w-[min(36rem,calc(100vw-2rem))] rounded-xl border border-border bg-surface-raised p-0 text-ink shadow-xl backdrop:bg-black/60">
      <form v-if="annulling" method="post" :action="`${links.receiptsBase}${annulling.id}/anular`" class="p-5 sm:p-6" data-testid="receipt-annul-form">
        <input type="hidden" :name="csrf.name" :value="csrf.hash" />
        <h2 id="receipt-annul-title" class="text-lg font-bold text-ink">Anular el recibo {{ annulling.numero_visible }}</h2>
        <p class="mt-1 text-sm text-ink-muted">{{ annulling.cliente }} · {{ money(annulling.total_ars) }}. Se agrega un contraasiento por lo efectivamente acreditado y se restituyen los saldos de los documentos imputados.</p>
        <label class="mt-3 block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Motivo</span><textarea name="motivo" rows="3" minlength="5" maxlength="500" required :class="fieldClass"></textarea></label>
        <div class="mt-5 flex justify-end gap-2 border-t border-border-subtle pt-4">
          <button type="button" :class="secondaryButton" @click="annulDialog?.close()">Volver</button>
          <button type="submit" :class="dangerButton">Anular recibo</button>
        </div>
      </form>
    </dialog>
  </div>
</template>
