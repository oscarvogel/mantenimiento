<script setup>
import { computed, nextTick, ref } from 'vue'
import { ExclamationTriangleIcon, InformationCircleIcon } from '@heroicons/vue/24/outline'
import EmptyState from './components/EmptyState.vue'
import PanelCard from './components/PanelCard.vue'

const props = defineProps({ data: { type: Object, required: true } })

const PERIODOS = [1, 7, 14, 30]
const numero = (value, decimales = 1) => (value === null || value === undefined
  ? null
  : new Intl.NumberFormat('es-AR', { minimumFractionDigits: decimales, maximumFractionDigits: decimales }).format(value))
const entero = (value) => (value === null || value === undefined
  ? null
  : new Intl.NumberFormat('es-AR').format(value))
const hora = (value) => (value ? String(value).slice(11, 16) : '')
const fecha = (value) => (value ? String(value).slice(0, 10) : '')

const reporte = computed(() => props.data.reporte ?? null)
const viajes = computed(() => reporte.value?.viajes ?? [])
const equiposDisponibles = computed(() => props.data.equipos ?? [])

const equipoSeleccionado = computed(() => props.data.equipo?.id == null
  ? null
  : equiposDisponibles.value.find((equipo) => String(equipo.id) === String(props.data.equipo.id)) ?? null)
const etiquetaEquipoSeleccionado = computed(() => equipoSeleccionado.value
  ? [equipoSeleccionado.value.plate, equipoSeleccionado.value.branchName].filter(Boolean).join(' — ')
  : '')
const consultaEquipo = ref('')
const listaEquiposAbierta = ref(false)
const indiceEquipoActivo = ref(0)
const listaEquipos = ref(null)
const normalizarBusqueda = (texto) => String(texto ?? '')
  .normalize('NFD')
  .replace(/[\u0300-\u036f]/g, '')
  .toLocaleLowerCase('es-AR')
  .trim()
const equiposFiltrados = computed(() => {
  const consulta = normalizarBusqueda(consultaEquipo.value)
  return equiposDisponibles.value
    .filter((equipo) => normalizarBusqueda(`${equipo.plate} ${equipo.branchName}`).includes(consulta))
    .slice(0, 30)
})
const opcionEquipoActivaId = computed(() => equiposFiltrados.value[indiceEquipoActivo.value]
  && listaEquiposAbierta.value
  ? `consumo-equipo-opcion-${indiceEquipoActivo.value}`
  : undefined)

const abrirListaEquipos = () => {
  consultaEquipo.value = ''
  const indiceSeleccionado = equiposDisponibles.value.findIndex((equipo) =>
    String(equipo.id) === String(props.data.equipo?.id ?? ''),
  )
  indiceEquipoActivo.value = indiceSeleccionado >= 0 && indiceSeleccionado < 30 ? indiceSeleccionado : 0
  listaEquiposAbierta.value = true
}

const buscarEquipos = (event) => {
  consultaEquipo.value = event.target.value
  indiceEquipoActivo.value = 0
  listaEquiposAbierta.value = true
}

const elegirEquipo = (equipo) => {
  listaEquiposAbierta.value = false
  consultaEquipo.value = ''
  irA(String(equipo.id), props.data.window.dias)
}

const moverEquipoActivo = (direccion) => {
  if (!equiposFiltrados.value.length) return
  indiceEquipoActivo.value = (indiceEquipoActivo.value + direccion + equiposFiltrados.value.length)
    % equiposFiltrados.value.length
  nextTick(() => listaEquipos.value?.children[indiceEquipoActivo.value]?.scrollIntoView?.({ block: 'nearest' }))
}

const manejarTecladoEquipos = (event) => {
  if (event.key === 'Escape') {
    listaEquiposAbierta.value = false
    consultaEquipo.value = ''
    return
  }

  if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
    event.preventDefault()
    if (!listaEquiposAbierta.value) abrirListaEquipos()
    else moverEquipoActivo(event.key === 'ArrowDown' ? 1 : -1)
    return
  }

  if (event.key === 'Enter' && listaEquiposAbierta.value && equiposFiltrados.value[indiceEquipoActivo.value]) {
    event.preventDefault()
    elegirEquipo(equiposFiltrados.value[indiceEquipoActivo.value])
  }
}

// `window` no está en el scope del template de Vue, así que la ruta se resuelve
// una vez acá y se usa como dato.
const ruta = window.location.pathname
const rutaFlota = ruta.replace(/\/consumo$/, '') + '/telemetria'

