<script setup>
import { ArrowLeftIcon } from '@heroicons/vue/20/solid'

defineProps({
  eyebrow: { type: String, required: true },
  title: { type: String, required: true },
  description: { type: String, required: true },
  back: { type: Object, default: null },
})
</script>

<template>
  <header class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
    <div>
      <a v-if="back" :href="back.href" class="mb-3 inline-flex items-center gap-1 text-sm font-semibold text-primary hover:text-primary-hover">
        <ArrowLeftIcon class="size-4" aria-hidden="true" />{{ back.label }}
      </a>
      <p class="text-[0.6875rem] font-bold uppercase tracking-[0.08em] text-primary">{{ eyebrow }}</p>
      <h1 class="mt-1 text-2xl font-bold tracking-tight text-ink sm:text-3xl">{{ title }}</h1>
      <p class="mt-2 max-w-3xl text-sm leading-6 text-ink-muted">{{ description }}</p>
    </div>
    <slot name="actions"></slot>
  </header>
</template>
