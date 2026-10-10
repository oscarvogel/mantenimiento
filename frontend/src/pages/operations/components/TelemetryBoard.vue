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
import EmptyState from './EmptyState.vue'

const props = defineProps({
  telemetry: {
    type: Object,
    default: () => ({
      sources: [],
      canRefresh: false,
      csrf: {},
      refreshUrl: '#',
      equipmentKm: null,
    }),
  },
  equipment: { type: Object, required: true },
})

const mapaAbierto = ref(null)

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
const CAPACIDAD_TOTAL_REFERENCIA = 780

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
  if (fuente.stale) {
    return {
      etiqueta: 'sin respuesta',
      tono: minutos >= LIMITE_VIEJA_MIN ? 'rojo' : 'ambar',
      detalle: `Hace ${edadEnTexto(minutos)}`,
    }
  }
  if (minutos < LIMITE_FRESCAZA_MIN) {
    return { etiqueta: 'al día', tono: 'verde', detalle: `Reportó hace ${minutos} min` }
  }
  if (minutos < LIMITE_VIEJA_MIN) {
    return { etiqueta: 'reciente', tono: 'ambar', detalle: `Hace ${edadEnTexto(minutos)}` }
  }
  return { etiqueta: 'desactualizado', tono: 'rojo', detalle: `Hace ${edadEnTexto(minutos)}` }
}

const edadEnTexto = (minutos) => {
  if (minutos < 60) return `${minutos} min`
  const horas = Math.floor(minutos / 60)
  if (horas < 24) return `${horas} h`
  const dias = Math.floor(horas / 24)
  return `${dias} ${dias === 1 ? 'día' : 'días'}`
}

/**
 * Separa el combustible por tanque usando las medidas adicionales que el
 * proveedor conserva con su nombre original. El total ya viene del sensor
 * calculado; los tanques son el desglose.
 */
const tanquesDe = (fuente) => {
  const extras = fuente.extraSensors || []
  const definiciones = [
    { numero: 1, capacidad: CAPACIDAD_TANQUES.T1, patron: /\b(?:t1|dep[oó]s?ito\s*1|tanque\s*1)\b/i },
    { numero: 2, capacidad: CAPACIDAD_TANQUES.T2, patron: /\b(?:t2|dep[oó]s?ito\s*2|tanque\s*2)\b/i },
  ]

  return definiciones.flatMap(({ numero, capacidad, patron }) => {
    const medida = extras.find((sensor) => patron.test(sensor.etiqueta || ''))
    if (!medida) return []

    const valor = medida.valor === null || medida.valor === undefined ? null : Number(medida.valor)
    const proporcion = valor === null || !Number.isFinite(valor)
      ? null
      : Math.max(0, Math.min(100, (valor / capacidad) * 100))

    return [{ numero, etiqueta: `Tanque ${numero}`, valor, proporcion, capacidad }]
  })
}

const nivelCombustible = (fuente) => {
  const total = fuente.fuelLiters
  const extras = fuente.extraSensors || []
  const hayTanques = extras.some((m) => /t1|t2/i.test(m.etiqueta || ''))
  if (total === null || total === undefined) {
    return { proporcion: null, tono: 'gris', texto: 'sin dato' }
  }
  const capacidad = hayTanques
    ? CAPACIDAD_TANQUES.T1 + CAPACIDAD_TANQUES.T2
    : CAPACIDAD_TOTAL_REFERENCIA
  const proporcion = Math.max(0, Math.min(100, (total / capacidad) * 100))
  if (proporcion <= 10) return { proporcion, tono: 'rojo', texto: 'crítico' }
  if (proporcion <= 25) return { proporcion, tono: 'ambar', texto: 'bajo' }
  return { proporcion, tono: 'verde', texto: 'normal' }
}

const nivelTanqueTotal = (fuente) => {
  const nivel = nivelCombustible(fuente)
  return nivel.proporcion === null ? null : Math.round(nivel.proporcion)
}

