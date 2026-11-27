<script setup>
import { primaryButton, secondaryButton } from '../../ui/buttons.js'
import { computed } from 'vue'
import { ExclamationTriangleIcon, HomeIcon, TruckIcon } from '@heroicons/vue/24/outline'
import FleetRankingChart from './charts/FleetRankingChart.vue'
import FleetCutsTimeline from './charts/FleetCutsTimeline.vue'
import ServiceKmChart from './charts/ServiceKmChart.vue'
import ConciliationShareBar from './charts/ConciliationShareBar.vue'
import FuelReconciliationTable from '../../components/FuelReconciliationTable.vue'

const props = defineProps({
  data: { type: Object, required: true },
})

const tablero = computed(() => props.data.tablero ?? {})
const urls = computed(() => props.data.urls ?? {})
const vista = computed(() => tablero.value.vista ?? 'uso')
const filtros = computed(() => tablero.value.filtros ?? {})
const opciones = computed(() => tablero.value.opciones ?? { vistas: ['uso', 'combustible', 'services'], dias: [7, 15, 30] })
const branches = computed(() => tablero.value.branches ?? [])
const resultado = computed(() => tablero.value.resultado ?? {})
const totales = computed(() => resultado.value.totales ?? {})

const number = new Intl.NumberFormat('es-AR', { maximumFractionDigits: 0 })
const decimal = new Intl.NumberFormat('es-AR', { maximumFractionDigits: 1 })
const dateTime = new Intl.DateTimeFormat('es-AR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })

const SIN_DATOS = 'Sin datos'
const vacio = (value) => value === null || value === undefined
const plural = (cantidad, singular, varios) => (Number(cantidad) === 1 ? singular : varios)
/** Km que faltan para el service; negativo es un service ya pasado, no "faltan -X". */
const restantes = (value) => {
  if (vacio(value)) return SIN_DATOS
  return Number(value) < 0 ? `Pasado por ${number.format(Math.abs(Number(value)))} km` : `Faltan ${number.format(Number(value))} km`
}
const entero = (value, sufijo = '') => vacio(value) ? SIN_DATOS : `${number.format(Number(value))}${sufijo}`
const unDecimal = (value, sufijo = '') => vacio(value) ? SIN_DATOS : `${decimal.format(Number(value))}${sufijo}`
const conSigno = (value, sufijo = '') => vacio(value) ? SIN_DATOS : `${Number(value) > 0 ? '+' : ''}${decimal.format(Number(value))}${sufijo}`
const fecha = (value) => {
  if (!value) return SIN_DATOS
  const parsed = new Date(String(value).replace(' ', 'T'))
  return Number.isNaN(parsed.getTime()) ? SIN_DATOS : dateTime.format(parsed)
}

const etiquetasVista = { uso: 'Uso y excesos', combustible: 'Combustible', services: 'Services por GPS' }
const urlDe = (cambios) => {
  const params = new URLSearchParams()
  const valores = { vista: vista.value, dias: filtros.value.dias, sucursal_id: filtros.value.sucursalId, ...cambios }
  Object.entries(valores).forEach(([clave, valor]) => {
    if (!vacio(valor) && valor !== '') params.set(clave, String(valor))
  })
  const query = params.toString()
  return `${urls.value.index ?? ''}${query ? `?${query}` : ''}`
}
const pestanas = computed(() => opciones.value.vistas.map((clave) => ({
  clave,
  etiqueta: etiquetasVista[clave] ?? clave,
  href: urlDe({ vista: clave }),
  activa: clave === vista.value,
})))
const usaPeriodo = computed(() => vista.value !== 'services')
const sucursalLabel = computed(() => {
  const branch = branches.value.find((item) => Number(item.id) === Number(filtros.value.sucursalId))
  return branch?.name ?? 'Todas las autorizadas'
})
const resumenFiltros = computed(() => usaPeriodo.value
  ? `${sucursalLabel.value} · últimos ${filtros.value.dias ?? 30} días`
  : `${sucursalLabel.value} · a hoy`)
const equipoUrl = (id) => (urls.value.equipment && id ? `${urls.value.equipment.replace(/\/$/, '')}/${id}` : null)

const tono = {
  ok: 'bg-success-subtle text-success-strong',
  aviso: 'bg-warning-subtle text-warning-strong',
  peligro: 'bg-danger-subtle text-danger-strong',
  info: 'bg-info-subtle text-info-strong',
  neutro: 'bg-surface-muted text-ink-muted',
}
const senal = {
  OK: { etiqueta: 'Reporta', tono: tono.ok },
  SIN_REPORTAR: { etiqueta: 'Sin reportar', tono: tono.peligro },
  SIN_DATOS: { etiqueta: 'Sin datos', tono: tono.neutro },
  CORTE_INTEGRACION: { etiqueta: 'Corte del proveedor', tono: tono.aviso },
}
const plan = {
  AL_DIA: { etiqueta: 'Al día', tono: tono.ok },
  PROXIMO: { etiqueta: 'Próximo', tono: tono.aviso },
  VENCIDO: { etiqueta: 'Vencido', tono: tono.peligro },
  SIN_DATOS: { etiqueta: 'Sin datos', tono: tono.neutro },
}

const unidades = computed(() => resultado.value.unidades ?? [])
const excesos = computed(() => resultado.value.excesos ?? [])
const cortes = computed(() => resultado.value.cortes ?? [])
const viajes = computed(() => resultado.value.viajes ?? [])
const planes = computed(() => resultado.value.planes ?? [])

const periodo = computed(() => resultado.value.periodo ?? {})
const serie = (campo) => unidades.value.map((unidad) => ({ key: unidad.equipoId, label: unidad.patente, value: unidad[campo] ?? null }))
const kmPorUnidad = computed(() => serie('km'))
const motorDetenido = computed(() => serie('porcentajeMotorDetenido'))
const excesosPorUnidad = computed(() => unidades.value
  .filter((unidad) => Number(unidad.excesos) > 0)
  .map((unidad) => ({ key: unidad.equipoId, label: unidad.patente, value: unidad.excesos })))
const kmTexto = (value) => `${number.format(Number(value))} km`

const tarjetas = computed(() => {
  const t = totales.value
  if (vista.value === 'combustible') {
    return [
      { label: 'Viajes', value: entero(t.viajes), hint: 'En curso o finalizados en el período' },
      { label: 'Con veredicto', value: entero(t.conVeredicto), hint: 'La telemetría vio el viaje completo' },
      { label: 'Litros declarados', value: entero(t.litrosDeclarados, ' L'), hint: 'Solo viajes con veredicto' },
      { label: 'Litros detectados', value: entero(t.litrosDetectados, ' L'), hint: 'Cargas vistas por el sensor' },
      { label: 'Diferencia', value: conSigno(t.diferenciaPorcentaje, '%'), hint: vacio(t.diferenciaLitros) ? `Tolerancia ${unDecimal(resultado.value.tolerancia, '%')}` : `${conSigno(t.diferenciaLitros, ' L')} · tolerancia ${unDecimal(resultado.value.tolerancia, '%')}` },
    ]
  }
  if (vista.value === 'services') {
    const porEstado = t.porEstadoEstimado ?? {}
    return [
      { label: 'Planes por km', value: entero(t.planes), hint: 'Activos, con criterio de kilometraje' },
      { label: 'Con estimación', value: entero(t.conEstimacion), hint: 'Con lectura vigente y GPS cerca' },
      { label: 'Se adelantan', value: entero(t.seAdelantan), hint: 'El GPS empeora el estado real' },
      { label: 'Vencidos estimados', value: entero(porEstado.VENCIDO), hint: `${entero(porEstado.PROXIMO)} próximos` },
    ]
  }
  return [
    { label: 'Unidades', value: entero(t.unidades), hint: 'Con telemetría vinculada' },
    { label: 'Km recorridos', value: entero(t.km, ' km'), hint: `${entero(t.unidadesConKm)} unidades con odómetro` },
    { label: 'Horas de motor', value: unDecimal(t.horasMotor, ' h'), hint: `${entero(t.unidadesConHorasMotor)} unidades con horómetro` },
    { label: 'Excesos de velocidad', value: entero(t.excesos), hint: `Más de ${entero(resultado.value.umbrales?.velocidadExcesoKmh)} km/h en dos muestras` },
    { label: 'Sin reportar', value: entero(t.sinReportar), hint: `Más de ${entero(resultado.value.umbrales?.sinReportarHoras)} h sin datos` },
    { label: 'Cortes del proveedor', value: entero(t.cortes), hint: `${entero(t.cortesAbiertos)} ${plural(t.cortesAbiertos, 'sigue abierto', 'siguen abiertos')}` },
  ]
})
</script>

<template>
  <div>
    <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <p class="flex items-center gap-2 text-sm font-semibold text-primary">
          <TruckIcon class="size-5" aria-hidden="true" />Gerencial · Flota
        </p>
        <h1 class="mt-1 text-2xl font-bold tracking-tight text-ink sm:text-3xl">Tablero de flota</h1>
        <p class="mt-2 max-w-3xl text-sm leading-6 text-ink-muted">
          Uso, combustible y services de cada camión según su telemetría. Cuando faltan datos para un indicador se
          muestra <strong class="font-semibold text-ink">«Sin datos»</strong> con el motivo: nunca un cero que parezca medido.
        </p>
      </div>
      <a
        v-if="urls.portal"
        :href="urls.portal"
        
       :class="[secondaryButton, 'self-start sm:self-auto']">
        <HomeIcon class="size-5" aria-hidden="true" />Volver al inicio
      </a>
    </header>

    <nav class="ui-tabs mt-6" aria-label="Secciones del tablero">
      <a
        v-for="pestana in pestanas"
        :key="pestana.clave"
        :href="pestana.href"
        :aria-current="pestana.activa ? 'page' : undefined"
        class="-mb-px flex min-h-11 items-center justify-center border-b-2 px-2 py-2 text-center text-sm font-semibold leading-tight sm:justify-start sm:px-4 sm:text-left"
        :class="pestana.activa ? 'border-primary text-primary' : 'border-transparent text-ink-muted hover:text-ink'"
      >{{ pestana.etiqueta }}</a>
    </nav>

    <details class="group mt-6 rounded-card bg-surface-raised shadow-card">
      <summary class="flex min-h-14 cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 marker:hidden sm:px-5">
        <span class="min-w-0">
          <span class="block text-sm font-semibold text-ink">{{ usaPeriodo ? 'Período y sucursal' : 'Sucursal' }}</span>
          <span class="block truncate text-xs text-ink-muted">{{ resumenFiltros }}</span>
        </span>
        <span class="text-sm font-semibold text-primary group-open:hidden">Editar</span>
        <span class="hidden text-sm font-semibold text-primary group-open:inline">Cerrar</span>
      </summary>
      <form :action="urls.index" method="get" class="grid gap-4 border-t border-border-subtle p-4 sm:grid-cols-[minmax(12rem,1fr)_minmax(9rem,0.6fr)_auto] sm:items-end">
        <input type="hidden" name="vista" :value="vista" />
        <label class="grid gap-1.5 text-sm font-semibold text-ink">
          Sucursal
          <select name="sucursal_id" class="min-h-11 rounded-pill border border-transparent bg-surface-muted px-3 text-sm font-normal text-ink">
            <option value="">Todas las autorizadas</option>
            <option
              v-for="branch in branches"
              :key="branch.id"
              :value="branch.id"
              :selected="Number(filtros.sucursalId) === Number(branch.id)"
            >{{ branch.name }}</option>
          </select>
        </label>
        <label v-if="usaPeriodo" class="grid gap-1.5 text-sm font-semibold text-ink">
          Período
          <select name="dias" class="min-h-11 rounded-pill border border-transparent bg-surface-muted px-3 text-sm font-normal text-ink">
            <option v-for="dias in opciones.dias" :key="dias" :value="dias" :selected="Number(filtros.dias) === Number(dias)">
              Últimos {{ dias }} días
            </option>
          </select>
        </label>
        <input v-else type="hidden" name="dias" :value="filtros.dias" />
        <button type="submit" :class="primaryButton">
          Aplicar
        </button>
      </form>
    </details>

    <section aria-labelledby="fleet-board-summary" class="mt-6">
      <h2 id="fleet-board-summary" class="sr-only">Resumen</h2>
      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
        <article v-for="(card, index) in tarjetas" :key="card.label" class="rounded-card p-4" :class="index === 0 ? 'ui-card-emphasis' : 'bg-surface-raised shadow-card'">
          <p class="text-xs font-bold uppercase tracking-wide text-ink-muted">{{ card.label }}</p>
          <p class="mt-1 text-2xl font-bold tabular-nums text-ink">{{ card.value }}</p>
          <p class="mt-1 text-xs leading-5 text-ink-muted">{{ card.hint }}</p>
        </article>
      </div>
    </section>

    <!-- Uso y excesos -->
    <template v-if="vista === 'uso'">
      <section v-if="cortes.length" class="mt-6 rounded-xl border border-warning/30 bg-warning-subtle p-4 sm:p-5" aria-labelledby="fleet-board-cuts">
        <h2 id="fleet-board-cuts" class="flex items-center gap-2 text-sm font-bold text-warning-strong">
          <ExclamationTriangleIcon class="size-5" aria-hidden="true" />Cortes del proveedor de telemetría
        </h2>
        <p class="mt-1 text-sm leading-6 text-ink">
          Durante un corte no llegan datos de la mitad o más de las unidades a la vez. Los km, el ralentí y los excesos
          de esas horas no se ven: no es un problema de los camiones.
        </p>
        <ul class="mt-3 grid gap-2 text-sm text-ink">
          <li v-for="(corte, indice) in cortes" :key="indice">
            <strong class="font-semibold">{{ corte.integracion ?? corte.proveedor ?? 'Proveedor' }}</strong>:
            {{ fecha(corte.desde) }} → {{ corte.sigueAbierto ? 'sigue abierto' : fecha(corte.hasta) }}
            ({{ entero(corte.horas, ' h') }}, {{ entero(corte.unidadesAfectadas) }} de {{ entero(corte.unidadesTotales) }} unidades)
          </li>
        </ul>
      </section>

      <section aria-label="Gráficos de uso" class="mt-6 grid gap-4">
        <FleetCutsTimeline :desde="periodo.desde" :hasta="periodo.hasta" :cortes="cortes" :format-date="fecha" />
        <div class="grid gap-4 lg:grid-cols-3">
          <FleetRankingChart
            id="fleet-board-km-chart"
            title="Km recorridos"
            description="Odómetro del GPS en el período."
            :items="kmPorUnidad"
            :formatter="kmTexto"
          />
          <FleetRankingChart
            id="fleet-board-idle-chart"
            title="Motor encendido con el camión detenido"
            description="Porcentaje de las horas de motor."
            :items="motorDetenido"
            :formatter="(value) => unDecimal(value, '%')"
            bar-class="bg-warning"
          />
          <FleetRankingChart
            id="fleet-board-speeding-chart"
            title="Excesos de velocidad"
            :description="`Episodios de más de ${entero(resultado.umbrales?.velocidadExcesoKmh)} km/h.`"
            :items="excesosPorUnidad"
            :formatter="(value) => entero(value)"
            bar-class="bg-danger"
            empty-text="Ningún camión superó el límite en el período."
          />
        </div>
      </section>

      <section class="mt-6 overflow-hidden rounded-card bg-surface-raised shadow-card" aria-labelledby="fleet-board-units">
        <div class="border-b border-border px-5 py-4 sm:px-6">
          <h2 id="fleet-board-units" class="text-lg font-bold text-ink">Uso por unidad</h2>
        </div>
        <div v-if="unidades.length" class="overflow-x-auto">
          <table class="ui-table min-w-full divide-y divide-border text-left text-sm">
            <thead class="bg-surface-muted text-xs font-semibold uppercase tracking-wide text-ink-muted">
              <tr>
                <th scope="col" class="px-4 py-3">Unidad</th>
                <th scope="col" class="px-4 py-3">Señal</th>
                <th scope="col" class="px-4 py-3 text-right">Km</th>
                <th scope="col" class="px-4 py-3 text-right">Km/día</th>
                <th scope="col" class="px-4 py-3 text-right">Horas motor</th>
                <th scope="col" class="px-4 py-3 text-right">Motor detenido</th>
                <th scope="col" class="px-4 py-3 text-right">Ralentí</th>
                <th scope="col" class="px-4 py-3 text-right">Vel. máx.</th>
                <th scope="col" class="px-4 py-3 text-right">Excesos</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border-subtle">
              <tr v-for="unidad in unidades" :key="unidad.equipoId" class="hover:bg-surface-subtle">
                <th scope="row" class="whitespace-nowrap px-4 py-3 font-semibold text-ink">
                  <a v-if="unidad.detalleUrl" :href="unidad.detalleUrl" class="text-primary hover:underline">{{ unidad.patente }}</a>
                  <span v-else>{{ unidad.patente }}</span>
                  <span class="block text-xs font-normal text-ink-muted">{{ unidad.sucursal }} · {{ unidad.proveedor ?? 'Sin proveedor' }}</span>
                </th>
                <td class="whitespace-nowrap px-4 py-3">
                  <span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="(senal[unidad.estadoSenal] ?? senal.SIN_DATOS).tono">
                    {{ (senal[unidad.estadoSenal] ?? senal.SIN_DATOS).etiqueta }}
                  </span>
                  <span class="mt-1 block text-xs text-ink-muted">Último dato: {{ fecha(unidad.ultimoDato) }}</span>
                </td>
                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-ink">{{ entero(unidad.km) }}</td>
                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-ink-muted">{{ entero(unidad.kmPorDia) }}</td>
                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-ink">{{ unDecimal(unidad.horasMotor) }}</td>
                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-ink">{{ unDecimal(unidad.porcentajeMotorDetenido, '%') }}</td>
                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-ink">{{ unDecimal(unidad.porcentajeRalenti, '%') }}</td>
                <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-ink-muted">{{ entero(unidad.velocidadMaximaKmh, ' km/h') }}</td>
                <td class="whitespace-nowrap px-4 py-3 text-right font-semibold tabular-nums" :class="unidad.excesos > 0 ? 'text-danger-strong' : 'text-ink'">{{ entero(unidad.excesos) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-else role="status" class="px-5 py-10 text-center text-sm leading-6 text-ink-muted">
          No hay unidades con telemetría vinculada en la sucursal seleccionada.
        </p>
      </section>

      <section v-if="excesos.length" class="mt-6 overflow-hidden rounded-card bg-surface-raised shadow-card" aria-labelledby="fleet-board-speeding">
        <div class="border-b border-border px-5 py-4 sm:px-6">
          <h2 id="fleet-board-speeding" class="text-lg font-bold text-ink">Excesos de velocidad</h2>
          <p class="mt-1 text-sm leading-6 text-ink-muted">
            Dos muestras seguidas por encima del límite. La duración es un mínimo: entre muestras el camión pudo ir rápido más tiempo.
          </p>
        </div>
        <ul class="divide-y divide-border-subtle text-sm">
          <li v-for="(exceso, indice) in excesos" :key="indice" class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 sm:px-6">
            <span class="font-semibold text-ink">{{ exceso.patente }}</span>
            <span class="text-ink-muted">{{ fecha(exceso.inicio) }} → {{ fecha(exceso.fin) }}</span>
            <span class="font-semibold tabular-nums text-danger-strong">{{ entero(exceso.velocidadMaximaKmh, ' km/h') }}</span>
          </li>
        </ul>
      </section>
    </template>

    <!-- Combustible -->
    <template v-else-if="vista === 'combustible'">
    <ConciliationShareBar class="mt-6" :por-estado="totales.porEstado ?? {}" />
    <FuelReconciliationTable :viajes="viajes" />
    </template>

    <!-- Services por GPS -->
    <template v-else>
    <ServiceKmChart class="mt-6" :planes="planes" :format-km="kmTexto" />
    <section class="mt-6 overflow-hidden rounded-card bg-surface-raised shadow-card" aria-labelledby="fleet-board-services">
      <div class="border-b border-border px-5 py-4 sm:px-6">
        <h2 id="fleet-board-services" class="text-lg font-bold text-ink">Próximo service estimado con el GPS</h2>
        <p class="mt-1 text-sm leading-6 text-ink-muted">
          Km estimado = última lectura del chofer + km que sumó el GPS desde entonces. Es una estimación: no cambia el
          odómetro, el estado del plan ni los avisos. El GPS suele contar algo menos que el tablero, así que tiende a quedarse corto.
        </p>
      </div>
      <div v-if="planes.length" class="overflow-x-auto">
        <table class="ui-table min-w-full divide-y divide-border text-left text-sm">
          <thead class="bg-surface-muted text-xs font-semibold uppercase tracking-wide text-ink-muted">
            <tr>
              <th scope="col" class="px-4 py-3">Unidad y service</th>
              <th scope="col" class="px-4 py-3 text-right">Próximo</th>
              <th scope="col" class="px-4 py-3">Con la lectura</th>
              <th scope="col" class="px-4 py-3 text-right">GPS desde la lectura</th>
              <th scope="col" class="px-4 py-3">Estimado</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border-subtle">
            <tr v-for="fila in planes" :key="fila.planId" class="align-top hover:bg-surface-subtle" :class="fila.seAdelanta ? 'bg-warning-subtle/40' : ''">
              <th scope="row" class="whitespace-nowrap px-4 py-3 font-semibold text-ink">
                <a v-if="equipoUrl(fila.equipoId)" :href="equipoUrl(fila.equipoId)" class="text-primary hover:underline">{{ fila.patente ?? fila.codigo }}</a>
                <span class="block text-xs font-normal text-ink-muted">{{ fila.servicio }} · {{ fila.sucursal }}</span>
              </th>
              <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-ink">{{ entero(fila.proximoKm, ' km') }}</td>
              <td class="whitespace-nowrap px-4 py-3">
                <span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="(plan[fila.real?.estado] ?? plan.SIN_DATOS).tono">{{ (plan[fila.real?.estado] ?? plan.SIN_DATOS).etiqueta }}</span>
                <span class="mt-1 block text-xs tabular-nums text-ink-muted">{{ restantes(fila.real?.kmRestantes) }}</span>
                <span class="block text-xs text-ink-muted">Lectura: {{ fila.lectura ? `${entero(fila.lectura.km)} · ${fecha(fila.lectura.fecha)}` : SIN_DATOS }}</span>
              </td>
              <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums text-ink">
                {{ vacio(fila.gps?.kmDesdeLectura) ? SIN_DATOS : `+${entero(fila.gps.kmDesdeLectura)} km` }}
                <span v-if="fila.gps?.hasta" class="block text-xs text-ink-muted">al {{ fecha(fila.gps.hasta) }}</span>
              </td>
              <td class="min-w-56 px-4 py-3">
                <template v-if="fila.estimado">
                  <span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="(plan[fila.estimado.estado] ?? plan.SIN_DATOS).tono">{{ (plan[fila.estimado.estado] ?? plan.SIN_DATOS).etiqueta }}</span>
                  <span class="mt-1 block text-xs tabular-nums text-ink-muted">{{ restantes(fila.estimado.kmRestantes) }} · {{ entero(fila.estimado.km, ' km') }}</span>
                  <span v-if="fila.seAdelanta" class="block text-xs font-semibold text-warning-strong">El GPS adelanta el service</span>
                </template>
                <span v-else class="block text-xs leading-5 text-ink-muted">Sin estimación: {{ fila.motivo }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-else role="status" class="px-5 py-10 text-center text-sm leading-6 text-ink-muted">
        No hay planes preventivos por kilometraje en la sucursal seleccionada.
      </p>
    </section>
    </template>

    <p class="mt-4 text-xs leading-5 text-ink-muted">
      «Sin datos» significa que falta información mínima para el indicador (regla 17). Este tablero es de solo lectura.
    </p>
  </div>
</template>
