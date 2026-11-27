<script setup>
import { computed, ref } from 'vue'

/**
 * Resultado de la conciliación de combustible: una barra apilada con la
 * cantidad de viajes en cada estado. Cada segmento lleva su cantidad en la
 * leyenda, así que el estado no depende del color.
 */
const props = defineProps({
  porEstado: { type: Object, default: () => ({}) },
})

const estados = [
  { clave: 'CONCILIADO', etiqueta: 'Conciliado', clase: 'bg-success' },
  { clave: 'DECLARADO_MENOR', etiqueta: 'Declaró menos', clase: 'bg-warning' },
  { clave: 'DECLARADO_SUPERA', etiqueta: 'Declaró de más', clase: 'bg-danger' },
  { clave: 'EN_CURSO', etiqueta: 'En curso', clase: 'bg-info' },
  { clave: 'SIN_DATOS_SUFICIENTES', etiqueta: 'Sin datos suficientes', clase: 'bg-ink-subtle' },
]
const total = computed(() => estados.reduce((suma, e) => suma + (Number(props.porEstado?.[e.clave]) || 0), 0))
const segmentos = computed(() => estados
  .map((e) => ({ ...e, cantidad: Number(props.porEstado?.[e.clave]) || 0 }))
  .filter((e) => e.cantidad > 0)
  .map((e) => ({ ...e, ancho: (e.cantidad / total.value) * 100 })))
const activo = ref(null)
</script>

<template>
  <article id="fleet-board-fuel-chart" class="rounded-card bg-surface-raised p-5 sm:p-6 shadow-card">
    <h3 class="text-base font-bold text-ink">Resultado de la conciliación</h3>
    <p class="mt-1 text-sm leading-6 text-ink-muted">Viajes del período según lo que pudo comparar la telemetría.</p>

    <template v-if="total > 0">
      <div class="relative mt-4 flex h-6 gap-0.5 overflow-visible" role="img" :aria-label="segmentos.map((s) => `${s.etiqueta}: ${s.cantidad}`).join(', ')" @mouseleave="activo = null">
        <div
          v-for="(segmento, indice) in segmentos"
          :key="segmento.clave"
          class="h-full"
          :class="[segmento.clase, indice === 0 ? 'rounded-l-md' : '', indice === segmentos.length - 1 ? 'rounded-r-md' : '']"
          :style="{ width: `${segmento.ancho}%` }"
          @mouseenter="activo = segmento"
        ></div>
        <div v-if="activo" role="tooltip" class="pointer-events-none absolute bottom-full left-1/2 z-10 mb-2 -translate-x-1/2 whitespace-nowrap rounded-card bg-surface-raised px-3 py-2 text-xs text-ink shadow-card">
          {{ activo.etiqueta }}: {{ activo.cantidad }} de {{ total }} viajes
        </div>
      </div>
      <ul class="mt-3 flex flex-wrap gap-x-4 gap-y-2 text-xs text-ink-muted">
        <li v-for="segmento in segmentos" :key="segmento.clave" class="inline-flex items-center gap-2">
          <span class="size-2.5 rounded-sm" :class="segmento.clase" aria-hidden="true"></span>{{ segmento.etiqueta }}: <strong class="font-semibold text-ink">{{ segmento.cantidad }}</strong>
        </li>
      </ul>
    </template>
    <p v-else role="status" class="mt-4 rounded-lg bg-surface-subtle px-4 py-5 text-sm text-ink-muted">No hay viajes en el período.</p>
  </article>
</template>
