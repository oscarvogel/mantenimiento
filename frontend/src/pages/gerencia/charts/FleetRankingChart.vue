<script setup>
import { computed } from 'vue'

/**
 * Ranking horizontal de una sola serie: una barra por camión, ordenada de mayor
 * a menor. El valor va escrito al lado (rótulo directo), así que la barra no
 * depende del color. Los camiones sin dato no se dibujan como cero: se listan
 * aparte con su nombre.
 */
const props = defineProps({
  id: { type: String, required: true },
  title: { type: String, required: true },
  description: { type: String, default: '' },
  items: { type: Array, default: () => [] },
  formatter: { type: Function, default: (value) => String(value) },
  emptyText: { type: String, default: 'Sin datos suficientes para este período.' },
  barClass: { type: String, default: 'bg-primary' },
  limit: { type: Number, default: 12 },
})

const conDato = computed(() => props.items
  .filter((item) => item.value !== null && item.value !== undefined && !Number.isNaN(Number(item.value)))
  .sort((a, b) => Number(b.value) - Number(a.value)))
const visibles = computed(() => conDato.value.slice(0, props.limit))
const ocultos = computed(() => conDato.value.length - visibles.value.length)
const sinDato = computed(() => props.items.filter((item) => item.value === null || item.value === undefined))
const maximo = computed(() => Math.max(0, ...visibles.value.map((item) => Number(item.value))))
const ancho = (value) => (maximo.value <= 0 || Number(value) <= 0 ? 0 : Math.max(2, (Number(value) / maximo.value) * 100))
</script>

<template>
  <article :id="id" class="rounded-card bg-surface-raised p-5 sm:p-6 shadow-card">
    <h3 class="text-base font-bold text-ink">{{ title }}</h3>
    <p v-if="description" class="mt-1 text-sm leading-6 text-ink-muted">{{ description }}</p>

    <ul v-if="visibles.length" class="mt-4 space-y-3" :aria-label="title">
      <li v-for="item in visibles" :key="item.key" :title="`${item.label}: ${formatter(item.value)}`">
        <div class="mb-1 flex items-baseline justify-between gap-3 text-sm">
          <span class="min-w-0 truncate font-medium text-ink">{{ item.label }}</span>
          <span class="shrink-0 tabular-nums text-ink-muted">{{ formatter(item.value) }}</span>
        </div>
        <div class="h-2 rounded-full bg-surface-muted" aria-hidden="true">
          <div class="h-full rounded-full" :class="barClass" :style="{ width: `${ancho(item.value)}%` }"></div>
        </div>
      </li>
    </ul>
    <p v-else role="status" class="mt-4 rounded-lg bg-surface-subtle px-4 py-5 text-sm text-ink-muted">{{ emptyText }}</p>

    <p v-if="ocultos > 0" class="mt-3 text-xs text-ink-muted">Y {{ ocultos }} unidades más con valores menores.</p>
    <p v-if="sinDato.length" class="mt-3 text-xs leading-5 text-ink-muted">
      Sin datos: {{ sinDato.map((item) => item.label).join(', ') }}.
    </p>
  </article>
</template>
