<script setup>
import { computed } from 'vue'
import { BeakerIcon, HomeIcon } from '@heroicons/vue/24/outline'
import FuelReconciliationTable from '../../components/FuelReconciliationTable.vue'

const props = defineProps({
  data: { type: Object, required: true },
})

const panel = computed(() => props.data.panel ?? {})
const conciliacion = computed(() => props.data.conciliacion ?? null)
const urls = computed(() => props.data.urls ?? {})

const totales = computed(() => panel.value.totales ?? {})
const viajes = computed(() => panel.value.viajes ?? [])
const sinSalidaReal = computed(() => panel.value.sin_salida_real ?? [])
const periodo = computed(() => panel.value.periodo ?? {})

const number = new Intl.NumberFormat('es-AR', { maximumFractionDigits: 0 })
const decimal = new Intl.NumberFormat('es-AR', { maximumFractionDigits: 2 })
const money = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS', maximumFractionDigits: 0 })
const date = new Intl.DateTimeFormat('es-AR', { day: '2-digit', month: '2-digit', year: '2-digit' })

const SIN_DATOS = 'Sin datos'
const vacio = (value) => value === null || value === undefined
const entero = (value, sufijo = '') => vacio(value) ? SIN_DATOS : `${number.format(Number(value))}${sufijo}`
const litros = (value) => vacio(value) ? SIN_DATOS : `${decimal.format(Number(value))} L`
const pesos = (value) => vacio(value) ? SIN_DATOS : money.format(Number(value))
const fecha = (value) => {
  if (!value) return SIN_DATOS
  const parsed = new Date(String(value).replace(' ', 'T'))
  return Number.isNaN(parsed.getTime()) ? SIN_DATOS : date.format(parsed)
}

const tarjetas = computed(() => [
  { label: 'Viajes', value: entero(totales.value.viajes), hint: 'En curso o finalizados en el período' },
  { label: 'Cargas', value: entero(totales.value.cargas), hint: `${litros(totales.value.litros)} declarados` },
  { label: 'Importe declarado', value: pesos(totales.value.importe_ars), hint: 'Solo las cargas con precio cargado' },
  { label: 'Sin precio', value: entero(totales.value.cargas_sin_precio), hint: `${litros(totales.value.litros_sin_precio)} sin valorar` },
  { label: 'Sin combustible', value: entero(totales.value.viajes_sin_combustible), hint: 'Viajes del período sin cargas cargadas' },
])

const diasDisponibles = [7, 15, 30, 90]
const diasActual = computed(() => Number(periodo.value.dias ?? 30))
</script>