// La leyenda no es un tooltip a propósito: si hace falta pasar el mouse para
// enterarse de que el número es una estimación, el operador no se entera.
const calibracion = computed(() => reporte.value?.sinCalibrar === true)

const resumen = computed(() => {
  const totales = reporte.value?.totales
  if (!totales) return null
  const por100 = viajes.value.filter((viaje) => viaje.litrosPor100Km !== null)
  const promedio = por100.length
    ? por100.reduce((acc, viaje) => acc + viaje.litrosPor100Km, 0) / por100.length
    : null
  return { ...totales, promedio }
})

const banda = computed(() => props.data.bandaFlota ?? { minima: 33, maxima: 41 })

const url = (equipoId, dias) => {
  const params = new URLSearchParams()
  if (equipoId) params.set('equipo', String(equipoId))
  params.set('dias', String(dias))
  return `${ruta}?${params.toString()}`
}

const irA = (equipoId, dias) => { window.location.href = url(equipoId, dias) }

const problemas = computed(() => {
  if (!reporte.value) return 0
  return viajes.value.filter((viaje) => !viaje.litrosPor100Km
    || !viaje.reconstruible || viaje.huboCarga).length
})
</script>

<template>
  <div class="space-y-6">
    <header class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <p class="text-[0.6875rem] font-bold uppercase tracking-[0.08em] text-primary">Telemetría</p>
        <h1 class="mt-1 text-2xl font-bold tracking-tight text-ink sm:text-3xl">Consumo por viaje</h1>
        <p class="mt-2 max-w-3xl text-sm leading-6 text-ink-muted">
          Combustible por trayecto, derivado del histórico de capturas. El origen del odómetro es la
          telemetría, no el odómetro físico: sirve para diagnóstico, no como respaldo contable.
        </p>
      </div>
      <a :href="rutaFlota" class="inline-flex items-center gap-1 text-sm font-semibold text-primary hover:text-primary-hover">
        Ver tablero de flota
      </a>
    </header>

    <!-- Aviso de calibración. Va arriba de todo y no se puede pasar por alto. -->
    <div
      v-if="calibracion"
      role="alert"
      class="flex gap-3 rounded-xl border border-warning bg-warning-subtle p-4 sm:p-5"
      data-test="aviso-calibracion"
    >
      <ExclamationTriangleIcon class="size-5 shrink-0 text-warning-strong" aria-hidden="true" />
      <div class="text-sm">
        <p class="font-bold text-warning-strong">Estos números son provisorios</p>
        <p class="mt-1 text-ink">
          Los umbrales con los que se clasificó cada intervalo son valores de fábrica, y nadie los
          verificó contra telemetría real todavía. Sirven para orientarse; no para liquidar ni para
          comparar la forma de conducir. Cuando la empresa configure sus propios umbrales, este
          aviso desaparece.
        </p>
      </div>
    </div>

    <PanelCard title="Período y equipo" flush allow-overflow>
      <div class="grid gap-4 p-5 sm:grid-cols-2 sm:p-6">
        <div>
          <label for="consumo-equipo" class="block text-xs font-bold uppercase tracking-wide text-ink-muted">Equipo</label>
          <div class="relative mt-1">
            <input
              id="consumo-equipo"
              type="search"
              role="combobox"
              aria-autocomplete="list"
              aria-haspopup="listbox"
              :aria-expanded="listaEquiposAbierta"
              aria-controls="consumo-equipo-listbox"
              :aria-activedescendant="opcionEquipoActivaId"
              autocomplete="off"
              spellcheck="false"
              :placeholder="etiquetaEquipoSeleccionado || 'Buscar por patente o sucursal…'"
              :value="listaEquiposAbierta ? consultaEquipo : etiquetaEquipoSeleccionado"
              class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm"
              data-test="selector-equipo"
              @focus="abrirListaEquipos"
              @input="buscarEquipos"
              @keydown="manejarTecladoEquipos"
              @blur="listaEquiposAbierta = false"
            />
            <div
              v-if="listaEquiposAbierta"
              id="consumo-equipo-listbox"
              ref="listaEquipos"
              role="listbox"
              aria-label="Equipos disponibles"
              class="absolute z-40 mt-1 max-h-72 w-full overflow-y-auto rounded-lg border border-border bg-surface-raised p-1 shadow-xl"
              @mousedown.prevent
            >
              <button
                v-for="(equipo, indice) in equiposFiltrados"
                :id="`consumo-equipo-opcion-${indice}`"
                :key="equipo.id"
                type="button"
                role="option"
                :aria-selected="indice === indiceEquipoActivo"
                class="block min-h-10 w-full rounded-md px-3 py-2 text-left text-sm text-ink hover:bg-surface-muted focus:bg-surface-muted focus:outline-none"
                :class="indice === indiceEquipoActivo ? 'bg-surface-muted' : ''"
                @click="elegirEquipo(equipo)"
              >
                <span class="font-semibold">{{ equipo.plate }}</span>
                <span v-if="equipo.branchName" class="text-ink-muted"> — {{ equipo.branchName }}</span>
              </button>
              <p v-if="equiposFiltrados.length === 0" class="px-3 py-3 text-sm text-ink-muted">
                No hay equipos que coincidan con la búsqueda.
              </p>
            </div>
          </div>
        </div>
        <div>
          <span class="block text-xs font-bold uppercase tracking-wide text-ink-muted">Días</span>
          <div class="mt-1 flex flex-wrap gap-2">
            <a
              v-for="periodo in PERIODOS"
              :key="periodo"
              :href="url(props.data.equipo?.id, periodo)"
              class="rounded-lg border px-3 py-2 text-sm font-semibold"
              :class="props.data.window.dias === periodo ? 'border-primary bg-primary-subtle text-primary' : 'border-border text-ink-muted hover:border-primary'"
            >{{ periodo }} d</a>
          </div>
        </div>
      </div>
    </PanelCard>

    <EmptyState
      v-if="!reporte"
      title="Sin datos suficientes"
      description="No hay capturas de telemetría de este equipo en el período elegido. Si acaba de habilitar la integración, esperá la próxima lectura: hace falta una secuencia de al menos dos capturas para poder medir."
    />

    <template v-else>
      <div
        v-if="!reporte.cobertura.suficiente"
        class="flex gap-3 rounded-xl border border-warning bg-warning-subtle p-4"
        data-test="aviso-cobertura"
      >
        <InformationCircleIcon class="size-5 shrink-0 text-warning-strong" aria-hidden="true" />
        <p class="text-sm text-ink">
          <strong class="font-bold text-warning-strong">Cobertura insuficiente.</strong>
          {{ reporte.cobertura.motivo }}
          <span class="block text-ink-muted">
            Sabés {{ reporte.cobertura.porcentaje }}% del período ({{ reporte.cobertura.minutosCubiertos }}
            de {{ reporte.cobertura.minutosEsperados }} minutos).
          </span>
        </p>
      </div>

      <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <PanelCard title="Kilómetros"><p class="text-2xl font-bold text-ink">{{ entero(resumen.kilometraje) ?? '—' }} <span class="text-sm font-medium text-ink-muted">km</span></p></PanelCard>
        <PanelCard title="Litros consumidos"><p class="text-2xl font-bold text-ink">{{ numero(resumen.litros, 1) ?? '—' }} <span class="text-sm font-medium text-ink-muted">l</span></p></PanelCard>
        <PanelCard title="Consumo medio">
          <p class="text-2xl font-bold text-ink">{{ numero(resumen.promedio, 1) ?? '—' }} <span class="text-sm font-medium text-ink-muted">l/100km</span></p>
          <p class="mt-1 text-xs text-ink-muted">Banda de la flota: {{ banda.minima }}–{{ banda.maxima }}</p>
        </PanelCard>
        <PanelCard title="Viajes">
          <p class="text-2xl font-bold text-ink">{{ entero(resumen.viajes) }}</p>
          <p class="mt-1 text-xs text-ink-muted">
            {{ entero(resumen.conPorHora) }} con l/h · {{ entero(resumen.sinConsumo) }} sin medición
          </p>
        </PanelCard>
      </div>

      <PanelCard title="Viajes" :count="viajes.length" flush>
        <div class="overflow-x-auto">
          <table class="w-full min-w-[46rem] text-left text-sm">
            <thead class="border-b border-border-subtle text-xs uppercase tracking-wide text-ink-muted">
              <tr>
                <th class="px-4 py-3 font-bold">Fecha</th>
                <th class="px-4 py-3 font-bold">Inicio</th>
                <th class="px-4 py-3 font-bold">Fin</th>
                <th class="px-4 py-3 font-bold text-right">km</th>
                <th class="px-4 py-3 font-bold text-right">Litros</th>
                <th class="px-4 py-3 font-bold text-right">l/100km</th>
                <th class="px-4 py-3 font-bold text-right">l/h motor</th>
                <th class="px-4 py-3 font-bold text-right">Cobertura</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border-subtle">
              <tr v-for="(viaje, indice) in viajes" :key="`${viaje.inicio}-${indice}`" class="align-top" data-test="fila-viaje">
                <td class="px-4 py-3 text-ink">{{ fecha(viaje.inicio) }}</td>
                <td class="px-4 py-3 text-ink">{{ hora(viaje.inicio) }}</td>
                <td class="px-4 py-3 text-ink">{{ hora(viaje.fin) }}</td>
                <td class="px-4 py-3 text-right text-ink">{{ entero(viaje.kilometraje) }}</td>
                <td class="px-4 py-3 text-right text-ink" data-test="celda-litros">
                  {{ numero(viaje.litros, 1) }}
                  <span v-if="viaje.huboCarga" class="block text-xs text-info-strong">+{{ numero(viaje.litrosCargados, 0) }} l de carga</span>
                </td>
                <td class="px-4 py-3 text-right" data-test="celda-100km" :class="viaje.fueraDeBanda ? 'text-warning-strong font-bold' : 'text-ink'">
                  <span v-if="viaje.litrosPor100Km !== null">{{ numero(viaje.litrosPor100Km, 1) }}</span>
                  <!--
                    El motivo va como texto visible y no sólo en el title: en el
                    celular no hay hover, y una celda vacía sin explicación parece
                    un dato que no llegó en lugar de "no se pudo medir".
                  -->
                  <span v-else class="block text-xs font-semibold italic text-ink-muted">Sin dato</span>
                  <span v-if="viaje.litrosPor100Km === null" class="mt-0.5 block text-xs leading-4 text-ink-subtle line-clamp-2" :title="viaje.motivoSinPor100Km">
                    {{ viaje.motivoSinPor100Km }}
                  </span>
                </td>
                <td class="px-4 py-3 text-right" :class="viaje.litrosPorHoraMotor === null ? 'text-ink-muted' : 'text-ink'">
                  <span v-if="viaje.litrosPorHoraMotor !== null">{{ numero(viaje.litrosPorHoraMotor, 1) }}</span>
                  <template v-else>
                    <span class="block text-xs font-semibold italic text-ink-muted">No calculable</span>
                    <span class="mt-0.5 block text-xs leading-4 text-ink-subtle line-clamp-2" :title="viaje.motivoSinPorHora">
                      {{ viaje.motivoSinPorHora }}
                    </span>
                  </template>
                </td>
                <td class="px-4 py-3 text-right">
                  <span class="text-ink">{{ viaje.coberturaPeriodo }}%</span>
                  <span v-if="!viaje.reconstruible" class="block text-xs italic text-warning-strong" :title="viaje.motivoNoReconstruible">No reconstruible</span>
                </td>
              </tr>
            </tbody>
            <tfoot v-if="viajes.length" class="border-t-2 border-border bg-surface-muted font-bold">
              <tr>
                <!-- Sin horas: un "06:00 – 18:30" sobre varios días no describe nada. -->
                <td class="px-4 py-3 text-ink" colspan="3">Total de {{ viajes.length }} viaje{{ viajes.length === 1 ? '' : 's' }}</td>
                <td class="px-4 py-3 text-right text-ink">{{ entero(resumen.kilometraje) }}</td>
                <td class="px-4 py-3 text-right text-ink">{{ numero(resumen.litros, 1) }}</td>
                <td class="px-4 py-3 text-right text-ink">{{ numero(resumen.promedio, 1) }}</td>
                <td class="px-4 py-3 text-right text-ink-muted">—</td>
                <td class="px-4 py-3"></td>
              </tr>
            </tfoot>
          </table>
        </div>
        <EmptyState
          v-if="!viajes.length"
          compact
          title="Sin viajes en el período"
          :description="viajes.length === 0 && reporte.muestrasLeidas > 0
            ? 'Hay capturas pero ninguna forma un trayecto: el equipo no se movió, o las capturas están demasiado separadas para reconstruir el recorrido.'
            : 'Sin datos suficientes.'"
        />
      </PanelCard>
    </template>
  </div>
</template>
