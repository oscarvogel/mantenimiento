<script setup>
/**
 * Tablero de telemetría del equipo.
 *
 * Se lee como el tablero de instrumentos de un camión, no como un panel más:
 * el perfil del tanque partido, el interruptor de motor, la batería. Cada
 * indicador tiene TRES estados —encendido, apagado y sin dato— porque un
 * sensor apagado y un sensor que no reporta parecen lo mismo en un switch de
 * dos posiciones, y el operador los atiende de forma distinta.
 *
 * Y la fecha de la señal va siempre al lado del dato. Un punto en el mapa sin
 * su hora manda a un chofer a donde el camión no está.
 */
import { computed, ref } from 'vue'

const props = defineProps({
  telemetry: { type: Object, required: true },
  equipment: { type: Object, required: true },
})

const mapaAbierto = ref(false)

// El botón sólo existe con permiso y con token completo. Un control visible
// que va a rebotar con 403 es peor que un control que no está.
const puedeActualizar = computed(
  () =>
    props.telemetry.canRefresh === true &&
    Boolean(props.telemetry.csrf?.name) &&
    Boolean(props.telemetry.csrf?.hash) &&
    props.telemetry.refreshUrl !== '#',
)

const LIMITE_FRESCAZA_MIN = 60
const LIMITE_VIEJA_MIN = 1440

// Capacidad orientativa de los dos tanques delIUECO. Sirve para dibujar el
// perfil; el porcentaje real se calcula contra lo que cargó el proveedor.
const CAPACIDAD_TANQUES = { T1: 550, T2: 230 }

const formatKm = (value) =>
  value === null || value === undefined
    ? '—'
    : Number(value).toLocaleString('es-AR', { maximumFractionDigits: 0 })

const formatLitros = (value) =>
  value === null || value === undefined
    ? '—'
    : Number(value).toLocaleString('es-AR', { maximumFractionDigits: 0 })

const formatHora = (value) => {
  if (!value) return '—'
  const fecha = new Date(String(value).replace(' ', 'T'))
  if (Number.isNaN(fecha.getTime())) return String(value)
  return fecha.toLocaleString('es-AR', {
    day: '2-digit',
    month: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  })
}

const frescura = (fuente) => {
  const minutos = fuente.ageMinutes
  if (minutos === null || minutos === undefined) {
    return { etiqueta: 'sin dato', tono: 'gris', detalle: 'El proveedor nunca informó' }
  }
  if (minutos < LIMITE_FRESCAZA_MIN) {
    return { etiqueta: 'al día', tono: 'verde', detalle: `Reportó hace ${minutos} min` }
  }
  if (minutos < LIMITE_VIEJA_MIN) {
    const horas = Math.floor(minutos / 60)
    return { etiqueta: 'reciente', tono: 'ambar', detalle: `Hace ${horas} h` }
  }
  const dias = Math.floor(minutos / LIMITE_VIEJA_MIN)
  return { etiqueta: 'desactualizado', tono: 'rojo', detalle: `Hace ${dias} días` }
}

/**
 * Separa el combustible por tanque usando las medidas adicionales que el
 * proveedor conserva con su nombre original. El total ya viene del sensor
 * calculado; los tanques son el desglose.
 */
const tanquesDe = (fuente) => {
  const extras = fuente.extraSensors || []
  const t1 = extras.find((m) => /t1|dep[oó]\s*1|tanque\s*1/i.test(m.etiqueta || ''))
  const t2 = extras.find((m) => /t2|dep[oó]\s*2|tanque\s*2/i.test(m.etiqueta || ''))
  const total = fuente.fuelLiters

  const unTanque = (medida, capacidad) => {
    const valor =
      medida && medida.valor !== null && medida.valor !== undefined ? Number(medida.valor) : null
    const porcentaje =
      valor !== null && total && total > 0
        ? Math.max(0, Math.min(100, (valor / total) * 100))
        : null
    return { etiqueta: medida ? medida.etiqueta : null, valor, porcentaje, capacidad }
  }

  return [
    unTanque(t1, CAPACIDAD_TANQUES.T1),
    unTanque(t2, CAPACIDAD_TANQUES.T2),
  ].filter((t) => t.valor !== null)
}

