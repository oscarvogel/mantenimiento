<script setup>
import { computed, ref } from 'vue'

/**
 * Línea de tiempo del período con los cortes de cada proveedor de telemetría.
 * El tramo con datos va en verde y el corte en ámbar: el estado se lee también
 * por la leyenda y por el texto de cada corte, nunca solo por el color.
 */
const props = defineProps({
  desde: { type: String, default: null },
  hasta: { type: String, default: null },
  cortes: { type: Array, default: () => [] },
  formatDate: { type: Function, required: true },
})

const instante = (value) => {
  const parsed = new Date(String(value ?? '').replace(' ', 'T'))
  return Number.isNaN(parsed.getTime()) ? null : parsed.getTime()
}
const inicio = computed(() => instante(props.desde))
const fin = computed(() => instante(props.hasta))
const valido = computed(() => inicio.value !== null && fin.value !== null && fin.value > inicio.value)
const posicion = (value) => {
  const t = instante(value)
  if (t === null || !valido.value) return 0
  return Math.min(100, Math.max(0, ((t - inicio.value) / (fin.value - inicio.value)) * 100))
}

const pistas = computed(() => {
  const porProveedor = new Map()
  props.cortes.forEach((corte, indice) => {
    const nombre = corte.integracion ?? corte.proveedor ?? 'Proveedor'
    if (!porProveedor.has(nombre)) porProveedor.set(nombre, [])
    const izquierda = posicion(corte.desde)
    const derecha = corte.sigueAbierto ? 100 : posicion(corte.hasta)
    porProveedor.get(nombre).push({
      key: `${nombre}-${indice}`,
      izquierda,
      ancho: Math.max(0.8, derecha - izquierda),
      texto: `${nombre}: corte del ${props.formatDate(corte.desde)} al ${corte.sigueAbierto ? 'presente (sigue abierto)' : props.formatDate(corte.hasta)} · ${corte.horas ?? '—'} h · ${corte.unidadesAfectadas ?? '—'} de ${corte.unidadesTotales ?? '—'} unidades`,
    })
  })
  return [...porProveedor.entries()].map(([nombre, bandas]) => ({ nombre, bandas }))
})

/** 'YYYY-MM-DD HH:mm' en la hora local del navegador, como llegan las fechas del servidor. */
const local = (fecha) => {
  const dos = (n) => String(n).padStart(2, '0')
  return `${fecha.getFullYear()}-${dos(fecha.getMonth() + 1)}-${dos(fecha.getDate())} ${dos(fecha.getHours())}:${dos(fecha.getMinutes())}`
}

const marcas = computed(() => {
  if (!valido.value) return []
  return [0, 0.25, 0.5, 0.75, 1].map((fraccion) => ({
    izquierda: fraccion * 100,
    texto: props.formatDate(local(new Date(inicio.value + fraccion * (fin.value - inicio.value)))),
  }))
})

const tooltip = ref(null)
const mostrar = (pista, banda, evento) => {
  const caja = evento.currentTarget.parentElement.getBoundingClientRect()
  const marca = evento.currentTarget.getBoundingClientRect()
  tooltip.value = { pista: pista.nombre, texto: banda.texto, izquierda: Math.min(Math.max(marca.left - caja.left + marca.width / 2, 80), caja.width - 80) }
}
</script>

<template>
  <article id="fleet-board-timeline" class="rounded-xl border border-border bg-surface-raised p-5 sm:p-6">
    <h3 class="text-base font-bold text-ink">Datos recibidos en el período</h3>
    <p class="mt-1 text-sm leading-6 text-ink-muted">
      Cada franja ámbar es un corte del proveedor: horas en que no llegaron datos de la mayoría de las unidades.
    </p>

    <div v-if="valido" class="mt-4">
      <div v-if="pistas.length" class="space-y-4">
        <div v-for="pista in pistas" :key="pista.nombre">
          <p class="mb-1 text-xs font-semibold text-ink-muted">{{ pista.nombre }}</p>
          <div class="relative h-6 rounded-md bg-success/70" @mouseleave="tooltip = null">
            <button
              v-for="banda in pista.bandas"
              :key="banda.key"
              type="button"
              class="absolute inset-y-0 rounded-md border-x-2 border-surface-raised bg-warning focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
              :style="{ left: `${banda.izquierda}%`, width: `${banda.ancho}%` }"
              :aria-label="banda.texto"
              @mouseenter="mostrar(pista, banda, $event)"
              @focus="mostrar(pista, banda, $event)"
              @blur="tooltip = null"
            ></button>
            <div
              v-if="tooltip && tooltip.pista === pista.nombre"
              role="tooltip"
              class="pointer-events-none absolute bottom-full z-10 mb-2 w-max max-w-64 -translate-x-1/2 rounded-lg border border-border bg-surface-raised px-3 py-2 text-xs leading-5 text-ink shadow-card"
              :style="{ left: `${tooltip.izquierda}px` }"
            >{{ tooltip.texto }}</div>
          </div>
        </div>
      </div>
      <div v-else class="h-6 rounded-md bg-success/70" role="img" aria-label="Sin cortes del proveedor en el período"></div>

      <div class="relative mt-2 h-4 text-[11px] text-ink-muted" aria-hidden="true">
        <span
          v-for="(marca, indice) in marcas"
          :key="indice"
          class="absolute whitespace-nowrap"
          :class="indice === 0 ? '' : indice === marcas.length - 1 ? '-translate-x-full' : '-translate-x-1/2'"
          :style="{ left: `${marca.izquierda}%` }"
        >{{ marca.texto }}</span>
      </div>

      <ul class="mt-3 flex flex-wrap gap-4 text-xs text-ink-muted">
        <li class="inline-flex items-center gap-2"><span class="size-2.5 rounded-sm bg-success/70" aria-hidden="true"></span>Datos recibidos</li>
        <li class="inline-flex items-center gap-2"><span class="size-2.5 rounded-sm bg-warning" aria-hidden="true"></span>Corte del proveedor</li>
      </ul>
    </div>
    <p v-else role="status" class="mt-4 rounded-lg bg-surface-subtle px-4 py-5 text-sm text-ink-muted">Sin datos suficientes para dibujar el período.</p>
  </article>
</template>
