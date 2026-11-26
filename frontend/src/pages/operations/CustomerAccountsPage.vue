<script setup>
/**
 * Cuenta corriente de clientes multimoneda (#588): saldo pendiente en la moneda
 * original de cada deuda, consolidado histórico en pesos y valorización actual
 * con las cotizaciones vigentes.
 */
import { computed, nextTick, ref } from 'vue'
import PageHeading from './components/PageHeading.vue'
import PanelCard from './components/PanelCard.vue'
import { fieldClass, formatMoney, primaryButton, secondaryButton } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })

const links = computed(() => props.data.links ?? {})
const rows = computed(() => props.data.rows ?? [])
const totals = computed(() => props.data.totals ?? {})
const valuation = computed(() => props.data.valuation ?? {})
const pagination = computed(() => props.data.pagination ?? {})
const customers = computed(() => props.data.customers ?? [])
const currencies = computed(() => props.data.currencies ?? ['ARS'])
const canFormalize = computed(() => Boolean(props.data.canFormalize))

// Las columnas por moneda son las que hay en el conjunto filtrado, no solo las
// de la página: así la tabla no cambia de columnas al paginar.
const currencyColumns = computed(() => Object.keys(totals.value.currencies ?? {}))

const fmtDate = (value) => {
  const raw = String(value ?? '').slice(0, 10)
  if (!raw) return '—'
  const [year, month, day] = raw.split('-')

  return `${day}/${month}/${year}`
}
const balanceClass = (value) => (value > 0 ? 'text-danger-strong' : (value < 0 ? 'text-success-strong' : 'text-ink-muted'))
const balanceLabel = (value) => (value > 0 ? 'a cobrar' : (value < 0 ? 'a favor del cliente' : 'saldada'))

const currencyBalance = (row, code) => row.balances?.[code] ?? { saldo_original: 0, pendiente: 0, a_favor: 0 }
// El saldo a favor se muestra en positivo: lo que el cliente tiene a favor.
const currencyAmount = (row, code) => {
  const balance = currencyBalance(row, code)

  return Number(balance.saldo_original ?? 0) < 0 ? Number(balance.a_favor ?? 0) : Number(balance.saldo_original ?? 0)
}
const favorCurrencies = computed(() => Object.values(totals.value.currencies ?? {}).filter((entry) => Number(entry.a_favor ?? 0) > 0))