<template>
  <div>
    <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <p class="flex items-center gap-2 text-sm font-semibold text-primary">
          <BeakerIcon class="size-5" aria-hidden="true" />Combustible
        </p>
        <h1 class="mt-1 text-2xl font-bold tracking-tight text-ink sm:text-3xl">Combustible</h1>
        <p class="mt-2 max-w-3xl text-sm leading-6 text-ink-muted">
          Lo que declaró cada viaje, lo que falta completar y la conciliación contra el sensor del tanque. Todo sale de la
          misma tabla de cargas del viaje: el módulo y la tabla no pueden discrepar.
        </p>
      </div>
      <a
        v-if="urls.portal"
        :href="urls.portal"
        class="inline-flex min-h-11 items-center justify-center gap-2 self-start rounded-lg border border-border-strong bg-surface-raised px-4 py-2.5 text-sm font-semibold text-ink hover:bg-surface-muted sm:self-auto"
      >
        <HomeIcon class="size-5" aria-hidden="true" />Volver al inicio
      </a>
    </header>

    <details class="group mt-6 rounded-xl border border-border bg-surface-raised">
      <summary class="flex min-h-14 cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 marker:hidden sm:px-5">
        <span class="min-w-0">
          <span class="block text-sm font-semibold text-ink">Período</span>
          <span class="block truncate text-xs text-ink-muted">Últimos {{ diasActual }} días</span>
        </span>
        <span class="text-sm font-semibold text-primary group-open:hidden">Editar</span>
        <span class="hidden text-sm font-semibold text-primary group-open:inline">Cerrar</span>
      </summary>
      <form :action="urls.index" method="get" class="grid gap-4 border-t border-border-subtle p-4 sm:grid-cols-[minmax(9rem,0.6fr)_auto] sm:items-end">
        <label class="grid gap-1.5 text-sm font-semibold text-ink">
          Período
          <select name="dias" class="min-h-11 rounded-lg border border-border-strong bg-surface-raised px-3 text-sm font-normal text-ink">
            <option v-for="dias in diasDisponibles" :key="dias" :value="dias" :selected="diasActual === dias">
              Últimos {{ dias }} días
            </option>
          </select>
        </label>
        <button type="submit" class="min-h-11 rounded-lg bg-primary px-5 py-2.5 text-sm font-semibold text-primary-foreground hover:bg-primary-hover">
          Aplicar
        </button>
      </form>
    </details>

    <section aria-labelledby="fuel-module-summary" class="mt-6">
      <h2 id="fuel-module-summary" class="sr-only">Resumen</h2>
      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-5">
        <article v-for="card in tarjetas" :key="card.label" class="rounded-xl border border-border bg-surface-raised p-4">
          <p class="text-xs font-bold uppercase tracking-wide text-ink-muted">{{ card.label }}</p>
          <p class="mt-1 text-2xl font-bold tabular-nums text-ink">{{ card.value }}</p>
          <p class="mt-1 text-xs leading-5 text-ink-muted">{{ card.hint }}</p>
        </article>
      </div>
    </section>

    <section class="mt-6 overflow-hidden rounded-xl border border-border bg-surface-raised" aria-labelledby="fuel-module-trips">
      <div class="border-b border-border px-5 py-4 sm:px-6">
        <h2 id="fuel-module-trips" class="text-lg font-bold text-ink">Combustible declarado por viaje</h2>
        <p class="mt-1 text-sm leading-6 text-ink-muted">
          Las cargas se registran en el viaje. Cuando falta un dato se dice cuál: nunca un cero que parezca medido.
        </p>
      </div>
      <div v-if="viajes.length" class="overflow-x-auto">
        <table class="min-w-full divide-y divide-border text-left text-sm">
          <thead class="bg-surface-muted text-xs font-semibold uppercase tracking-wide text-ink-muted">
            <tr>
              <th scope="col" class="px-4 py-3">Viaje</th>
              <th scope="col" class="px-4 py-3">Estado</th>
              <th scope="col" class="px-4 py-3 text-right">Litros</th>
              <th scope="col" class="px-4 py-3 text-right">Cargas</th>
              <th scope="col" class="px-4 py-3 text-right">Importe</th>
              <th scope="col" class="px-4 py-3 text-right">Km</th>
              <th scope="col" class="px-4 py-3">Pendiente</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border-subtle">
            <tr v-for="viaje in viajes" :key="viaje.numero" class="align-top hover:bg-surface-subtle">
              <th scope="row" class="whitespace-nowrap px-4 py-3 font-semibold text-ink">
                Nº {{ viaje.numero }} · {{ viaje.patente ?? 'Sin tractor' }}
                <span class="block text-xs font-normal text-ink-muted">
                  {{ fecha(viaje.fecha_salida) }}<template v-if="viaje.sucursal"> · {{ viaje.sucursal }}</template>
                </span>
              </th>
              <td class="whitespace-nowrap px-4 py-3 text-ink">
                {{ viaje.estado === 'FINALIZADO' ? 'Finalizado' : 'En curso' }}
                <span class="mt-1 block text-xs text-ink-muted">{{ viaje.cargas_sin_precio > 0 ? `${entero(viaje.cargas_sin_precio)} cargas sin precio` : '' }}</span>
              </td>
              <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-ink">{{ litros(viaje.litros) }}</td>
              <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-ink">{{ entero(viaje.cargas) }}</td>
              <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-ink">{{ pesos(viaje.importe_ars) }}</td>
              <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-ink-muted">{{ vacio(viaje.km) ? SIN_DATOS : entero(viaje.km, ' km') }}</td>
              <td class="min-w-56 px-4 py-3 text-xs leading-5 text-ink-muted">{{ viaje.motivo ?? '' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-else role="status" class="px-5 py-10 text-center text-sm leading-6 text-ink-muted">
        No hay viajes en curso ni finalizados en el período seleccionado.
      </p>
    </section>

    <section v-if="sinSalidaReal.length" class="mt-6 overflow-hidden rounded-xl border border-border bg-surface-raised" aria-labelledby="fuel-module-without-departure">
      <div class="border-b border-border px-5 py-4 sm:px-6">
        <h2 id="fuel-module-without-departure" class="text-lg font-bold text-ink">Viajes sin salida real cargada</h2>
        <p class="mt-1 text-sm leading-6 text-ink-muted">
          Sin la salida real no hay período ni odómetro: hasta que se cargue, el viaje no entra en la conciliación.
        </p>
      </div>
      <ul class="divide-y divide-border-subtle text-sm">
        <li v-for="viaje in sinSalidaReal" :key="viaje.numero" class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 sm:px-6">
          <span class="font-semibold text-ink">Nº {{ viaje.numero }}</span>
          <span class="text-ink-muted">{{ viaje.motivo }}</span>
        </li>
      </ul>
    </section>

    <section class="mt-6 rounded-xl border border-border bg-surface-raised p-5 sm:p-6" aria-labelledby="fuel-module-pending">
      <h2 id="fuel-module-pending" class="text-lg font-bold text-ink">Cargas sin precio</h2>
      <p class="mt-1 text-sm leading-6 text-ink-muted">
        <template v-if="totales.cargas_sin_precio > 0">
          Hay {{ entero(totales.cargas_sin_precio) }} cargas ({{ litros(totales.litros_sin_precio) }}) sin precio cargado:
          el importe todavía no entra en el margen del viaje.
        </template>
        <template v-else>
          Todas las cargas del período tienen su precio cargado.
        </template>
      </p>
      <a
        v-if="urls.pending"
        :href="urls.pending"
        class="mt-3 inline-flex min-h-11 items-center justify-center rounded-lg border border-border-strong bg-surface-raised px-4 py-2.5 text-sm font-semibold text-ink hover:bg-surface-muted"
      >
        Completar los precios pendientes
      </a>
    </section>

    <template v-if="conciliacion">
      <section aria-labelledby="fuel-module-reconciliation" class="mt-6">
        <h2 id="fuel-module-reconciliation" class="text-lg font-bold text-ink">Conciliación con el sensor del tanque</h2>
        <p class="mt-1 text-sm leading-6 text-ink-muted">
          Se compara por viaje, porque las cargas se declaran todas al volver. Sin veredicto cuando la telemetría no vio el
          viaje completo: un corte del GPS no se imputa al chofer.
        </p>
      </section>
      <FuelReconciliationTable :viajes="conciliacion.viajes ?? []" />
    </template>
    <p v-else class="mt-6 rounded-xl border border-border bg-surface-raised p-5 text-sm leading-6 text-ink-muted">
      La comparación con el sensor del tanque es un dato gerencial: tu cuenta no tiene ese acceso.
    </p>

    <p class="mt-4 text-xs leading-5 text-ink-muted">
      «Sin datos» significa que falta información mínima para el indicador. Esta pantalla es de solo lectura.
    </p>
  </div>
</template>