const capacidadTanqueTotal = (fuente) => {
  const extras = fuente.extraSensors || []
  const hayTanques = extras.some((m) => /t1|t2/i.test(m.etiqueta || ''))
  return hayTanques
    ? CAPACIDAD_TANQUES.T1 + CAPACIDAD_TANQUES.T2
    : CAPACIDAD_TOTAL_REFERENCIA
}

const porcentajeTanque = (tanque) => tanque.proporcion === null ? null : Math.round(tanque.proporcion)

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

const mapaSrcDe = (fuente) => {
  if (!fuente?.position) return ''
  const { latitude, longitude } = fuente.position
  const d = 0.01
  const bbox = [longitude - d, latitude - d, longitude + d, latitude + d].join('%2C')
  return `https://www.openstreetmap.org/export/embed.html?bbox=${bbox}&layer=mapnik&marker=${latitude}%2C${longitude}`
}

const mapaEnlaceDe = (fuente) => {
  if (!fuente?.position) return '#'
  return `https://www.openstreetmap.org/?mlat=${fuente.position.latitude}&mlon=${fuente.position.longitude}#map=14/${fuente.position.latitude}/${fuente.position.longitude}`
}
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
      <input type="hidden" name="equipment_id" :value="equipment.id" />
      <button
        type="submit"
        class="ui-interactive inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-primary bg-surface px-4 py-2.5 text-sm font-semibold text-primary transition hover:bg-primary-subtle"
      >
        Actualizar telemetría
      </button>
    </form>

    <EmptyState
      v-if="telemetry.sources.length === 0"
      title="Todavía no hay una fuente vinculada"
      description="Cuando se vincule el proveedor de telemetría, acá vas a ver la ubicación y las lecturas del equipo."
    />

    <section class="rounded-xl border border-border bg-surface-subtle/50 p-4" aria-labelledby="telemetry-kilometers-title">
      <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h3 id="telemetry-kilometers-title" class="text-xs font-bold uppercase tracking-wide text-ink-muted">
          Comparación de kilometraje
        </h3>
        <p class="text-xs text-ink-muted">La lectura de telemetría es informativa; no modifica la del sistema.</p>
      </div>
      <div class="mt-3 grid gap-3 sm:grid-cols-2">
        <div class="rounded-lg border border-border bg-surface p-3">
          <p class="text-xs text-ink-muted">Del sistema · chofer</p>
          <p class="mt-1 text-xl font-bold tabular-nums text-ink">{{ formatKm(telemetry.equipmentKm) }} km</p>
        </div>
        <div
          v-for="fuente in telemetry.sources"
          :key="`km-${fuente.integrationId}`"
          class="rounded-lg border border-border bg-surface p-3"
        >
          <p class="text-xs text-ink-muted">{{ fuente.integrationName || fuente.provider }}</p>
          <p class="mt-1 text-xl font-bold tabular-nums text-ink">{{ formatKm(fuente.kilometers) }} km</p>
          <p class="mt-1 text-xs text-ink-muted">
            <template v-if="fuente.kilometersDifference !== null && fuente.kilometersDifference !== undefined">
              Diferencia: {{ formatKm(fuente.kilometersDifference) }} km
            </template>
            <template v-else>Sin referencia para comparar</template>
          </p>
        </div>
      </div>
    </section>

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
            <span v-if="fuente.stale"> · el proveedor no respondió la última consulta</span>
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
          <div class="relative overflow-hidden rounded-xl border border-border bg-surface">
            <iframe
              v-if="posicion(fuente)"
              :src="mapaSrcDe(fuente)"
              :title="`Última ubicación conocida de ${equipment.code}`"
              class="h-40 w-full border-0"
              loading="lazy"
              referrerpolicy="no-referrer-when-downgrade"
              allowfullscreen
            />
            <div
              v-else
              class="flex h-40 w-full flex-col items-center justify-center gap-2 bg-surface-muted px-4 text-center"
            >
              <span class="text-sm font-semibold text-ink">Sin posición</span>
              <span class="text-xs text-ink-muted">
                Esta fuente no reportó coordenadas en la última señal.
              </span>
            </div>
            <button
              v-if="posicion(fuente)"
              type="button"
              class="ui-interactive absolute right-2 top-2 rounded-lg border border-border bg-surface/95 px-3 py-2 text-xs font-semibold text-ink shadow"
              aria-label="Ampliar el mapa"
              @click="mapaAbierto = fuente.integrationId"
            >
              Ampliar mapa
            </button>
          </div>

          <Teleport to="body">
            <div
              v-if="mapaAbierto === fuente.integrationId && posicion(fuente)"
              class="fixed inset-0 z-[120] flex items-center justify-center bg-black/75 p-3 sm:p-6"
              role="dialog"
              aria-modal="true"
              aria-labelledby="telemetry-map-title"
              @click.self="mapaAbierto = null"
              @keydown.esc="mapaAbierto = null"
            >
              <section class="flex h-full max-h-[min(90vh,900px)] w-full max-w-7xl flex-col overflow-hidden rounded-xl border border-border bg-surface shadow-2xl">
                <header class="flex shrink-0 items-center justify-between gap-3 border-b border-border px-4 py-3 sm:px-5">
                  <h2 id="telemetry-map-title" class="font-semibold text-ink">
                    Última ubicación conocida de {{ equipment.code }}
                  </h2>
                  <button
                    type="button"
                    class="ui-interactive shrink-0 rounded-lg border border-border bg-surface px-3 py-2 text-sm font-semibold text-ink shadow-sm"
                    aria-label="Cerrar el mapa"
                    autofocus
                    @click="mapaAbierto = null"
                  >
                    Cerrar mapa
                  </button>
                </header>
                <iframe
                  :src="mapaSrcDe(fuente)"
                  :title="`Última ubicación conocida de ${equipment.code}`"
                  class="min-h-0 w-full flex-1 border-0"
                  referrerpolicy="no-referrer-when-downgrade"
                  allowfullscreen
                />
              </section>
            </div>
          </Teleport>

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
              :href="mapaEnlaceDe(fuente)"
              target="_blank"
              rel="noopener"
              class="font-semibold text-primary underline"
            >
              Abrir en el mapa
            </a>
          </div>
        </div>

        <!-- Combustible: nivel total o lectura individual por tanque. -->
        <div class="rounded-xl border border-border bg-surface-subtle/60 p-4">
          <div class="flex flex-wrap items-end justify-between gap-2">
            <div>
              <p class="text-xs font-bold uppercase tracking-wide text-ink-muted">Combustible</p>
              <p v-if="tanquesDe(fuente).length > 1" class="mt-1 text-3xl font-bold tabular-nums text-ink">
                <template v-if="fuente.fuelLiters !== null && fuente.fuelLiters !== undefined">
                  {{ formatLitros(fuente.fuelLiters) }}
                  <span class="text-sm font-semibold text-ink-muted">l en total</span>
                </template>
                <span v-else class="text-lg font-semibold text-ink-muted">Total sin dato</span>
              </p>
              <p
                v-else-if="tanquesDe(fuente).length === 0 && (fuente.fuelLiters === null || fuente.fuelLiters === undefined)"
                class="mt-1 text-lg font-semibold text-ink-muted"
              >
                Total sin dato
              </p>
            </div>
            <span
              class="rounded-full px-2.5 py-1 text-xs font-bold"
              :class="{
                'bg-success-subtle text-success': nivelCombustible(fuente).tono === 'verde',
                'bg-warning-subtle text-warning-foreground': nivelCombustible(fuente).tono === 'ambar',
                'bg-danger-subtle text-danger': nivelCombustible(fuente).tono === 'rojo',
                'bg-surface-muted text-ink-muted': nivelCombustible(fuente).tono === 'gris',
              }"
            >
              {{ nivelCombustible(fuente).texto }}
            </span>
          </div>

          <div
            v-if="tanquesDe(fuente).length"
            class="mt-4 grid gap-3"
            :class="tanquesDe(fuente).length === 1 ? 'sm:grid-cols-1' : 'sm:grid-cols-2'"
          >
            <article
              v-for="tanque in tanquesDe(fuente)"
              :key="tanque.numero"
              class="flex items-center gap-4 rounded-lg border border-border bg-surface p-4"
            >
              <div
                class="relative flex shrink-0 items-end overflow-hidden rounded-t-2xl rounded-b-md border-2 border-primary/45 bg-surface-muted"
                :class="tanquesDe(fuente).length === 1 ? 'h-36 w-20' : 'h-28 w-16'"
                role="meter"
                :aria-label="`${tanque.etiqueta}: ${formatLitros(tanque.valor)} litros, ${porcentajeTanque(tanque) === null ? 'sin porcentaje' : `${porcentajeTanque(tanque)}% de capacidad de referencia`}`"
                aria-valuemin="0"
                :aria-valuemax="tanque.capacidad"
                :aria-valuenow="tanque.valor === null || !Number.isFinite(tanque.valor) ? undefined : Math.max(0, Math.min(tanque.capacidad, tanque.valor))"
              >
                <div
                  v-if="porcentajeTanque(tanque) !== null"
                  class="absolute inset-x-0 bottom-0 bg-primary/75 transition-[height] duration-500"
                  :style="{ height: `${porcentajeTanque(tanque)}%` }"
                />
                <span class="relative z-10 mb-1 w-full text-center text-[11px] font-bold text-ink">
                  {{ porcentajeTanque(tanque) === null ? '—' : `${porcentajeTanque(tanque)}%` }}
                </span>
              </div>
              <div class="min-w-0 flex-1">
                <p class="text-xs font-bold uppercase tracking-wide text-ink-muted">{{ tanque.etiqueta }}</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-ink">
                  {{ formatLitros(tanque.valor) }}
                  <span class="text-xs font-medium text-ink-muted">l</span>
                </p>
                <p class="mt-1 text-sm text-ink-muted">Capacidad de referencia: {{ tanque.capacidad }} l</p>
              </div>
            </article>
          </div>

          <div v-else-if="nivelTanqueTotal(fuente) !== null" class="mt-4 rounded-lg border border-border bg-surface p-4 sm:p-5">
            <div class="flex flex-wrap items-end justify-between gap-3">
              <div>
                <p class="text-xs font-bold uppercase tracking-wide text-ink-muted">Nivel total</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-ink sm:text-3xl">
                  {{ formatLitros(fuente.fuelLiters) }}
                  <span class="text-base font-semibold text-ink-muted">/ {{ capacidadTanqueTotal(fuente) }} l</span>
                </p>
                <p class="mt-1 text-sm text-ink-muted">Capacidad de referencia</p>
              </div>
              <p class="text-2xl font-bold tabular-nums text-ink sm:text-3xl">{{ nivelTanqueTotal(fuente) }}%</p>
            </div>
            <div
              class="mt-4 h-9 overflow-hidden rounded-full border border-border bg-surface-muted"
              role="meter"
              :aria-label="`Combustible total: ${formatLitros(fuente.fuelLiters)} de ${capacidadTanqueTotal(fuente)} litros de referencia, ${nivelTanqueTotal(fuente)}%`"
              aria-valuemin="0"
              :aria-valuemax="capacidadTanqueTotal(fuente)"
              :aria-valuenow="Math.max(0, Math.min(capacidadTanqueTotal(fuente), Number(fuente.fuelLiters)))"
            >
              <div
                class="h-full rounded-full bg-primary/75 transition-[width] duration-500"
                :style="{ width: `${nivelTanqueTotal(fuente)}%` }"
              />
            </div>
          </div>

          <p v-else class="mt-4 rounded-lg border border-dashed border-border px-3 py-4 text-sm text-ink-muted">
            Sin lectura de combustible en la última señal.
          </p>
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

  </div>
</template>
