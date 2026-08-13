<script setup>
defineProps({
  title: { type: String, required: true },
  count: { type: [Number, String], default: null },
  flush: { type: Boolean, default: false },
})
</script>

<template>
  <section class="overflow-hidden rounded-xl border border-border bg-surface-raised">
    <header class="flex flex-wrap items-center justify-between gap-3 border-b border-border-subtle px-5 py-4 sm:px-6">
      <h2 class="text-base font-bold text-ink sm:text-lg">{{ title }}</h2>
      <span v-if="count !== null" class="rounded-full bg-surface-muted px-2.5 py-1 text-xs font-bold text-ink-muted">{{ count }}</span>
      <slot name="header-actions"></slot>
    </header>
    <div :class="flush ? '' : 'p-5 sm:p-6'"><slot></slot></div>
    <slot name="footer"></slot>
  </section>
</template>
