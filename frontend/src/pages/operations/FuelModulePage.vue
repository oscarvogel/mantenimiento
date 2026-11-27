<script setup>
import * as format from '../../ui/format.js'
import { computed, nextTick, reactive, ref } from 'vue'
import { BeakerIcon, HomeIcon } from '@heroicons/vue/24/outline'
import FuelReconciliationTable from '../../components/FuelReconciliationTable.vue'
import AutocompleteSelect from './components/AutocompleteSelect.vue'
import { fieldClass, nowLocal, primaryButton, secondaryButton } from './helpers.js'

const props = defineProps({
  data: { type: Object, required: true },
})

const panel = computed(() => props.data.panel ?? {})
const conciliacion = computed(() => props.data.conciliacion ?? null)
const urls = computed(() => props.data.urls ?? {})
const filtros = computed(() => props.data.filtros ?? {})
const opciones = computed(() => props.data.opciones ?? {})
const puedeEditar = computed(() => Boolean(props.data.puedeEditar))
const csrf = computed(() => props.data.csrf ?? { name: 'csrf_test_name', hash: '' })

const totales = computed(() => panel.value.totales ?? {})
const viajes = computed(() => panel.value.viajes ?? [])
const sinSalidaReal = computed(() => panel.value.sin_salida_real ?? [])
const periodo = computed(() => panel.value.periodo ?? {})
const consumo = computed(() => totales.value.consumo ?? {})

const number = new Intl.NumberFormat('es-AR', { maximumFractionDigits: 0 })
const decimal = new Intl.NumberFormat('es-AR', { minimumFractionDigits: 0, maximumFractionDigits: 2 })

const SIN_DATOS = 'Sin datos'
const vacio = (value) => value === null || value === undefined
const entero = (value, sufijo = '') => vacio(value) ? SIN_DATOS : `${number.format(Number(value))}${sufijo}`
const litros = (value) => vacio(value) ? SIN_DATOS : `${decimal.format(Number(value))} L`
const pesos = (value) => format.formatMoney(value)
const fecha = (value) => format.formatDate(value)
const fechaCorta = (value) => {
  if (!value) return ''
  const raw = String(value)
  return raw.length >= 10 ? raw.slice(0, 10) : raw
}

const estadoLabel = { PLANIFICADO: 'Planificado', EN_CURSO: 'En curso', FINALIZADO: 'Finalizado', CANCELADO: 'Cancelado' }
const estadoBadge = {
  EN_CURSO: 'bg-success-subtle text-success-strong',
  PLANIFICADO: 'bg-info-subtle text-info-strong',
  FINALIZADO: 'bg-surface-muted text-ink-muted',
  CANCELADO: 'bg-danger-subtle text-danger-strong',
}

// --- Filtros ---------------------------------------------------------------
const camionSeleccionado = ref(filtros.value.camion ? String(filtros.value.camion) : '')

const opcionesCamion = computed(() => (opciones.value.camiones ?? []).map((camion) => ({
  value: String(camion.id),
  label: camion.label,
  hint: camion.patente,
})))

// --- Pestañas --------------------------------------------------------------
const pestana = ref('combustible')

// --- Cargar combustible ----------------------------------------------------
const cargarDialog = ref(null)
const cargarViaje = ref(null)
const cargarForm = reactive({ fecha: nowLocal(), litros: '', importe: '', moneda: 'ARS', cotizacion: '1', kilometraje: '', estacion: '', comprobante: '', observaciones: '' })

const monedas = computed(() => (opciones.value.monedas ?? []).length
  ? opciones.value.monedas
  : [{ code: 'ARS', label: 'Peso argentino', rate: 1 }])

const cotizacionDe = (code) => {
  const moneda = monedas.value.find((item) => item.code === code)
  return moneda?.rate === null || moneda?.rate === undefined ? '' : String(moneda.rate)
}

const abrirCargar = (viaje) => {
  cargarViaje.value = viaje
  cargarForm.fecha = nowLocal()
  cargarForm.litros = ''
  cargarForm.importe = ''
  cargarForm.moneda = 'ARS'
  cargarForm.cotizacion = '1'
  cargarForm.kilometraje = ''
  cargarForm.estacion = ''
  cargarForm.comprobante = ''
  cargarForm.observaciones = ''
  nextTick(() => cargarDialog.value?.showModal())
}

