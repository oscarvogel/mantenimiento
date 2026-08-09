<script setup>
import { ArrowDownTrayIcon, ArrowUpTrayIcon, EyeIcon } from '@heroicons/vue/24/outline'
import CsrfInput from './components/CsrfInput.vue'
import EmptyState from './components/EmptyState.vue'
import FlashMessages from './components/FlashMessages.vue'
import FormField from './components/FormField.vue'
import PageHeading from './components/PageHeading.vue'
import PaginationBar from './components/PaginationBar.vue'
import PanelCard from './components/PanelCard.vue'
import StatusBadge from './components/StatusBadge.vue'
import { fieldClass, primaryButton, secondaryButton } from './helpers.js'

defineProps({ data: { type: Object, required: true } })
</script>

<template>
  <div>
    <PageHeading eyebrow="Importaciones" title="Equipos y lecturas" description="Validá el archivo, revisá cada fila y confirmá la persistencia sólo cuando el resultado sea correcto." />
    <FlashMessages :flash="data.flash" />

    <PanelCard v-if="data.canUpload" title="Nueva importación" class="mb-6">
      <div class="mb-5 flex flex-wrap gap-2">
        <a :href="data.routes.templates.equipment" :class="secondaryButton"><ArrowDownTrayIcon class="mr-2 size-4" aria-hidden="true" />Plantilla de equipos</a>
        <a :href="data.routes.templates.readings" :class="secondaryButton"><ArrowDownTrayIcon class="mr-2 size-4" aria-hidden="true" />Plantilla de lecturas</a>
      </div>
      <form method="post" enctype="multipart/form-data" :action="data.routes.upload" class="grid gap-4 md:grid-cols-[14rem_1fr_auto] md:items-end">
        <CsrfInput :csrf="data.csrf" />
        <FormField label="Tipo" for-id="import-type">
          <select id="import-type" name="tipo" :class="fieldClass"><option value="EQUIPOS">Equipos</option><option value="LECTURAS">Lecturas</option></select>
        </FormField>
        <FormField label="Archivo CSV o XLSX" for-id="import-file" :hint="`Máximo ${data.maxSizeMb} MB y 5.000 filas.`">
          <input id="import-file" type="file" name="archivo" accept=".csv,.xlsx" required :class="fieldClass" />
        </FormField>
        <button type="submit" :class="primaryButton"><ArrowUpTrayIcon class="mr-2 size-5" aria-hidden="true" />Validar archivo</button>
      </form>
    </PanelCard>

    <PanelCard title="Historial de importaciones" :count="data.imports.total" flush>
      <EmptyState v-if="data.imports.items.length === 0" title="Todavía no hay importaciones" description="Las cargas dentro de tu alcance aparecerán acá." />
      <template v-else>
        <div class="hidden overflow-x-auto md:block">
          <table class="w-full min-w-[52rem] text-left text-sm">
            <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted"><tr><th class="px-6 py-3">Fecha</th><th class="px-6 py-3">Archivo</th><th class="px-6 py-3">Resultado</th><th class="px-6 py-3">Estado</th><th class="px-6 py-3"><span class="sr-only">Acción</span></th></tr></thead>
            <tbody class="divide-y divide-border-subtle">
              <tr v-for="item in data.imports.items" :key="item.id" class="hover:bg-brand-50/60">
                <td class="px-6 py-4"><span class="font-medium text-ink">{{ item.date }}</span><br><span class="text-xs text-ink-muted">{{ item.userName || 'Usuario' }}</span></td>
                <td class="px-6 py-4"><span class="font-semibold text-ink">{{ item.originalFile }}</span><br><span class="text-xs text-ink-muted">{{ item.type }}</span></td>
                <td class="px-6 py-4 text-ink-muted"><span class="font-medium text-ink">{{ item.importedRows }}</span> importadas · <span class="text-danger-strong">{{ item.errorRows }} errores</span> · <span class="text-warning-strong">{{ item.duplicateRows }} duplicadas</span><p v-if="item.summary" class="mt-1 text-xs">{{ item.summary }}</p></td>
                <td class="px-6 py-4"><StatusBadge :status="item.status" /></td>
                <td class="px-6 py-4 text-right"><a :href="item.detailUrl" :class="secondaryButton"><EyeIcon class="mr-1.5 size-4" aria-hidden="true" />Ver detalle</a></td>
              </tr>
            </tbody>
          </table>
        </div>
        <ul class="divide-y divide-border-subtle md:hidden">
          <li v-for="item in data.imports.items" :key="item.id" class="p-5">
            <div class="flex items-start justify-between gap-3"><div class="min-w-0"><p class="truncate font-semibold text-ink">{{ item.originalFile }}</p><p class="mt-1 text-xs text-ink-muted">{{ item.date }} · {{ item.type }}</p></div><StatusBadge :status="item.status" /></div>
            <p class="mt-3 text-sm text-ink-muted">{{ item.importedRows }} importadas · {{ item.errorRows }} errores · {{ item.duplicateRows }} duplicadas</p>
            <a :href="item.detailUrl" :class="`${secondaryButton} mt-4 w-full`">Ver detalle</a>
          </li>
        </ul>
      </template>
      <template #footer><PaginationBar :pagination="data.imports.pagination" /></template>
    </PanelCard>
  </div>
</template>