// Paginación con el mismo patrón de Viajes (#532): se preservan los filtros.
const listUrl = (changes) => {
  const query = new URLSearchParams(window.location.search)
  for (const [key, value] of Object.entries(changes)) {
    if (value === null) query.delete(key)
    else query.set(key, String(value))
  }
  const suffix = query.toString()

  return `${links.value.accounts}${suffix ? `?${suffix}` : ''}`
}
const pageUrl = (page) => listUrl({ pagina: page <= 1 ? null : page })
const pageSizeUrl = (size) => listUrl({ pagina: null, por_pagina: Number(size) === 25 ? null : size })
const pageNumbers = computed(() => {
  const pages = Number(pagination.value.pages ?? 1)
  const current = Number(pagination.value.page ?? 1)
  const from = Math.max(1, current - 2)
  const to = Math.min(pages, from + 4)
  const numbers = []
  for (let page = from; page <= to; page += 1) numbers.push(page)

  return numbers
})

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
    <PageHeading eyebrow="Clientes · Cuenta corriente" title="Cuenta corriente de clientes" description="Cada deuda conserva su moneda original: el saldo pendiente se ve en la moneda en que se pactó y el consolidado histórico se ve en pesos, con las diferencias de cambio ya registradas." :back="{ href: links.queue ?? '/viajes/cierre-comercial', label: 'Ir al cierre comercial' }">
      <template #actions>
        <button type="button" :class="secondaryButton" data-testid="open-customer" @click="openPicker">{{ canFormalize ? 'Abrir cuenta / saldo inicial' : 'Buscar cliente' }}</button>
      </template>
    </PageHeading>

    <section aria-label="Totales" class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
      <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card"><p class="text-xs font-semibold text-ink-muted">Consolidado histórico</p><p class="mt-2 text-xl font-bold text-ink" data-testid="accounts-total">{{ formatMoney(totals.saldo_ars) }}</p><p class="text-xs text-ink-muted">Debe menos haber del libro</p></article>
      <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card"><p class="text-xs font-semibold text-ink-muted">Valorización actual</p><p class="mt-2 text-xl font-bold text-ink" data-testid="accounts-valuation">{{ valuation.disponible ? formatMoney(valuation.ars) : 'No disponible' }}</p><p class="text-xs text-ink-muted">{{ valuation.disponible ? 'Saldo pendiente a cotizaciones vigentes' : `Sin cotización para ${(valuation.monedas_sin_cotizacion ?? []).join(', ')}` }}</p></article>
      <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card"><p class="text-xs font-semibold text-ink-muted">Debe</p><p class="mt-2 text-xl font-bold text-ink">{{ formatMoney(totals.debe_ars) }}</p></article>
      <article class="rounded-xl border border-border bg-surface-raised p-4 shadow-card"><p class="text-xs font-semibold text-ink-muted">Saldos a favor</p><p class="mt-2 text-xl font-bold text-success-strong" data-testid="accounts-favor">{{ favorCurrencies.length ? favorCurrencies.map((entry) => formatMoney(entry.a_favor, entry.moneda)).join(' · ') : '—' }}</p></article>
    </section>

    <form method="get" :action="links.accounts" class="mb-4 flex flex-wrap items-end gap-2">
      <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Buscar cliente</span>
        <input type="search" name="q" :value="data.search ?? ''" placeholder="Nombre o CUIT" :class="[fieldClass, 'max-w-xs']" /></label>
      <label class="block"><span class="mb-1 block text-xs font-semibold text-ink-muted">Moneda</span>
        <select name="moneda" :class="fieldClass" data-testid="accounts-currency-filter">
          <option value="">Todas</option>
          <option v-for="currency in currencies" :key="currency" :value="currency" :selected="data.currency === currency">{{ currency }}</option>
        </select></label>
      <button type="submit" :class="secondaryButton">Filtrar</button>
    </form>

    <PanelCard title="Clientes con movimientos" :count="rows.length" flush>
      <p v-if="rows.length === 0" class="p-5 text-sm text-ink-muted" data-testid="accounts-empty">Todavía no hay movimientos. Formalizá tramos desde el cierre comercial o cargá el saldo inicial de un cliente.</p>
      <ul v-else class="divide-y divide-border-subtle sm:hidden" data-testid="accounts-cards">
        <li v-for="row in rows" :key="row.id" class="flex items-start justify-between gap-3 px-4 py-3 text-sm">
          <div><a :href="`${links.accounts}/${row.id}`" class="font-semibold text-primary hover:underline">{{ row.nombre }}</a><p class="text-xs text-ink-muted">Último movimiento {{ fmtDate(row.ultimo_movimiento) }}</p>
            <p v-for="code in currencyColumns" :key="code" class="text-xs text-ink-muted">{{ code }}: {{ formatMoney(currencyAmount(row, code), code) }}</p>
          </div>
          <div class="whitespace-nowrap text-right font-semibold" :class="balanceClass(row.saldo_ars)">{{ formatMoney(row.saldo_ars) }}<p class="text-xs font-normal text-ink-muted">{{ balanceLabel(row.saldo_ars) }}</p></div>
        </li>
      </ul>
      <div v-if="rows.length > 0" class="hidden overflow-x-auto sm:block">
        <table class="w-full min-w-[52rem] text-left text-sm" data-testid="accounts-table">
          <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted">
            <tr>
              <th class="px-3 py-2">Cliente</th>
              <th v-for="code in currencyColumns" :key="code" class="px-3 py-2 text-right">{{ code }}</th>
              <th class="px-3 py-2 text-right">Histórico ARS</th>
              <th class="px-3 py-2 text-right">Valorización actual</th>
              <th class="px-3 py-2">Último movimiento</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border-subtle">
            <tr v-for="row in rows" :key="row.id">
              <td class="px-3 py-2"><a :href="`${links.accounts}/${row.id}`" class="font-semibold text-primary hover:underline">{{ row.nombre }}</a><span v-if="row.identificacion_fiscal" class="block text-xs text-ink-muted">{{ row.identificacion_fiscal }}</span></td>
              <td v-for="code in currencyColumns" :key="code" class="whitespace-nowrap px-3 py-2 text-right" :class="balanceClass(currencyBalance(row, code).saldo_original)">
                {{ formatMoney(currencyAmount(row, code), code) }}
                <span class="block text-xs font-normal text-ink-muted">{{ balanceLabel(currencyBalance(row, code).saldo_original) }}</span>
              </td>
              <td class="whitespace-nowrap px-3 py-2 text-right font-semibold" :class="balanceClass(row.saldo_ars)">{{ formatMoney(row.saldo_ars) }}</td>
              <td class="whitespace-nowrap px-3 py-2 text-right text-ink">{{ row.valuation?.disponible ? formatMoney(row.valuation.ars) : 'No disponible' }}</td>
              <td class="px-3 py-2 text-ink-muted">{{ fmtDate(row.ultimo_movimiento) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <nav v-if="rows.length > 0 && Number(pagination.pages ?? 1) > 1" aria-label="Paginación" class="flex flex-wrap items-center justify-between gap-2 border-t border-border-subtle px-4 py-3 text-sm" data-testid="accounts-pagination">
        <p class="text-xs text-ink-muted">Mostrando {{ pagination.from }}–{{ pagination.to }} de {{ pagination.total }}</p>
        <div class="flex items-center gap-2">
          <a v-if="Number(pagination.page ?? 1) > 1" :href="pageUrl(Number(pagination.page) - 1)" class="font-semibold text-primary hover:underline">Anterior</a>
          <a v-for="number in pageNumbers" :key="number" :href="pageUrl(number)" class="px-2 py-1 font-semibold" :class="number === Number(pagination.page) ? 'rounded bg-primary text-primary-foreground' : 'text-primary hover:underline'">{{ number }}</a>
          <a v-if="Number(pagination.page ?? 1) < Number(pagination.pages ?? 1)" :href="pageUrl(Number(pagination.page) + 1)" class="font-semibold text-primary hover:underline">Siguiente</a>
          <select :value="pagination.perPage" class="rounded-lg border border-border bg-surface-raised px-2 py-1 text-xs" @change="(event) => { window.location.href = pageSizeUrl(event.target.value) }">
            <option v-for="option in (pagination.perPageOptions ?? [25])" :key="option" :value="option">{{ option }} por página</option>
          </select>
        </div>
      </nav>
    </PanelCard>

    <dialog ref="pickerDialog" aria-labelledby="picker-title" @click.self="pickerDialog?.close()" class="m-auto w-[min(32rem,calc(100vw-2rem))] rounded-xl border border-border bg-surface-raised p-0 text-ink shadow-xl backdrop:bg-black/60">
      <form class="p-5 sm:p-6" @submit.prevent="goToCustomer">
        <h2 id="picker-title" class="text-lg font-bold text-ink">Elegí el cliente</h2>
        <p class="mt-1 text-sm text-ink-muted">Desde su estado de cuenta podés {{ canFormalize ? 'cargar el saldo inicial, registrar cobros y ' : '' }}ver todos sus movimientos.</p>
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
