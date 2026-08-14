<script setup>
import { ChevronLeftIcon, ChevronRightIcon } from '@heroicons/vue/20/solid'
import { pageSizeUrl } from './pagination.js'

defineProps({ pagination: { type: Object, required: true } })

function changePageSize(event, pagination) {
  window.location.assign(pageSizeUrl(pagination, event.target.value))
}
</script>

<template>
  <nav v-if="pagination.totalPages > 1 || pagination.perPage" aria-label="Paginación" class="grid grid-cols-2 items-center gap-3 border-t border-border-subtle px-5 py-4 text-sm sm:flex sm:flex-wrap sm:justify-between sm:px-6">
    <a v-if="pagination.previousUrl" :href="pagination.previousUrl" class="inline-flex min-h-10 items-center justify-center gap-1 rounded-lg border border-border px-3 font-semibold text-ink hover:bg-surface-muted sm:justify-start">
      <ChevronLeftIcon class="size-4" aria-hidden="true" />Anterior
    </a>
    <span v-else class="inline-flex min-h-10 cursor-not-allowed items-center justify-center gap-1 rounded-lg border border-border-subtle px-3 text-ink-subtle sm:justify-start" aria-disabled="true"><ChevronLeftIcon class="size-4" aria-hidden="true" />Anterior</span>
    <span class="col-span-2 row-start-1 text-center text-xs text-ink-muted sm:col-auto sm:row-auto sm:text-sm">Página {{ pagination.page }} de {{ pagination.totalPages }}<template v-if="pagination.total !== undefined"> · {{ pagination.total }} registros</template></span>
    <label v-if="pagination.perPage" class="col-span-2 flex min-h-10 items-center justify-center gap-2 text-xs font-semibold text-ink-muted sm:col-auto sm:justify-start sm:text-sm">
      Mostrar
      <select :value="pagination.perPage" class="min-h-10 rounded-lg border border-border bg-white px-2 text-ink" aria-label="Registros por página" @change="changePageSize($event, pagination)">
        <option v-for="size in (pagination.perPageOptions || [5, 10, 25])" :key="size" :value="size">{{ size }}</option>
      </select>
    </label>
    <a v-if="pagination.nextUrl" :href="pagination.nextUrl" class="inline-flex min-h-10 items-center justify-center gap-1 rounded-lg border border-border px-3 font-semibold text-ink hover:bg-surface-muted sm:justify-start">
      Siguiente<ChevronRightIcon class="size-4" aria-hidden="true" />
    </a>
    <span v-else class="inline-flex min-h-10 cursor-not-allowed items-center justify-center gap-1 rounded-lg border border-border-subtle px-3 text-ink-subtle sm:justify-start" aria-disabled="true">Siguiente<ChevronRightIcon class="size-4" aria-hidden="true" /></span>
  </nav>
</template>
