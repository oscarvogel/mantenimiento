<script setup>
import * as format from '../../ui/format.js'
import { computed, reactive, ref } from 'vue'
import EmptyState from './components/EmptyState.vue'
import PageHeading from './components/PageHeading.vue'
import PanelCard from './components/PanelCard.vue'
import { fieldClass, primaryButton, secondaryButton } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })

const loads = computed(() => props.data.loads ?? [])
const summary = computed(() => props.data.summary ?? { loads: 0, trips: 0, liters: 0 })
const currencies = computed(() => props.data.currencies?.length ? props.data.currencies : [{ code: 'ARS', label: 'Peso argentino', rate: 1 }])
const links = computed(() => props.data.links ?? { save: '/viajes/operativos/combustible-sin-precio', trips: '/viajes/operativos' })
const csrf = computed(() => props.data.csrf ?? { name: 'csrf_test_name', hash: '' })

const rows = reactive(Object.fromEntries(loads.value.map((load) => [load.id, { price: '', amount: '', currency: 'ARS', rate: '1' }])))
const bulkPrice = ref('')

const toNumber = (value) => {
  if (value === null || value === undefined || String(value).trim() === '') return null
  const parsed = Number(String(value).replace(',', '.'))
  return Number.isFinite(parsed) ? parsed : null
}
const round = (value, decimals) => Math.round(value * 10 ** decimals) / 10 ** decimals

// Precio por litro e importe se completan uno a partir del otro.
const onPrice = (load) => {
  const price = toNumber(rows[load.id].price)
  rows[load.id].amount = price === null || price <= 0 ? '' : String(round(price * load.litros, 2))
}
const onAmount = (load) => {
  const amount = toNumber(rows[load.id].amount)
  rows[load.id].price = amount === null || amount <= 0 ? '' : String(round(amount / load.litros, 4))
}
const onCurrency = (load) => {
  const row = rows[load.id]
  const currency = currencies.value.find((item) => item.code === row.currency)
  row.rate = row.currency === 'ARS' ? '1' : String(currency?.rate ?? '')
}

const applyBulkPrice = () => {
  const price = toNumber(bulkPrice.value)
  if (price === null || price <= 0) return
  for (const load of loads.value) {
    if (String(rows[load.id].amount).trim() !== '') continue
    rows[load.id].price = String(price)
    onPrice(load)
  }
}

const filled = computed(() => loads.value.filter((load) => (toNumber(rows[load.id].amount) ?? 0) > 0))
const filledArs = computed(() => filled.value.reduce((total, load) => {
  const row = rows[load.id]
  const amount = toNumber(row.amount) ?? 0
  const rate = row.currency === 'ARS' ? 1 : (toNumber(row.rate) ?? 0)
  return total + amount * rate
}, 0))

const number = (value, decimals = 2) => Number(value ?? 0).toLocaleString('es-AR', { minimumFractionDigits: 0, maximumFractionDigits: decimals })
const money = (value) => format.formatMoney(value ?? 0, { cents: true })
const dateTime = (value) => format.formatDateTime(value)
const statusLabel = { PLANIFICADO: 'Planificado', EN_CURSO: 'En curso', FINALIZADO: 'Finalizado' }
const statusBadge = {
  EN_CURSO: 'bg-success-subtle text-success-strong',
  PLANIFICADO: 'bg-info-subtle text-info-strong',
  FINALIZADO: 'bg-surface-muted text-ink-muted',
}
</script>

