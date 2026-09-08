<script setup>
import { ArrowLeftIcon, CheckCircleIcon, ExclamationTriangleIcon, XCircleIcon } from '@heroicons/vue/24/outline'
import CsrfInput from './components/CsrfInput.vue'
import PageHeading from './components/PageHeading.vue'
import PanelCard from './components/PanelCard.vue'
import { secondaryButton } from './helpers.js'

defineProps({ data: { type: Object, required: true } })

function statusClass(status) {
  if (status === 'OK') return 'text-success-strong'
  if (status === 'ADVERTENCIA') return 'text-warning-strong'
  return 'text-danger-strong'
}

function statusIcon(status) {
  if (status === 'OK') return CheckCircleIcon
  if (status === 'ADVERTENCIA') return ExclamationTriangleIcon
  return XCircleIcon
}
</script>

<template>
  <div>
    <PageHeading
      eyebrow="Importaciones"
      title="Vista previa de choferes"
      description="Esta pantalla no modifica la base. Revisá cómo se resolverá cada móvil y cada chofer antes de confirmar una importación."
    />

    <div class="mb-5">
      <a :href="data.routes.back" :class="secondaryButton">
        <ArrowLeftIcon class="mr-2 size-4" aria-hidden="true" />
        Volver a importaciones
      </a>
    </div>

    <PanelCard title="Resumen" class="mb-6">
      <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <div><p class="text-xs uppercase tracking-wide text-ink-muted">Archivo</p><p class="mt-1 font-semibold text-ink">{{ data.header.originalFile }}</p></div>
        <div><p class="text-xs uppercase tracking-wide text-ink-muted">Filas</p><p class="mt-1 text-2xl font-semibold text-ink">{{ data.header.totalRows }}</p></div>
        <div><p class="text-xs uppercase tracking-wide text-ink-muted">OK</p><p class="mt-1 text-2xl font-semibold text-success-strong">{{ data.header.okRows }}</p></div>
        <div><p class="text-xs uppercase tracking-wide text-ink-muted">Advertencias</p><p class="mt-1 text-2xl font-semibold text-warning-strong">{{ data.header.warningRows }}</p></div>
        <div><p class="text-xs uppercase tracking-wide text-ink-muted">Errores</p><p class="mt-1 text-2xl font-semibold text-danger-strong">{{ data.header.errorRows }}</p></div>
      </div>
    </PanelCard>

    <PanelCard title="Resultado por fila" :count="data.rows.length" flush>
      <div class="overflow-x-auto">
        <table class="w-full min-w-[68rem] text-left text-sm">
          <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted">
            <tr>
              <th class="px-5 py-3">Origen</th>
              <th class="px-5 py-3">Patente</th>
              <th class="px-5 py-3">Chofer</th>
              <th class="px-5 py-3">Estado</th>
              <th class="px-5 py-3">Acción propuesta</th>
              <th class="px-5 py-3">Detalle</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-border-subtle">
            <tr v-for="row in data.rows" :key="`${row.sheet}-${row.rowNumber}`">
              <td class="px-5 py-4"><span class="font-medium text-ink">{{ row.sheet }}</span><br><span class="text-xs text-ink-muted">Fila {{ row.rowNumber }} · {{ row.brand }}</span></td>
              <td class="px-5 py-4"><span class="font-semibold text-ink">{{ row.plate }}</span><br><span class="text-xs text-ink-muted">{{ row.normalizedPlate }}</span></td>
              <td class="px-5 py-4">{{ row.driverName || 'Sin chofer informado' }}</td>
              <td class="px-5 py-4">
                <span class="inline-flex items-center gap-1.5 font-medium" :class="statusClass(row.status)">
                  <component :is="statusIcon(row.status)" class="size-4" aria-hidden="true" />
                  {{ row.status }}
                </span>
              </td>
              <td class="px-5 py-4 font-medium text-ink">{{ row.action }}</td>
              <td class="px-5 py-4 text-ink-muted">
                <span v-if="row.message">{{ row.message }}</span>
                <span v-else-if="row.employeeId">Empleado #{{ row.employeeId }} · Móvil #{{ row.equipmentId }}</span>
                <span v-else-if="row.equipmentId">Móvil #{{ row.equipmentId }}</span>
                <span v-else>Requiere revisión</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </PanelCard>

    <PanelCard title="Confirmación" class="mt-6">
      <div v-if="data.canConfirm" class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-ink-muted">
          No hay errores ni advertencias. Al confirmar se crearán los empleados faltantes y se actualizarán las asignaciones de chofer.
        </p>
        <form method="post" :action="data.routes.confirm">
          <CsrfInput :csrf="data.csrf" />
          <input type="hidden" name="preview_token" :value="data.previewToken" />
          <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
            Confirmar importación
          </button>
        </form>
      </div>
      <div v-else class="flex items-start gap-3 rounded-lg border border-warning-200 bg-warning-50 p-4 text-sm text-warning-strong">
        <ExclamationTriangleIcon class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
        <p>La importación no puede confirmarse mientras existan errores o empleados ambiguos.</p>
      </div>
    </PanelCard>
  </div>
</template>
