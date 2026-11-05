<script setup>
import { ArrowTopRightOnSquareIcon, ClockIcon, LockClosedIcon } from '@heroicons/vue/24/outline'

defineProps({
  entries: { type: Array, default: () => [] },
})

const stateLabel = (state) => ({
  available: 'Disponible',
  not_implemented: 'Pendiente',
  restricted: 'Restringido',
}[state] ?? 'Pendiente')

const stateTone = (state) => ({
  available: 'bg-success-subtle text-success-strong',
  not_implemented: 'bg-surface-muted text-ink-muted',
  restricted: 'bg-warning-subtle text-warning-strong',
}[state] ?? 'bg-surface-muted text-ink-muted')
</script>

<template>
  <section aria-labelledby="report-catalog-title" class="rounded-xl border border-border bg-surface-raised">
    <div class="border-b border-border px-5 py-4 sm:px-6">
      <h2 id="report-catalog-title" class="text-lg font-bold text-ink">Informes y consultas</h2>
      <p class="mt-1 max-w-3xl text-sm leading-6 text-ink-muted">Sólo se habilitan los informes que podés consultar con tu empresa y tus permisos. Las categorías pendientes no abren una pantalla vacía.</p>
    </div>
    <ul v-if="entries.length" class="grid gap-3 p-4 sm:grid-cols-2 sm:p-5 xl:grid-cols-3">
      <li v-for="entry in entries" :key="entry.key">
        <a
          v-if="entry.state === 'available' && entry.url"
          :href="entry.url"
          class="group flex h-full min-h-32 flex-col rounded-lg border border-border-strong bg-surface-raised p-4 transition hover:border-primary hover:bg-surface-subtle focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-border-focus"
        >
          <span class="flex items-start justify-between gap-3">
            <span class="text-base font-semibold text-ink group-hover:text-primary">{{ entry.label }}</span>
            <span class="rounded-full px-2.5 py-1 text-xs font-semibold" :class="stateTone(entry.state)">{{ stateLabel(entry.state) }}</span>
          </span>
          <span class="mt-2 text-sm leading-6 text-ink-muted">{{ entry.description }}</span>
          <span class="mt-auto flex items-center gap-1.5 pt-3 text-sm font-semibold text-primary">Abrir informe<ArrowTopRightOnSquareIcon class="size-4" aria-hidden="true" /></span>
        </a>
        <div v-else class="flex h-full min-h-32 flex-col rounded-lg border border-border bg-surface-muted p-4">
          <span class="flex items-start justify-between gap-3">
            <span class="text-base font-semibold text-ink">{{ entry.label }}</span>
            <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold" :class="stateTone(entry.state)">
              <LockClosedIcon v-if="entry.state === 'restricted'" class="size-3.5" aria-hidden="true" />
              <ClockIcon v-else class="size-3.5" aria-hidden="true" />
              {{ stateLabel(entry.state) }}
            </span>
          </span>
          <span class="mt-2 text-sm leading-6 text-ink-muted">{{ entry.description }}</span>
        </div>
      </li>
    </ul>
    <p v-else role="status" class="px-5 py-8 text-center text-sm text-ink-muted">No hay informes habilitados para tu cuenta.</p>
  </section>
</template>