const cambiarMonedaCarga = () => {
  cargarForm.cotizacion = cargarForm.moneda === 'ARS' ? '1' : cotizacionDe(cargarForm.moneda)
}

// --- Completar importes ----------------------------------------------------
const preciosDialog = ref(null)
const preciosViaje = ref(null)
const preciosFilas = reactive({})

const abrirPrecios = (viaje) => {
  preciosViaje.value = viaje
  for (const carga of viaje.cargas_pendientes ?? []) {
    preciosFilas[carga.id] = { precio: '', importe: '', moneda: 'ARS', cotizacion: '1' }
  }
  nextTick(() => preciosDialog.value?.showModal())
}

const toNumber = (value) => {
  if (value === null || value === undefined || String(value).trim() === '') return null
  const parsed = Number(String(value).replace(',', '.'))
  return Number.isFinite(parsed) ? parsed : null
}

const round = (value, decimals) => Math.round(value * 10 ** decimals) / 10 ** decimals

const onPrecio = (carga) => {
  const fila = preciosFilas[carga.id]
  if (!fila) return
  const precio = toNumber(fila.precio)
  fila.importe = precio === null || precio <= 0 ? '' : String(round(precio * carga.litros, 2))
}

const onImporte = (carga) => {
  const fila = preciosFilas[carga.id]
  if (!fila) return
  const importe = toNumber(fila.importe)
  fila.precio = importe === null || importe <= 0 ? '' : String(round(importe / carga.litros, 4))
}

const cambiarMonedaPrecio = (carga) => {
  const fila = preciosFilas[carga.id]
  if (!fila) return
  fila.cotizacion = fila.moneda === 'ARS' ? '1' : cotizacionDe(fila.moneda)
}

const preciosCompletados = computed(() => (preciosViaje.value?.cargas_pendientes ?? [])
  .filter((carga) => (toNumber(preciosFilas[carga.id]?.importe) ?? 0) > 0).length)

// --- Conciliación ----------------------------------------------------------
// La conciliación no filtra por camión en el servidor: cuando hay un camión
// elegido, la pestaña muestra sólo sus viajes.
const conciliacionFiltrada = computed(() => {
  const filas = conciliacion.value?.viajes ?? []
  const camion = (opciones.value.camiones ?? []).find((item) => String(item.id) === String(camionSeleccionado.value))
  if (!camion?.patente) {
    return filas
  }

  return filas.filter((viaje) => String(viaje.patente ?? '').trim().toUpperCase() === String(camion.patente).trim().toUpperCase())
})

// --- Resumen ---------------------------------------------------------------
const tarjetas = computed(() => [
  {
    label: 'Litros cargados',
    value: litros(totales.value.litros),
    hint: `${entero(totales.value.cargas)} cargas en ${entero(totales.value.viajes)} viajes`,
    testid: 'fuel-kpi-litros',
  },
  {
    label: 'Importe cargado',
    value: pesos(totales.value.importe_ars),
    hint: `${entero(totales.value.cargas_sin_precio)} cargas sin precio`,
    testid: 'fuel-kpi-importe',
  },
  {
    label: 'Consumo promedio',
    value: vacio(consumo.value.litros_por_100km) ? SIN_DATOS : `${decimal.format(Number(consumo.value.litros_por_100km))} L/100 km`,
    hint: vacio(consumo.value.litros_por_100km)
      ? 'Faltan odómetros de salida y de regreso'
      : `${entero(consumo.value.viajes_medidos)} viajes medidos · ${entero(consumo.value.km)} km`,
    testid: 'fuel-kpi-consumo',
  },
  {
    label: 'Viajes sin combustible',
    value: entero(totales.value.viajes_sin_combustible),
    hint: 'Del período, sin cargas cargadas',
    testid: 'fuel-kpi-sin-combustible',
  },
])