const nivelCombustible = (fuente) => {
  const total = fuente.fuelLiters
  const extras = fuente.extraSensors || []
  const hayTanques = extras.some((m) => /t1|t2/i.test(m.etiqueta || ''))
  if (total === null || total === undefined) {
    return { proporcion: null, tono: 'gris', texto: 'sin dato' }
  }
  const capacidad = hayTanques ? CAPACIDAD_TANQUES.T1 + CAPACIDAD_TANQUES.T2 : 780
  const proporcion = Math.max(0, Math.min(100, (total / capacidad) * 100))
  if (proporcion <= 10) return { proporcion, tono: 'rojo', texto: 'crítico' }
  if (proporcion <= 25) return { proporcion, tono: 'ambar', texto: 'bajo' }
  return { proporcion, tono: 'verde', texto: 'normal' }
}

const voltajeEstado = (fuente) => {
  if (fuente.voltage === null || fuente.voltage === undefined) {
    return { nivel: 0, tono: 'gris', texto: 'sin dato' }
  }
  // 12 V es un camión parado; con el motor prendido la auxiliar sube a 13,8.
  const referencia = fuente.engineOn ? 24 : 22
  const nivel = Math.max(0, Math.min(100, ((fuente.voltage - 20) / 4) * 100))
  return {
    nivel,
    tono: fuente.voltage < referencia ? 'rojo' : 'verde',
    texto: fuente.voltage < referencia ? 'baja' : 'normal',
  }
}

const posicion = (fuente) => fuente.position

const mapaSrc = computed(() => {
  const fuente = props.telemetry.sources[0]
  if (!fuente || !fuente.position) return ''
  const { latitude, longitude } = fuente.position
  const d = 0.01
  const bbox = [longitude - d, latitude - d, longitude + d, latitude + d].join('%2C')
  return `https://www.openstreetmap.org/export/embed.html?bbox=${bbox}&layer=mapnik&marker=${latitude}%2C${longitude}`
})

const mapaEnlace = computed(() => {
  const fuente = props.telemetry.sources[0]
  if (!fuente || !fuente.position) return '#'
  return `https://www.openstreetmap.org/?mlat=${fuente.position.latitude}&mlon=${fuente.position.longitude}#map=14/${fuente.position.latitude}/${fuente.position.longitude}`
})
</script>

