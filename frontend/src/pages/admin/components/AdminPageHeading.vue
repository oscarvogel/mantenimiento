<script setup>
defineProps({
  eyebrow: {
    type: String,
    default: '',
  },
  title: {
    type: String,
    required: true,
  },
  description: {
    type: String,
    required: true,
  },
})
</script>

<template>
  <header class="mb-7 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
    <div class="max-w-3xl">
      <p v-if="eyebrow" class="text-sm font-semibold text-primary">{{ eyebrow }}</p>
      <h1 class="mt-1 text-2xl font-bold tracking-tight text-ink sm:text-3xl">{{ title }}</h1>
      <p class="mt-2 text-sm leading-6 text-ink-muted sm:text-base">{{ description }}</p>
    </div>
    <slot name="aside"></slot>
  </header>
</template>