<template>
  <section class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
    <PageHeading
      eyebrow="Viajes · Combustible"
      title="Combustible sin precio"
      description="Cargas registradas sólo con litros. Poné el importe de las que ya tengas y guardá: lo que dejes vacío sigue pendiente para otro momento. También se completan en viajes ya finalizados."
    />

    <EmptyState
      v-if="loads.length === 0"
      title="No hay combustible sin precio"
      description="Todas las cargas de combustible tienen su importe. Si cargás una sin precio, va a aparecer acá."
      data-fuel-empty
    />

    <form v-else method="post" :action="links.save" data-fuel-prices>
      <input type="hidden" :name="csrf.name" :value="csrf.hash" />

      <div class="mb-5 grid gap-3 sm:grid-cols-3">
        <article class="rounded-card bg-surface-raised p-4 shadow-card">
          <p class="text-xs font-semibold text-ink-muted">Cargas sin precio</p>
          <p class="mt-2 text-2xl font-bold text-ink" data-summary-loads>{{ number(summary.loads, 0) }}</p>
          <p class="mt-1 text-xs text-ink-muted">en {{ number(summary.trips, 0) }} {{ summary.trips === 1 ? 'viaje' : 'viajes' }}</p>
        </article>
        <article class="rounded-card bg-surface-raised p-4 shadow-card">
          <p class="text-xs font-semibold text-ink-muted">Litros sin valorizar</p>
          <p class="mt-2 text-2xl font-bold text-ink">{{ number(summary.liters) }} L</p>
          <p class="mt-1 text-xs text-ink-muted">Cuentan para el consumo, no para el costo</p>
        </article>
        <article class="rounded-card bg-surface-raised p-4 shadow-card">
          <label class="block">
            <span class="text-xs font-semibold text-ink-muted">Mismo precio por litro para las vacías</span>
            <div class="mt-2 flex gap-2">
              <input v-model="bulkPrice" type="number" min="0.0001" step="0.0001" inputmode="decimal" placeholder="Ej. 1450" :class="fieldClass" data-bulk-price @keydown.enter.prevent="applyBulkPrice" />
              <button type="button" :class="secondaryButton" data-bulk-apply @click="applyBulkPrice">Aplicar</button>
            </div>
            <span class="mt-1 block text-xs text-ink-muted">Completa sólo las filas sin importe; después podés ajustar cada una.</span>
          </label>
        </article>
      </div>

      <PanelCard title="Cargas pendientes" :count="loads.length">
        <ul class="divide-y divide-border-subtle">
          <li v-for="load in loads" :key="load.id" class="grid gap-3 py-4 lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_minmax(0,2fr)] lg:items-center" data-fuel-row>
            <div class="min-w-0">
              <div class="flex flex-wrap items-center gap-2">
                <a :href="load.url" class="font-semibold text-primary hover:text-primary-hover">Viaje #{{ load.viaje_numero }}</a>
                <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="statusBadge[load.viaje_estado] ?? statusBadge.FINALIZADO">{{ statusLabel[load.viaje_estado] ?? load.viaje_estado }}</span>
              </div>
              <p class="mt-1 text-xs text-ink-muted">{{ load.tractor || 'Sin tractor' }}<template v-if="load.sucursal"> · {{ load.sucursal }}</template></p>
            </div>
            <div class="text-sm">
              <p class="font-semibold text-ink">{{ number(load.litros) }} L</p>
              <p class="text-xs text-ink-muted">{{ dateTime(load.fecha) }}<template v-if="load.estacion"> · {{ load.estacion }}</template><template v-if="load.comprobante"> · {{ load.comprobante }}</template></p>
            </div>
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
              <label class="block">
                <span class="mb-1 block text-xs font-semibold text-ink-muted">Precio por litro</span>
                <input v-model="rows[load.id].price" type="number" min="0.0001" step="0.0001" inputmode="decimal" :class="fieldClass" data-price @input="onPrice(load)" />
              </label>
              <label class="block">
                <span class="mb-1 block text-xs font-semibold text-ink-muted">Importe total</span>
                <input v-model="rows[load.id].amount" type="number" min="0.01" step="0.01" inputmode="decimal" :name="`importe[${load.id}]`" :class="fieldClass" data-amount @input="onAmount(load)" />
              </label>
              <label class="block">
                <span class="mb-1 block text-xs font-semibold text-ink-muted">Moneda</span>
                <select v-model="rows[load.id].currency" :name="`moneda[${load.id}]`" :class="fieldClass" @change="onCurrency(load)">
                  <option v-for="currency in currencies" :key="currency.code" :value="currency.code">{{ currency.code }}</option>
                </select>
              </label>
              <label v-if="rows[load.id].currency !== 'ARS'" class="block">
                <span class="mb-1 block text-xs font-semibold text-ink-muted">Cotización</span>
                <input v-model="rows[load.id].rate" type="number" min="0.000001" step="0.000001" :name="`cotizacion[${load.id}]`" required :class="fieldClass" />
              </label>
            </div>
          </li>
        </ul>
      </PanelCard>

      <!-- pr-24 deja libre la esquina del botón flotante del asistente, como ScrollCta. -->
      <div class="sticky bottom-0 z-10 mt-5 flex flex-col gap-3 rounded-card bg-surface-raised p-4 pr-24 shadow-card sm:flex-row sm:items-center sm:justify-between" data-fuel-save-bar>
        <p class="text-sm text-ink-muted" data-fuel-total>
          <template v-if="filled.length === 0">Todavía no completaste ningún importe.</template>
          <template v-else>{{ filled.length }} {{ filled.length === 1 ? 'carga' : 'cargas' }} con precio · {{ money(filledArs) }}</template>
        </p>
        <button type="submit" :class="primaryButton" :disabled="filled.length === 0" data-fuel-save>Guardar precios</button>
      </div>
    </form>
  </section>
</template>