const hayFiltros = computed(() => Boolean(filtros.value.desde || filtros.value.hasta || filtros.value.camion))
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
          Lo que se cargó en cada viaje, cuánto consume y qué falta cargar. Todos los datos salen de la misma tabla
          de cargas del viaje: el módulo y la tabla no pueden discrepar.
        </p>
      </div>
      <a
        v-if="urls.portal"
        :href="urls.portal"
        
       :class="[secondaryButton, 'self-start sm:self-auto']">
        <HomeIcon class="size-5" aria-hidden="true" />Volver al inicio
      </a>
    </header>

    <form :action="urls.index" method="get" class="mt-6 rounded-card bg-surface-raised shadow-card" data-fuel-filters>
      <div class="grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-4 lg:items-end sm:p-5">
        <label class="block">
          <span class="mb-1.5 block text-sm font-semibold text-ink">Desde</span>
          <input type="date" name="desde" :value="filtros.desde ?? ''" :class="fieldClass" data-fuel-desde />
        </label>
        <label class="block">
          <span class="mb-1.5 block text-sm font-semibold text-ink">Hasta</span>
          <input type="date" name="hasta" :value="filtros.hasta ?? ''" :class="fieldClass" data-fuel-hasta />
        </label>
        <label class="block">
          <span class="mb-1.5 block text-sm font-semibold text-ink">Camión</span>
          <AutocompleteSelect
            v-model="camionSeleccionado"
            name="camion"
            :options="opcionesCamion"
            placeholder="Todos los camiones"
            empty-label="No hay camiones con ese nombre"
            data-fuel-camion
          />
        </label>
        <div class="flex flex-wrap gap-2">
          <button type="submit" :class="primaryButton" data-fuel-aplicar>Aplicar</button>
          <a v-if="hayFiltros" :href="urls.index" :class="secondaryButton" data-fuel-limpiar>Limpiar</a>
        </div>
      </div>
      <p class="border-t border-border-subtle px-4 py-2 text-xs text-ink-muted sm:px-5" data-fuel-periodo>
        Período: {{ fechaCorta(periodo.desde) }} a {{ fechaCorta(periodo.hasta) }}. Entra un viaje que empezó antes del
        final del período y terminó después del inicio, o que sigue en curso.
      </p>
    </form>

    <section aria-labelledby="fuel-module-summary" class="mt-6">
      <h2 id="fuel-module-summary" class="sr-only">Resumen</h2>
      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <article v-for="card in tarjetas" :key="card.label" class="rounded-card bg-surface-raised p-4 shadow-card" :data-testid="card.testid">
          <p class="text-xs font-bold uppercase tracking-wide text-ink-muted">{{ card.label }}</p>
          <p class="mt-1 text-2xl font-bold tabular-nums text-ink">{{ card.value }}</p>
          <p class="mt-1 text-xs leading-5 text-ink-muted">{{ card.hint }}</p>
        </article>
      </div>
    </section>

    <div v-if="conciliacion" class="ui-tabs mt-6" role="tablist" aria-label="Vistas del módulo de combustible">
      <button
        type="button"
        role="tab"
        :aria-selected="pestana === 'combustible'"
        class="min-h-11 rounded-t-lg px-4 py-2 text-sm font-semibold"
        :class="pestana === 'combustible' ? 'border-b-2 border-primary text-primary' : 'text-ink-muted hover:text-ink'"
        data-fuel-tab-combustible
        @click="pestana = 'combustible'"
      >
        Combustible
      </button>
      <button
        type="button"
        role="tab"
        :aria-selected="pestana === 'conciliacion'"
        class="min-h-11 rounded-t-lg px-4 py-2 text-sm font-semibold"
        :class="pestana === 'conciliacion' ? 'border-b-2 border-primary text-primary' : 'text-ink-muted hover:text-ink'"
        data-fuel-tab-conciliacion
        @click="pestana = 'conciliacion'"
      >
        Conciliación con el sensor
      </button>
    </div>

    <section v-show="pestana === 'combustible'" class="mt-6 overflow-hidden rounded-card bg-surface-raised shadow-card" aria-labelledby="fuel-module-trips">
      <div class="border-b border-border px-5 py-4 sm:px-6">
        <h2 id="fuel-module-trips" class="text-lg font-bold text-ink">Combustible por viaje</h2>
        <p class="mt-1 text-sm leading-6 text-ink-muted">
          Las cargas se registran en el viaje. Cuando falta un dato se dice cuál: nunca un cero que parezca medido.
        </p>
      </div>
      <div v-if="viajes.length" class="overflow-x-auto">
        <table class="ui-table min-w-full divide-y divide-border text-left text-sm">
          <thead class="bg-surface-muted text-xs font-semibold uppercase tracking-wide text-ink-muted">
            <tr>
              <th scope="col" class="px-4 py-3">Fecha</th>
              <th scope="col" class="px-4 py-3">Camión</th>
              <th scope="col" class="px-4 py-3">Viaje</th>
              <th scope="col" class="px-4 py-3 text-right">Litros</th>
              <th scope="col" class="px-4 py-3 text-right">Importe</th>
              <th scope="col" class="px-4 py-3 text-right">Km</th>
              <th scope="col" class="px-4 py-3 text-right">Consumo</th>
              <th scope="col" class="px-4 py-3">Pendiente</th>
              <th scope="col" class="px-4 py-3 text-right">Acciones</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border-subtle">
            <tr v-for="viaje in viajes" :key="viaje.id" class="align-top hover:bg-surface-subtle" data-fuel-row>
              <td class="whitespace-nowrap px-4 py-3 text-ink">{{ fecha(viaje.fecha_salida) }}</td>
              <td class="whitespace-nowrap px-4 py-3 text-ink">
                {{ viaje.patente || 'Sin tractor' }}
                <span v-if="viaje.sucursal" class="mt-1 block text-xs text-ink-muted">{{ viaje.sucursal }}</span>
              </td>
              <th scope="row" class="whitespace-nowrap px-4 py-3 font-semibold text-ink">
                <a :href="viaje.url_viaje" class="text-primary hover:text-primary-hover">Nº {{ viaje.numero }}</a>
                <span class="mt-1 block text-xs font-normal">
                  <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="estadoBadge[viaje.estado] ?? estadoBadge.FINALIZADO">{{ estadoLabel[viaje.estado] ?? viaje.estado }}</span>
                </span>
              </th>
              <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-ink">{{ litros(viaje.litros) }}</td>
              <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-ink">{{ pesos(viaje.importe_ars) }}</td>
              <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-ink-muted">{{ vacio(viaje.km) ? SIN_DATOS : entero(viaje.km, ' km') }}</td>
              <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-ink">{{ vacio(viaje.consumo_l100) ? SIN_DATOS : `${decimal.format(Number(viaje.consumo_l100))} L/100` }}</td>
              <td class="min-w-56 px-4 py-3 text-xs leading-5 text-ink-muted">{{ viaje.motivo ?? '' }}</td>
              <td class="whitespace-nowrap px-4 py-3 text-right">
                <div class="flex flex-wrap justify-end gap-2">
                  <button
                    v-if="puedeEditar && viaje.puede_cargar"
                    type="button"
                    :class="secondaryButton"
                    :data-testid="`fuel-cargar-${viaje.numero}`"
                    @click="abrirCargar(viaje)"
                  >
                    Cargar
                  </button>
                  <button
                    v-if="puedeEditar && viaje.puede_completar_precio"
                    type="button"
                    :class="secondaryButton"
                    :data-testid="`fuel-precios-${viaje.numero}`"
                    @click="abrirPrecios(viaje)"
                  >
                    Completar importe
                  </button>
                  <a :href="viaje.url_viaje" :class="secondaryButton">Ver viaje</a>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-else role="status" class="px-5 py-10 text-center text-sm leading-6 text-ink-muted" data-fuel-empty>
        No hay viajes en el período seleccionado.
      </p>
    </section>

    <section v-show="pestana === 'conciliacion'" class="mt-6">
      <h2 class="text-lg font-bold text-ink">Conciliación con el sensor del tanque</h2>
      <p class="mt-1 text-sm leading-6 text-ink-muted">
        Se compara por viaje, porque las cargas se declaran todas al volver. Sin veredicto cuando la telemetría no vio el
        viaje completo: un corte del GPS no se imputa al chofer.
      </p>
      <div v-if="conciliacion" class="mt-4">
        <FuelReconciliationTable :viajes="conciliacionFiltrada" />
      </div>
    </section>

    <section v-if="sinSalidaReal.length" class="mt-6 overflow-hidden rounded-card bg-surface-raised shadow-card" aria-labelledby="fuel-module-without-departure">
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

    <section class="mt-6 rounded-card bg-surface-raised p-5 sm:p-6 shadow-card" aria-labelledby="fuel-module-pending">
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
        
       :class="[secondaryButton, 'mt-3']">
        Ver todas las cargas sin precio
      </a>
    </section>

    <!-- Cargar una carga de combustible en un viaje -->
    <dialog v-if="puedeEditar" ref="cargarDialog" aria-labelledby="fuel-cargar-title" @click.self="cargarDialog?.close()" class="m-auto w-[min(44rem,calc(100vw-2rem))] max-h-[calc(100vh-2rem)] overflow-y-auto rounded-card bg-surface-raised p-0 text-ink shadow-xl backdrop:bg-black/60">
      <div v-if="cargarViaje" class="p-5 sm:p-6">
        <div class="mb-5 flex items-start justify-between gap-4 border-b border-border-subtle pb-4">
          <div>
            <h2 id="fuel-cargar-title" class="text-lg font-bold text-ink">Cargar combustible</h2>
            <p class="mt-1 text-sm text-ink-muted">Viaje Nº {{ cargarViaje.numero }} · {{ cargarViaje.patente || 'Sin tractor' }}</p>
          </div>
          <button type="button" aria-label="Cerrar" @click="cargarDialog?.close()" class="grid size-9 shrink-0 place-items-center rounded-lg text-ink-muted hover:bg-surface-subtle hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary">×</button>
        </div>
        <form method="post" :action="cargarViaje.url_cargar" data-fuel-cargar-form>
          <input type="hidden" :name="csrf.name" :value="csrf.hash" />
          <input type="hidden" name="desde" :value="filtros.desde ?? ''" />
          <input type="hidden" name="hasta" :value="filtros.hasta ?? ''" />
          <input type="hidden" name="camion" :value="filtros.camion ?? ''" />
          <div class="grid gap-4 sm:grid-cols-2">
            <label class="block">
              <span class="mb-1.5 block text-sm font-semibold text-ink">Fecha de la carga</span>
              <input v-model="cargarForm.fecha" type="datetime-local" name="fecha" :class="fieldClass" required />
            </label>
            <label class="block">
              <span class="mb-1.5 block text-sm font-semibold text-ink">Litros</span>
              <input v-model="cargarForm.litros" type="number" min="0.01" step="0.01" inputmode="decimal" name="litros" :class="fieldClass" required />
            </label>
            <label class="block">
              <span class="mb-1.5 block text-sm font-semibold text-ink">Importe (opcional)</span>
              <input v-model="cargarForm.importe" type="number" min="0.01" step="0.01" inputmode="decimal" name="importe" :class="fieldClass" />
              <span class="mt-1.5 block text-xs text-ink-muted">Si lo dejás vacío, la carga queda sin precio y la completás después.</span>
            </label>
            <label class="block">
              <span class="mb-1.5 block text-sm font-semibold text-ink">Moneda</span>
              <select v-model="cargarForm.moneda" name="moneda" :class="fieldClass" required @change="cambiarMonedaCarga">
                <option v-for="moneda in monedas" :key="moneda.code" :value="moneda.code">{{ moneda.code }} · {{ moneda.label }}</option>
              </select>
            </label>
            <label v-if="cargarForm.moneda !== 'ARS'" class="block">
              <span class="mb-1.5 block text-sm font-semibold text-ink">Cotización a ARS</span>
              <input v-model="cargarForm.cotizacion" type="number" min="0.000001" step="0.000001" name="cotizacion" :class="fieldClass" required />
              <span class="mt-1.5 block text-xs text-ink-muted">Cuántos ARS vale 1 {{ cargarForm.moneda }}.</span>
            </label>
            <label class="block">
              <span class="mb-1.5 block text-sm font-semibold text-ink">Kilometraje (opcional)</span>
              <input v-model="cargarForm.kilometraje" type="number" min="0" step="1" name="kilometraje" :class="fieldClass" />
            </label>
            <label class="block">
              <span class="mb-1.5 block text-sm font-semibold text-ink">Estación (opcional)</span>
              <input v-model="cargarForm.estacion" type="text" maxlength="120" name="estacion" :class="fieldClass" />
            </label>
            <label class="block">
              <span class="mb-1.5 block text-sm font-semibold text-ink">Comprobante (opcional)</span>
              <input v-model="cargarForm.comprobante" type="text" maxlength="80" name="comprobante" :class="fieldClass" />
            </label>
            <label class="block sm:col-span-2">
              <span class="mb-1.5 block text-sm font-semibold text-ink">Observaciones (opcional)</span>
              <input v-model="cargarForm.observaciones" type="text" maxlength="500" name="observaciones" :class="fieldClass" />
            </label>
          </div>
          <div class="mt-5 flex flex-wrap justify-end gap-2 border-t border-border-subtle pt-4">
            <button type="button" @click="cargarDialog?.close()" :class="secondaryButton">Cancelar</button>
            <button type="submit" :class="primaryButton" data-fuel-cargar-submit>Guardar carga</button>
          </div>
        </form>
      </div>
    </dialog>

    <!-- Completar los importes de las cargas que esperan su precio -->
    <dialog v-if="puedeEditar" ref="preciosDialog" aria-labelledby="fuel-precios-title" @click.self="preciosDialog?.close()" class="m-auto w-[min(52rem,calc(100vw-2rem))] max-h-[calc(100vh-2rem)] overflow-y-auto rounded-card bg-surface-raised p-0 text-ink shadow-xl backdrop:bg-black/60">
      <div v-if="preciosViaje" class="p-5 sm:p-6">
        <div class="mb-5 flex items-start justify-between gap-4 border-b border-border-subtle pb-4">
          <div>
            <h2 id="fuel-precios-title" class="text-lg font-bold text-ink">Completar importe</h2>
            <p class="mt-1 text-sm text-ink-muted">
              Viaje Nº {{ preciosViaje.numero }} · {{ preciosViaje.cargas_pendientes.length }}
              {{ preciosViaje.cargas_pendientes.length === 1 ? 'carga sin precio' : 'cargas sin precio' }}
            </p>
          </div>
          <button type="button" aria-label="Cerrar" @click="preciosDialog?.close()" class="grid size-9 shrink-0 place-items-center rounded-lg text-ink-muted hover:bg-surface-subtle hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary">×</button>
        </div>
        <form method="post" :action="urls.precios" data-fuel-precios-form>
          <input type="hidden" :name="csrf.name" :value="csrf.hash" />
          <input type="hidden" name="desde" :value="filtros.desde ?? ''" />
          <input type="hidden" name="hasta" :value="filtros.hasta ?? ''" />
          <input type="hidden" name="camion" :value="filtros.camion ?? ''" />
          <ul class="divide-y divide-border-subtle">
            <li v-for="carga in preciosViaje.cargas_pendientes" :key="carga.id" class="grid gap-3 py-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)] lg:items-center">
              <div class="min-w-0">
                <p class="font-semibold text-ink">{{ decimal.format(Number(carga.litros)) }} L</p>
                <p class="text-xs text-ink-muted">{{ fecha(carga.fecha) }}<template v-if="carga.estacion"> · {{ carga.estacion }}</template><template v-if="carga.comprobante"> · {{ carga.comprobante }}</template></p>
              </div>
              <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                <label class="block">
                  <span class="mb-1 block text-xs font-semibold text-ink-muted">Precio por litro</span>
                  <input v-model="preciosFilas[carga.id].precio" type="number" min="0.0001" step="0.0001" inputmode="decimal" :class="fieldClass" @input="onPrecio(carga)" />
                </label>
                <label class="block">
                  <span class="mb-1 block text-xs font-semibold text-ink-muted">Importe total</span>
                  <input v-model="preciosFilas[carga.id].importe" type="number" min="0.01" step="0.01" inputmode="decimal" :name="`importe[${carga.id}]`" :class="fieldClass" @input="onImporte(carga)" />
                </label>
                <label class="block">
                  <span class="mb-1 block text-xs font-semibold text-ink-muted">Moneda</span>
                  <select v-model="preciosFilas[carga.id].moneda" :name="`moneda[${carga.id}]`" :class="fieldClass" @change="cambiarMonedaPrecio(carga)">
                    <option v-for="moneda in monedas" :key="moneda.code" :value="moneda.code">{{ moneda.code }}</option>
                  </select>
                </label>
                <label v-if="preciosFilas[carga.id].moneda !== 'ARS'" class="block">
                  <span class="mb-1 block text-xs font-semibold text-ink-muted">Cotización</span>
                  <input v-model="preciosFilas[carga.id].cotizacion" type="number" min="0.000001" step="0.000001" :name="`cotizacion[${carga.id}]`" required :class="fieldClass" />
                </label>
              </div>
            </li>
          </ul>
          <div class="mt-5 flex flex-wrap items-center justify-between gap-2 border-t border-border-subtle pt-4">
            <p class="text-sm text-ink-muted" data-fuel-precios-resumen>{{ preciosCompletados }} importes listos para guardar</p>
            <div class="flex gap-2">
              <button type="button" @click="preciosDialog?.close()" :class="secondaryButton">Cancelar</button>
              <button type="submit" :class="primaryButton" :disabled="preciosCompletados === 0" data-fuel-precios-submit>Guardar importes</button>
            </div>
          </div>
        </form>
      </div>
    </dialog>
  </div>
</template>
