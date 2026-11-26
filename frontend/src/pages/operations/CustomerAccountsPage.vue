<script setup>
import * as format from '../../ui/format.js'
/**
 * Cuenta corriente de clientes (#530): un saldo único en ARS por cliente,
 * derivado del libro de movimientos (Debe − Haber).
 */
import { computed, nextTick, ref } from 'vue'
import PageHeading from './components/PageHeading.vue'
import PanelCard from './components/PanelCard.vue'
import { fieldClass, primaryButton, secondaryButton } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })

const links = computed(() => props.data.links ?? {})
const rows = computed(() => props.data.rows ?? [])
const totals = computed(() => props.data.totals ?? {})
const customers = computed(() => props.data.customers ?? [])
const canFormalize = computed(() => Boolean(props.data.canFormalize))

const money = (value) => format.formatMoney(value ?? 0, { cents: true })
const fmtDate = (value) => format.formatDate(value)
const balanceClass = (value) => (value > 0 ? 'text-danger-strong' : (value < 0 ? 'text-success-strong' : 'text-ink-muted'))
const balanceLabel = (value) => (value > 0 ? 'a cobrar' : (value < 0 ? 'a favor del cliente' : 'saldada'))

// Abrir la cuenta de un cliente que todavía no tiene movimientos.
const pickerDialog = ref(null)
const pickedCustomer = ref('')
const openPicker = () => {
  pickedCustomer.value = ''
  nextTick(() => pickerDialog.value?.showModal())
}
const goToCustomer = () => {
  if (pickedCustomer.value) window.location.href = `${links.value.accounts}/${pickedCustomer.value}`
}
</script>

<template>
  <div>
    <PageHeading eyebrow="Clientes · Cuenta corriente" title="Cuenta corriente de clientes" description="Saldo único en pesos por cliente. Los cargos nacen al formalizar cada tramo o al cargar el saldo inicial; la moneda y la cotización de origen quedan en cada movimiento." :back="{ href: links.queue ?? '/viajes/cierre-comercial', label: 'Ir al cierre comercial' }">
      <template #actions>
        <button type="button" :class="secondaryButton" data-testid="open-customer" @click="openPicker">{{ canFormalize ? 'Abrir cuenta / saldo inicial' : 'Buscar cliente' }}</button>
      </template>
    </PageHeading>

    <section aria-label="Totales" class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
      <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card"><p class="text-xs font-semibold text-ink-muted">Debe</p><p class="mt-2 text-xl font-bold text-ink">{{ money(totals.debe_ars) }}</p></article>
      <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card"><p class="text-xs font-semibold text-ink-muted">Haber</p><p class="mt-2 text-xl font-bold text-ink">{{ money(totals.haber_ars) }}</p></article>
      <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card"><p class="text-xs font-semibold text-ink-muted">Saldo total</p><p class="mt-2 text-xl font-bold" :class="balanceClass(totals.saldo_ars)" data-testid="accounts-total">{{ money(totals.saldo_ars) }}</p></article>
    </section>

    <form method="get" :action="links.accounts" class="mb-4 flex flex-wrap gap-2">
      <input type="search" name="q" :value="data.search ?? ''" placeholder="Buscar por nombre o CUIT" :class="[fieldClass, 'max-w-md']" />
      <button type="submit" :class="secondaryButton">Buscar</button>
    </form>

    <PanelCard title="Clientes con movimientos" :count="rows.length" flush>
      <p v-if="rows.length === 0" class="p-5 text-sm text-ink-muted" data-testid="accounts-empty">Todavía no hay movimientos. Formalizá tramos desde el cierre comercial o cargá el saldo inicial de un cliente.</p>
      <ul v-else class="divide-y divide-border-subtle sm:hidden" data-testid="accounts-cards">
        <li v-for="row in rows" :key="row.id" class="flex items-start justify-between gap-3 px-4 py-3 text-sm">
          <div><a :href="`${links.accounts}/${row.id}`" class="font-semibold text-primary hover:underline">{{ row.nombre }}</a><p class="text-xs text-ink-muted">Último movimiento {{ fmtDate(row.ultimo_movimiento) }}</p></div>
          <div class="whitespace-nowrap text-right font-semibold" :class="balanceClass(row.saldo_ars)">{{ money(row.saldo_ars) }}<p class="text-xs font-normal text-ink-muted">{{ balanceLabel(row.saldo_ars) }}</p></div>
        </li>
      </ul>
      <div v-if="rows.length > 0" class="hidden overflow-x-auto sm:block">
        <table class="w-full min-w-[44rem] text-left text-sm" data-testid="accounts-table">
          <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted">
            <tr><th class="px-3 py-2">Cliente</th><th class="px-3 py-2">Último movimiento</th><th class="px-3 py-2 text-right">Debe</th><th class="px-3 py-2 text-right">Haber</th><th class="px-3 py-2 text-right">Saldo</th></tr>
          </thead>
          <tbody class="divide-y divide-border-subtle">
            <tr v-for="row in rows" :key="row.id">
              <td class="px-3 py-2"><a :href="`${links.accounts}/${row.id}`" class="font-semibold text-primary hover:underline">{{ row.nombre }}</a><span v-if="row.identificacion_fiscal" class="block text-xs text-ink-muted">{{ row.identificacion_fiscal }}</span></td>
              <td class="px-3 py-2 text-ink-muted">{{ fmtDate(row.ultimo_movimiento) }}</td>
              <td class="whitespace-nowrap px-3 py-2 text-right text-ink">{{ money(row.debe_ars) }}</td>
              <td class="whitespace-nowrap px-3 py-2 text-right text-ink">{{ money(row.haber_ars) }}</td>
              <td class="whitespace-nowrap px-3 py-2 text-right font-semibold" :class="balanceClass(row.saldo_ars)">{{ money(row.saldo_ars) }}<span class="block text-xs font-normal text-ink-muted">{{ balanceLabel(row.saldo_ars) }}</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </PanelCard>

    <dialog ref="pickerDialog" aria-labelledby="picker-title" @click.self="pickerDialog?.close()" class="m-auto w-[min(32rem,calc(100vw-2rem))] rounded-xl border border-border bg-surface-raised p-0 text-ink shadow-xl backdrop:bg-black/60">
      <form class="p-5 sm:p-6" @submit.prevent="goToCustomer">
        <h2 id="picker-title" class="text-lg font-bold text-ink">Elegí el cliente</h2>
        <p class="mt-1 text-sm text-ink-muted">Desde su estado de cuenta podés {{ canFormalize ? 'cargar el saldo inicial y ' : '' }}ver todos sus movimientos.</p>
        <label class="mt-3 block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Cliente</span>
          <select v-model="pickedCustomer" :class="fieldClass" required data-testid="customer-picker">
            <option value="" disabled>Elegí un cliente</option>
            <option v-for="customer in customers" :key="customer.id" :value="customer.id">{{ customer.nombre }}</option>
          </select>
        </label>
        <div class="mt-5 flex justify-end gap-2 border-t border-border-subtle pt-4">
          <button type="button" :class="secondaryButton" @click="pickerDialog?.close()">Volver</button>
          <button type="submit" :class="primaryButton">Abrir estado de cuenta</button>
        </div>
      </form>
    </dialog>
  </div>
</template>
