<script setup>
import { ChevronLeftIcon, ChevronRightIcon } from '@heroicons/vue/20/solid'

defineProps({ pagination: { type: Object, required: true } })
</script>

<template>
  <nav v-if="pagination.totalPages > 1" aria-label="Paginación" class="flex items-center justify-between gap-3 border-t border-border-subtle px-5 py-4 text-sm sm:px-6">
    <a v-if="pagination.previousUrl" :href="pagination.previousUrl" class="inline-flex min-h-10 items-center gap-1 rounded-lg border border-border px-3 font-semibold text-ink hover:bg-surface-muted">
      <ChevronLeftIcon class="size-4" aria-hidden="true" />Anterior
    </a>
    <span v-else class="inline-flex min-h-10 cursor-not-allowed items-center gap-1 rounded-lg border border-border-subtle px-3 text-ink-subtle" aria-disabled="true"><ChevronLeftIcon class="size-4" aria-hidden="true" />Anterior</span>
    <span class="text-center text-xs text-ink-muted sm:text-sm">Página {{ pagination.page }} de {{ pagination.totalPages }}<template v-if="pagination.total !== undefined"> · {{ pagination.total }} registros</template></span>
    <a v-if="pagination.nextUrl" :href="pagination.nextUrl" class="inline-flex min-h-10 items-center gap-1 rounded-lg border border-border px-3 font-semibold text-ink hover:bg-surface-muted">
      Siguiente<ChevronRightIcon class="size-4" aria-hidden="true" />
    </a>
    <span v-else class="inline-flex min-h-10 cursor-not-allowed items-center gap-1 rounded-lg border border-border-subtle px-3 text-ink-subtle" aria-disabled="true">Siguiente<ChevronRightIcon class="size-4" aria-hidden="true" /></span>
  </nav>
</template>
