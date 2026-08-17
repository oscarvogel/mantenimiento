<script setup>
import { CheckCircleIcon, XCircleIcon } from '@heroicons/vue/24/outline'
import CsrfInput from './components/CsrfInput.vue'
import EmptyState from './components/EmptyState.vue'
import PageHeading from './components/PageHeading.vue'
import PaginationBar from './components/PaginationBar.vue'
import PanelCard from './components/PanelCard.vue'
import StatusBadge from './components/StatusBadge.vue'
import { dangerButton, formatNumberEs, primaryButton } from './helpers.js'

defineProps({ data: { type: Object, required: true } })
const labels = { codigo: 'Código interno', code: 'Código interno', patente: 'Patente', plate: 'Patente', sucursal_codigo: 'Sucursal', branch_code: 'Sucursal', tipo_equipo: 'Tipo de equipo', marca: 'Marca', modelo: 'Modelo', anio: 'Año', fecha_alta: 'Fecha de alta', registered_at: 'Fecha de alta', equipo_codigo: 'Equipo', equipo_code: 'Equipo', fecha_lectura: 'Fecha de lectura', recorded_at: 'Fecha de lectura', kilometraje: 'Kilometraje', kilometers: 'Kilometraje', horometro: 'Horómetro', hours: 'Horómetro' }
const humanRows = (row) => Object.entries(row.normalizedData ?? {}).filter(([key, value]) => labels[key] && value !== null && value !== '').map(([key, value]) => ({ label: labels[key], value: typeof value === 'number' ? formatNumberEs(value, key.includes('hora') || key === 'hours' ? 1 : 0) : value }))
const issueLabel = (field) => labels[field] ?? field
</script>

<template>
  <div>
    <PageHeading eyebrow="Vista previa" :title="data.header.originalFile" :description="`${data.header.type} · ${data.header.status}${data.header.summary ? ` · ${data.header.summary}` : ''}`" :back="{ label: 'Volver a importaciones', href: data.routes.back }" />

    <section aria-label="Resumen de validación" class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
      <article v-for="metric in [{label:'Total',value:data.header.totalRows,tone:'text-ink'},{label:'Válidas',value:data.header.validRows,tone:'text-success-strong'},{label:'Errores',value:data.header.errorRows,tone:'text-danger-strong'},{label:'Duplicadas',value:data.header.duplicateRows,tone:'text-warning-strong'}]" :key="metric.label" class="rounded-xl border border-border bg-white p-4 shadow-card sm:p-5">
        <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">{{ metric.label }}</p><p class="mt-2 text-2xl font-bold" :class="metric.tone">{{ metric.value }}</p>
      </article>
    </section>

    <div v-if="data.canMutate && data.header.status === 'BORRADOR_VALIDADO'" class="mb-6 flex flex-col gap-2 sm:flex-row">
      <form method="post" :action="data.routes.confirm" data-confirm data-confirm-title="¿Confirmar la importación?" data-confirm-text="Las filas válidas se persistirán en el sistema. Esta acción no se puede deshacer." data-confirm-button="Confirmar importación">
        <CsrfInput :csrf="data.csrf" /><button type="submit" :disabled="data.header.validRows === 0" :class="primaryButton"><CheckCircleIcon class="mr-2 size-5" aria-hidden="true" />Confirmar importación</button>
      </form>
      <form method="post" :action="data.routes.cancel" data-confirm data-confirm-title="¿Cancelar el borrador?" data-confirm-text="Las filas validades se descartarán sin persistir nada." data-confirm-button="Cancelar borrador" data-confirm-danger="true">
        <CsrfInput :csrf="data.csrf" /><button type="submit" :class="dangerButton"><XCircleIcon class="mr-2 size-5" aria-hidden="true" />Cancelar borrador</button>
      </form>
    </div>

    <PanelCard title="Filas validadas" :count="data.rows.total" flush>
      <EmptyState v-if="data.rows.items.length === 0" title="No hay filas visibles" description="No hay resultados dentro de tu alcance para esta página." />
      <template v-else>
        <div class="hidden overflow-x-auto md:block">
          <table class="w-full min-w-[50rem] text-left text-sm">
            <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted"><tr><th class="px-6 py-3">Fila</th><th class="px-6 py-3">Estado</th><th class="px-6 py-3">Datos normalizados</th><th class="px-6 py-3">Errores / resultado</th></tr></thead>
            <tbody class="divide-y divide-border-subtle"><tr v-for="row in data.rows.items" :key="row.rowNumber"><td class="px-6 py-4 font-semibold text-ink">#{{ row.rowNumber }}</td><td class="px-6 py-4"><StatusBadge :status="row.status" /></td><td class="px-6 py-4"><dl class="grid gap-1 text-sm"> <div v-for="item in humanRows(row)" :key="item.label" class="flex gap-2"><dt class="font-semibold text-ink">{{ item.label }}:</dt><dd>{{ item.value }}</dd></div></dl><details class="mt-3"><summary class="cursor-pointer text-xs font-semibold text-primary">Ver datos técnicos</summary><pre class="mt-2 max-w-xl whitespace-pre-wrap break-words rounded-lg bg-surface-subtle p-3 text-xs text-ink">{{ JSON.stringify(row.normalizedData, null, 2) }}</pre></details></td><td class="px-6 py-4"><div v-for="issue in row.issues" :key="`${issue.field}-${issue.message}`" class="mb-2 text-sm text-danger-strong"><strong>{{ issueLabel(issue.field) }}:</strong> {{ issue.message }}<span v-if="issue.value"> · Valor recibido: {{ issue.value }}</span></div><p v-if="row.result" class="text-sm text-ink-muted">{{ row.result }}</p></td></tr></tbody>
          </table>
        </div>
        <ul class="divide-y divide-border-subtle md:hidden"><li v-for="row in data.rows.items" :key="row.rowNumber" class="p-5"><div class="flex items-center justify-between"><strong class="text-sm text-ink">Fila #{{ row.rowNumber }}</strong><StatusBadge :status="row.status" /></div><dl class="mt-3 grid gap-1 text-sm"><div v-for="item in humanRows(row)" :key="item.label" class="flex gap-2"><dt class="font-semibold">{{ item.label }}:</dt><dd>{{ item.value }}</dd></div></dl><details class="mt-3"><summary class="cursor-pointer text-xs font-semibold text-primary">Ver datos técnicos</summary><pre class="mt-2 whitespace-pre-wrap break-words rounded-lg bg-surface-subtle p-3 text-xs text-ink">{{ JSON.stringify(row.normalizedData, null, 2) }}</pre></details><div v-for="issue in row.issues" :key="`${issue.field}-${issue.message}`" class="mt-2 text-sm text-danger-strong"><strong>{{ issueLabel(issue.field) }}:</strong> {{ issue.message }}</div><p v-if="row.result" class="mt-2 text-sm text-ink-muted">{{ row.result }}</p></li></ul>
      </template>
      <template #footer><PaginationBar :pagination="data.rows.pagination" /></template>
    </PanelCard>
  </div>
</template>
