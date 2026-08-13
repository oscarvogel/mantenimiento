<script setup>
import { ArrowRightIcon } from '@heroicons/vue/20/solid'
import MaintenanceStatus from './MaintenanceStatus.vue'
import EmptyMaintenance from './EmptyMaintenance.vue'

defineProps({
  items: {
    type: Array,
    required: true,
  },
  maintenanceUrl: {
    type: String,
    required: true,
  },
})
</script>

<template>
  <section aria-labelledby="upcoming-title" class="overflow-hidden rounded-xl border border-border bg-surface-raised">
    <div class="flex items-center justify-between gap-4 border-b border-border-subtle px-5 py-4 sm:px-6">
      <div>
        <h2 id="upcoming-title" class="text-base font-bold text-ink sm:text-lg">Mantenimientos próximos</h2>
        <p class="mt-0.5 text-sm text-ink-muted">Prioridades de servicio de la flota</p>
      </div>
      <a
        v-if="maintenanceUrl !== '#'"
        :href="maintenanceUrl"
        class="hidden items-center gap-1 text-sm font-semibold text-primary hover:text-primary-hover sm:inline-flex"
      >
        Ver todos
        <ArrowRightIcon class="size-4" aria-hidden="true" />
      </a>
    </div>

    <EmptyMaintenance v-if="items.length === 0" :action-url="maintenanceUrl" />

    <template v-else>
      <div class="hidden overflow-x-auto md:block">
        <table class="w-full min-w-[44rem] border-collapse text-left">
          <caption class="sr-only">Mantenimientos próximos y vencidos por equipo</caption>
          <thead>
            <tr class="bg-surface-subtle text-xs font-semibold uppercase tracking-wide text-ink-muted">
              <th scope="col" class="px-6 py-3">Equipo</th>
              <th scope="col" class="px-6 py-3">Servicio</th>
              <th scope="col" class="px-6 py-3">Sucursal</th>
              <th scope="col" class="px-6 py-3">Restante</th>
              <th scope="col" class="px-6 py-3">Estado</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border-subtle">
            <tr v-for="item in items" :key="item.id" class="group hover:bg-brand-50/70">
              <th scope="row" class="px-6 py-4 text-sm font-semibold text-ink">{{ item.equipment }}</th>
              <td class="px-6 py-4 text-sm text-ink-muted">{{ item.service }}</td>
              <td class="px-6 py-4 text-sm text-ink-muted">{{ item.branch }}</td>
              <td class="whitespace-nowrap px-6 py-4 text-sm font-medium text-ink">{{ item.remaining }}</td>
              <td class="px-6 py-4">
                <MaintenanceStatus :status="item.tone" :label="item.statusLabel" />
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <ul class="divide-y divide-border-subtle md:hidden">
        <li v-for="item in items" :key="item.id">
          <article class="px-5 py-4">
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-ink">{{ item.equipment }}</p>
                <p class="mt-1 line-clamp-2 text-sm text-ink-muted">{{ item.service }}</p>
              </div>
              <MaintenanceStatus :status="item.tone" :label="item.statusLabel" />
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs">
              <span class="font-semibold text-ink">{{ item.remaining }}</span>
              <span class="text-ink-muted">{{ item.branch }}</span>
            </div>
          </article>
        </li>
      </ul>

      <div v-if="maintenanceUrl !== '#'" class="border-t border-border-subtle p-4 sm:hidden">
        <a
          :href="maintenanceUrl"
          class="flex min-h-11 items-center justify-center rounded-lg bg-primary-subtle px-4 text-sm font-semibold text-primary hover:bg-brand-100"
        >
          Ver todos los mantenimientos
        </a>
      </div>
    </template>
  </section>
</template>