<template>
  <div class="space-y-5">
    <!--
      Refresco manual. Form POST y no fetch a propósito: no depende de
      JavaScript adicional, el navegador manda el token y el servidor
      responde con el resultado. Sin token completo el botón no aparece.
    -->
    <form
      v-if="puedeActualizar"
      method="post"
      :action="telemetry.refreshUrl"
      class="flex justify-end"
    >
      <input type="hidden" :name="telemetry.csrf.name" :value="telemetry.csrf.hash" />
      <button
        type="submit"
        class="ui-interactive inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-primary bg-surface px-4 py-2.5 text-sm font-semibold text-primary transition hover:bg-primary-subtle"
      >
        Actualizar telemetría
      </button>
    </form>

    <div
      v-for="(fuente, indice) in telemetry.sources"
      :key="fuente.integrationId"
      class="rounded-2xl border border-border bg-surface p-5"
    >
      <!-- cabecera: de dónde viene y cuándo habló -->
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p class="text-xs font-bold uppercase tracking-wide text-ink-muted">
            {{ fuente.integrationName || fuente.provider }}
          </p>
          <p class="mt-1 text-sm text-ink">
            Última señal: <span class="font-semibold">{{ formatHora(fuente.observedAt) }}</span>
          </p>
          <p class="text-xs text-ink-muted">
            {{ frescura(fuente).detalle }}
            <span v-if="fuente.stale"> · el proveedor no respondió la última corrida</span>
          </p>
        </div>
        <span
          class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-bold"
          :class="{
            'bg-success-subtle text-success': frescura(fuente).tono === 'verde',
            'bg-warning-subtle text-warning-foreground': frescura(fuente).tono === 'ambar',
            'bg-danger-subtle text-danger': frescura(fuente).tono === 'rojo',
            'bg-surface-muted text-ink-muted': frescura(fuente).tono === 'gris',
          }"
        >
          <span class="size-2 rounded-full bg-current" aria-hidden="true" />
          {{ frescura(fuente).etiqueta }}
        </span>
      </div>

      <div class="mt-5 grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
        <!-- MAPA: chico, click para agrandar -->
        <div class="space-y-2">
          <button
            type="button"
            class="ui-interactive block w-full overflow-hidden rounded-xl border border-border"
            :class="mapaAbierto ? 'fixed inset-4 z-50 shadow-2xl' : ''"
            :aria-label="mapaAbierto ? 'Cerrar el mapa' : 'Ampliar el mapa'"
            @click="mapaAbierto = !mapaAbierto"
          >
            <img
              v-if="posicion(fuente)"
              :src="mapaSrc"
              :alt="`Última ubicación conocida de ${equipment.code}`"
              class="h-56 w-full object-cover"
              loading="lazy"
            />
            <div
              v-else
              class="flex h-56 w-full flex-col items-center justify-center gap-2 bg-surface-muted px-4 text-center"
            >
              <span class="text-sm font-semibold text-ink">Sin posición</span>
              <span class="text-xs text-ink-muted">
                Esta fuente no reportó coordenadas en la última señal.
              </span>
            </div>
          </button>

          <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-ink-muted">
            <span v-if="posicion(fuente)">
              {{ posicion(fuente).latitude.toFixed(4) }}, {{ posicion(fuente).longitude.toFixed(4) }}
              <template v-if="posicion(fuente).speedKmh !== null">
                · {{ posicion(fuente).speedKmh }} km/h
              </template>
              <template v-if="posicion(fuente).satellites !== null">
                · {{ posicion(fuente).satellites }} sat
              </template>
            </span>
            <a
              v-if="posicion(fuente)"
              :href="mapaEnlace"
              target="_blank"
              rel="noopener"
              class="font-semibold text-primary underline"
            >
              Abrir en el mapa
            </a>
          </div>
        </div>

        <!-- COMBUSTIBLE: perfil de los dos tanques -->
        <div class="rounded-xl border border-border p-4">
          <p class="text-xs font-bold uppercase tracking-wide text-ink-muted">Combustible</p>

          <div class="mt-3 flex items-baseline gap-2">
            <span class="text-3xl font-bold text-ink">{{ formatLitros(fuente.fuelLiters) }}</span>
            <span class="text-sm text-ink-muted">litros · {{ nivelCombustible(fuente).texto }}</span>
          </div>

          <!-- perfil del tanque, partido en T1 y T2 -->
          <div class="mt-4">
            <div
              class="flex h-9 overflow-hidden rounded-lg border-2 border-ink/20"
              role="img"
              :aria-label="`Nivel de combustible: ${formatLitros(fuente.fuelLiters)} litros, ${nivelCombustible(fuente).texto}`"
            >
              <div
                v-for="(tanque, indiceTanque) in tanquesDe(fuente)"
                :key="tanque.etiqueta || indiceTanque"
                class="flex items-center justify-center text-[10px] font-bold uppercase text-white"
                :style="{ width: `${tanque.porcentaje}%` }"
                :class="{
                  'bg-danger': nivelCombustible(fuente).tono === 'rojo',
                  'bg-warning': nivelCombustible(fuente).tono === 'ambar',
                  'bg-success': nivelCombustible(fuente).tono === 'verde',
                }"
              >
                {{ tanque.porcentaje.toFixed(0) }}%
              </div>
              <div
                v-if="nivelCombustible(fuente).proporcion !== null && tanquesDe(fuente).length > 0"
                class="flex-1 bg-surface-muted"
              />
            </div>

            <ul class="mt-2 space-y-1 text-xs text-ink-muted">
              <li v-for="(tanque, indiceTanque) in tanquesDe(fuente)" :key="`l-${tanque.etiqueta || indiceTanque}`">
                {{ tanque.etiqueta }}: {{ formatLitros(tanque.valor) }} l
              </li>
            </ul>
          </div>
        </div>
      </div>

      <!-- interruptores: motor y ralentí, tres estados -->
      <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <!-- batería -->
        <div class="rounded-xl border border-border p-4">
          <p class="text-xs font-bold uppercase tracking-wide text-ink-muted">Batería</p>
          <div class="mt-2 flex items-center gap-2">
            <span
              class="inline-block h-3 w-8 rounded-sm border-2"
              :class="{
                'border-danger bg-danger/20': voltajeEstado(fuente).tono === 'rojo',
                'border-success bg-success/20': voltajeEstado(fuente).tono === 'verde',
                'border-ink-muted/40 bg-surface-muted': voltajeEstado(fuente).tono === 'gris',
              }"
              aria-hidden="true"
            />
            <span class="text-lg font-bold text-ink">
              {{ fuente.voltage === null || fuente.voltage === undefined ? '—' : fuente.voltage }}
              <span class="text-xs font-normal text-ink-muted">V</span>
            </span>
          </div>
          <p class="mt-1 text-xs text-ink-muted">{{ voltajeEstado(fuente).texto }}</p>
        </div>

        <!-- motor -->
        <div class="rounded-xl border border-border p-4">
          <p class="text-xs font-bold uppercase tracking-wide text-ink-muted">Motor</p>
          <div class="mt-2 flex items-center gap-2">
            <span
              class="relative inline-flex h-6 w-11 shrink-0 rounded-full transition-colors"
              :class="{
                'bg-success': fuente.engineOn === true,
                'bg-ink-muted/40': fuente.engineOn === false,
                'bg-surface-muted': fuente.engineOn === null,
              }"
              role="img"
              :aria-label="
                fuente.engineOn === null
                  ? 'Motor: sin dato'
                  : fuente.engineOn
                    ? 'Motor encendido'
                    : 'Motor apagado'
              "
            >
              <span
                class="absolute top-0.5 size-5 rounded-full bg-white shadow"
                :class="fuente.engineOn === true ? 'right-0.5' : 'left-0.5'"
              />
            </span>
            <span class="text-sm font-semibold text-ink">
              {{ fuente.engineOn === null ? 'Sin dato' : fuente.engineOn ? 'Encendido' : 'Apagado' }}
            </span>
          </div>
        </div>

        <!-- ralentí -->
        <div class="rounded-xl border border-border p-4">
          <p class="text-xs font-bold uppercase tracking-wide text-ink-muted">Ralentí</p>
          <div class="mt-2 flex items-center gap-2">
            <span
              class="inline-block size-3 rounded-full"
              :class="{
                'bg-warning': fuente.idling === true,
                'bg-ink-muted/40': fuente.idling === false,
                'bg-surface-muted ring-1 ring-ink-muted/40': fuente.idling === null,
              }"
              aria-hidden="true"
            />
            <span class="text-sm font-semibold text-ink">
              {{ fuente.idling === null ? 'Sin dato' : fuente.idling ? 'Activo' : 'Sin ralentí' }}
            </span>
          </div>
        </div>

        <!-- horómetro: hoy no existe en el sistema -->
        <div class="rounded-xl border border-border p-4">
          <p class="text-xs font-bold uppercase tracking-wide text-ink-muted">Horas de motor</p>
          <p class="mt-2 text-lg font-bold text-ink">
            {{ fuente.hours === null || fuente.hours === undefined ? '—' : fuente.hours }}
            <span v-if="fuente.hours !== null && fuente.hours !== undefined" class="text-xs font-normal text-ink-muted">
              h
            </span>
          </p>
          <p class="mt-1 text-xs text-ink-muted">Sólo del proveedor</p>
        </div>
      </div>

      <!-- sensores que el proveedor reporta y nosotros no interpretamos -->
      <div
        v-if="(fuente.extraSensors || []).filter((m) => !/t1|t2/i.test(m.etiqueta || '')).length"
        class="mt-4 rounded-xl border border-border bg-surface-muted/40 p-4"
      >
        <p class="text-xs font-bold uppercase tracking-wide text-ink-muted">
          Lecturas del proveedor
        </p>
        <ul class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-xs text-ink">
          <li
            v-for="medida in fuente.extraSensors.filter((m) => !/t1|t2/i.test(m.etiqueta || ''))"
            :key="medida.etiqueta"
          >
            {{ medida.etiqueta }}:
            <span class="font-semibold">
              {{ medida.valor === null || medida.valor === undefined ? '—' : medida.valor }}
              {{ medida.unidad }}
            </span>
          </li>
        </ul>
      </div>
    </div>

    <!-- el choque de kilometraje, resuelto -->
    <div class="rounded-2xl border border-border bg-surface p-5">
      <p class="text-xs font-bold uppercase tracking-wide text-ink-muted">Kilometraje</p>
      <div class="mt-3 grid gap-3 sm:grid-cols-3">
        <div>
          <p class="text-xs text-ink-muted">Del sistema</p>
          <p class="text-lg font-bold text-ink">{{ formatKm(telemetry.equipmentKm) }} km</p>
          <p class="text-xs text-ink-muted">Es el que carga el chofer</p>
        </div>
        <div
          v-for="fuente in telemetry.sources"
          :key="`km-${fuente.integrationId}`"
        >
          <p class="text-xs text-ink-muted">{{ fuente.integrationName || fuente.provider }}</p>
          <p class="text-lg font-bold text-ink">{{ formatKm(fuente.kilometers) }} km</p>
          <p class="text-xs text-ink-muted">
            <template v-if="fuente.kilometersDifference !== null && fuente.kilometersDifference !== undefined">
              diferencia {{ formatKm(fuente.kilometersDifference) }} km
            </template>
            <template v-else>sin referencia propia para comparar</template>
          </p>
        </div>
      </div>
    </div>
  </div>
</template>