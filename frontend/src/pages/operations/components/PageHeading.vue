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
  <header class="mb-6 flex flex-wrap items-end justify-between gap-x-8 gap-y-4 border-b border-border-subtle pb-5 sm:mb-7 sm:pb-6">
    <div class="min-w-0">
      <a v-if="back" :href="back.href" class="mb-2 inline-flex min-h-11 items-center gap-1 rounded-md text-sm font-semibold text-primary hover:text-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-border-focus">
        <ArrowLeftIcon class="size-4" aria-hidden="true" />{{ back.label }}
      </a>
      <p v-if="eyebrow" class="mb-1 text-xs font-semibold text-ink-muted">{{ eyebrow }}</p>
      <h1 class="break-words text-3xl font-bold tracking-tight text-ink sm:text-4xl">{{ title }}</h1>
      <p v-if="description" class="mt-1.5 max-w-prose text-sm leading-6 text-ink-muted">{{ description }}</p>
    </div>
    <div v-if="$slots.actions" class="flex max-w-full flex-wrap items-center gap-2"><slot name="actions" /></div>
  </header>
</template>
