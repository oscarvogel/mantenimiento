<script setup>
import { ArrowDownTrayIcon } from '@heroicons/vue/24/outline'
import PageHeading from './components/PageHeading.vue'
import PanelCard from './components/PanelCard.vue'
import EmptyState from './components/EmptyState.vue'
import StatusBadge from './components/StatusBadge.vue'
import { secondaryButton } from './helpers.js'

defineProps({ data: { type: Object, required: true } })
</script>

<template>
  <div>
    <PageHeading
      eyebrow="Mantenimiento preventivo"
      title="Biblioteca preventiva"
      description="Servicios, tareas, materiales sugeridos y plantillas disponibles para tu empresa."
      :back="{ label: 'Volver a importaciones', href: data.routes.back }"
    />

    <div class="mb-6">
      <a :href="data.routes.downloadTemplate" :class="secondaryButton">
        <ArrowDownTrayIcon class="mr-2 size-4" aria-hidden="true" />Descargar plantilla general de camiones
      </a>
    </div>

    <section class="mb-6 grid gap-3 sm:grid-cols-2">
      <article class="rounded-xl border border-border bg-white p-5 shadow-card">
        <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Plantillas</p>
        <p class="mt-2 text-3xl font-bold text-ink">{{ data.templates.length }}</p>
      </article>
      <article class="rounded-xl border border-border bg-white p-5 shadow-card">
        <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Servicios</p>
        <p class="mt-2 text-3xl font-bold text-ink">{{ data.services.length }}</p>
      </article>
    </section>

    <PanelCard title="Plantillas de la empresa" :count="data.templates.length" flush class="mb-6">
      <EmptyState v-if="data.templates.length === 0" title="Todavía no hay plantillas" description="Importá la biblioteca preventiva desde Excel para crear la primera plantilla." />
      <div v-else class="overflow-x-auto">
        <table class="w-full min-w-[46rem] text-left text-sm">
          <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted">
            <tr><th class="px-6 py-3">Código</th><th class="px-6 py-3">Plantilla</th><th class="px-6 py-3">Aplica a</th><th class="px-6 py-3">Servicios</th><th class="px-6 py-3">Estado</th></tr>
          </thead>
          <tbody class="divide-y divide-border-subtle">
            <tr v-for="item in data.templates" :key="item.id">
              <td class="px-6 py-4 font-mono text-xs font-semibold text-ink">{{ item.code }}</td>
              <td class="px-6 py-4"><p class="font-semibold text-ink">{{ item.name }}</p><p class="text-xs text-ink-muted">{{ item.scope }}</p></td>
              <td class="px-6 py-4 text-ink-muted">{{ item.equipmentType }}<span v-if="item.brand"> · {{ item.brand }}</span><span v-if="item.model"> {{ item.model }}</span></td>
              <td class="px-6 py-4 font-semibold text-ink">{{ item.itemCount }}</td>
              <td class="px-6 py-4"><StatusBadge :status="item.active ? 'ACTIVO' : 'INACTIVO'" /></td>
            </tr>
          </tbody>
        </table>
      </div>
    </PanelCard>

    <PanelCard title="Catálogo de servicios" :count="data.services.length" flush>
      <EmptyState v-if="data.services.length === 0" title="Todavía no hay servicios" description="Los servicios importados aparecerán acá con su cantidad de tareas y materiales sugeridos." />
      <div v-else class="overflow-x-auto">
        <table class="w-full min-w-[48rem] text-left text-sm">
          <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted">
            <tr><th class="px-6 py-3">Código</th><th class="px-6 py-3">Servicio</th><th class="px-6 py-3">Categoría</th><th class="px-6 py-3">Tareas</th><th class="px-6 py-3">Materiales</th><th class="px-6 py-3">Estado</th></tr>
          </thead>
          <tbody class="divide-y divide-border-subtle">
            <tr v-for="service in data.services" :key="service.id">
              <td class="px-6 py-4 font-mono text-xs font-semibold text-ink">{{ service.code }}</td>
              <td class="px-6 py-4 font-semibold text-ink">{{ service.name }}</td>
              <td class="px-6 py-4 text-ink-muted">{{ service.category || 'Sin categoría' }}</td>
              <td class="px-6 py-4 text-ink">{{ service.taskCount }}</td>
              <td class="px-6 py-4 text-ink">{{ service.materialCount }}</td>
              <td class="px-6 py-4"><StatusBadge :status="service.active ? 'ACTIVO' : 'INACTIVO'" /></td>
            </tr>
          </tbody>
        </table>
      </div>
    </PanelCard>
  </div>
</template>
