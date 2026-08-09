<script setup>
import { WrenchScrewdriverIcon } from '@heroicons/vue/24/outline'

defineProps({
  compact: {
    type: Boolean,
    default: false,
  },
})
</script>

<template>
  <div class="flex min-w-0 items-center gap-3">
    <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-accent text-accent-foreground">
      <WrenchScrewdriverIcon class="size-6" aria-hidden="true" />
    </span>
    <div v-if="!compact" class="min-w-0">
      <p class="truncate text-base font-bold tracking-tight text-ink-inverse">Mantenimiento</p>
      <p class="truncate text-xs font-medium text-brand-200">Gestión de flota</p>
    </div>
  </div>
</template>
