<script setup>
import { computed } from 'vue'

/**
 * Km que faltan para el próximo service, una barra por plan, alrededor del cero:
 * a la derecha lo que falta, a la izquierda lo ya pasado. El color es el estado
 * del plan (los colores de estado del sistema) y el estado va escrito al lado.
 *
 * Se usa el km estimado por GPS cuando existe y, si no, el de la lectura. La
 * escala se recorta: un odómetro corrupto (millones de km) no puede aplastar al
 * resto de la flota; esa barra se dibuja hasta el borde y lo dice.
 */
const props = defineProps({
  planes: { type: Array, default: () => [] },
  formatKm: { type: Function, required: true },
  limit: { type: Number, default: 15 },
  tope: { type: Number, default: 40000 },
})

const etiquetas = { AL_DIA: 'Al día', PROXIMO: 'Próximo', VENCIDO: 'Vencido', SIN_DATOS: 'Sin datos' }
const color = (estado) => ({
  AL_DIA: 'rgb(var(--maintenance-ok))',
  PROXIMO: 'rgb(var(--maintenance-due))',
  VENCIDO: 'rgb(var(--maintenance-overdue))',
}[estado] ?? 'rgb(var(--maintenance-inactive))')

const filas = computed(() => props.planes
  .map((plan) => {
    const fuente = plan.estimado ?? plan.real ?? {}
    return {
      key: plan.planId,
      label: `${plan.patente ?? plan.codigo} · ${plan.servicio}`,
      km: fuente.kmRestantes,
      estado: fuente.estado ?? 'SIN_DATOS',
      estimado: Boolean(plan.estimado),
    }
  })
  .filter((fila) => fila.km !== null && fila.km !== undefined)
  .sort((a, b) => a.km - b.km))
const visibles = computed(() => filas.value.slice(0, props.limit))
const escala = computed(() => Math.min(props.tope, Math.max(1, ...visibles.value.map((fila) => Math.abs(fila.km)))))
const ancho = (km) => Math.max(1, (Math.min(Math.abs(km), escala.value) / escala.value) * 50)
const recortada = (km) => Math.abs(km) > escala.value
const texto = (fila) => (fila.km < 0 ? `Pasado por ${props.formatKm(Math.abs(fila.km))}` : `Faltan ${props.formatKm(fila.km)}`)
</script>

<template>
  <article id="fleet-board-service-chart" class="rounded-xl border border-border bg-surface-raised p-5 sm:p-6">
    <h3 class="text-base font-bold text-ink">Km para el próximo service</h3>
    <p class="mt-1 text-sm leading-6 text-ink-muted">
      Los más urgentes primero. A la izquierda del cero, services ya pasados. Se usa el km estimado por GPS cuando hay; si no, el de la última lectura.
    </p>

    <ul v-if="visibles.length" class="mt-4 space-y-3" aria-label="Km para el próximo service por plan">
      <li v-for="fila in visibles" :key="fila.key" :title="`${fila.label}: ${texto(fila)} (${etiquetas[fila.estado] ?? fila.estado}${fila.estimado ? ', estimado por GPS' : ''})`">
        <div class="mb-1 flex items-baseline justify-between gap-3 text-sm">
          <span class="min-w-0 truncate font-medium text-ink">{{ fila.label }}</span>
          <span class="shrink-0 text-xs tabular-nums text-ink-muted">
            {{ texto(fila) }} · {{ etiquetas[fila.estado] ?? fila.estado }}<template v-if="fila.estimado"> · GPS</template>
          </span>
        </div>
        <div class="relative h-2 rounded-full bg-surface-muted" aria-hidden="true">
          <span class="absolute inset-y-[-3px] left-1/2 w-px bg-border-strong"></span>
          <span
            class="absolute inset-y-0"
            :class="[fila.km < 0 ? 'rounded-l-full' : 'rounded-r-full', recortada(fila.km) ? 'opacity-80' : '']"
            :style="{
              backgroundColor: color(fila.estado),
              width: `${ancho(fila.km)}%`,
              left: fila.km < 0 ? `${50 - ancho(fila.km)}%` : '50%',
            }"
          ></span>
        </div>
        <p v-if="recortada(fila.km)" class="mt-0.5 text-[11px] text-ink-muted">Fuera de escala: la barra está recortada.</p>
      </li>
    </ul>
    <p v-else role="status" class="mt-4 rounded-lg bg-surface-subtle px-4 py-5 text-sm text-ink-muted">No hay planes con km para graficar.</p>

    <ul class="mt-4 flex flex-wrap gap-4 text-xs text-ink-muted">
      <li v-for="estado in ['VENCIDO', 'PROXIMO', 'AL_DIA']" :key="estado" class="inline-flex items-center gap-2">
        <span class="size-2.5 rounded-full" :style="{ backgroundColor: color(estado) }" aria-hidden="true"></span>{{ etiquetas[estado] }}
      </li>
    </ul>
  </article>
</template>
