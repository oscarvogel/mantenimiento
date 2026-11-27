<script setup>
import CountUp from '../../../components/CountUp.vue'

defineProps({
  title: { type: String, required: true },
  count: { type: [Number, String], default: null },
  flush: { type: Boolean, default: false },
  allowOverflow: { type: Boolean, default: false },
})
</script>

<template>
  <!-- Rediseño de interiores: tarjeta sin borde, esquinas grandes y sombra suave. -->
  <section :class="['min-w-0 rounded-card bg-surface-raised shadow-card', allowOverflow ? 'overflow-visible' : 'overflow-hidden']">
    <header class="flex flex-wrap items-center justify-between gap-3 px-4 pt-4 sm:px-6 sm:pt-5">
      <h2 class="min-w-0 break-words text-base font-bold text-ink sm:text-lg">{{ title }}</h2>
      <span v-if="count !== null" class="rounded-full bg-surface-muted px-2.5 py-1 text-xs font-bold text-ink-muted">
        <CountUp v-if="Number.isFinite(Number(count))" :value="Number(count)" />
        <template v-else>{{ count }}</template>
      </span>
      <slot name="header-actions"></slot>
    </header>
    <div :class="flush ? 'pt-3' : 'p-4 sm:px-6 sm:pb-6'"><slot></slot></div>
    <slot name="footer"></slot>
  </section>
</template>
