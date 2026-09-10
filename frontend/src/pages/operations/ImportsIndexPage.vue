<script setup>
import { ArrowDownTrayIcon, ArrowUpTrayIcon, EyeIcon, WrenchScrewdriverIcon } from '@heroicons/vue/24/outline'
import CsrfInput from './components/CsrfInput.vue'
import EmptyState from './components/EmptyState.vue'
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
    <PageHeading eyebrow="Importaciones" title="Equipos, unidades, lecturas y vencimientos" description="Validá el archivo, revisá cada fila y confirmá la persistencia sólo cuando el resultado sea correcto." />

    <PanelCard v-if="data.canUpload" title="Nueva importación" class="mb-6">
      <div class="space-y-5">
        <section class="rounded-xl border border-border-subtle bg-surface-subtle/60 p-4 sm:p-5" aria-labelledby="import-templates-title">
          <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
            <div>
              <h3 id="import-templates-title" class="text-sm font-bold text-ink">Plantillas disponibles</h3>
              <p class="mt-1 max-w-2xl text-sm text-ink-muted">Descargá una estructura lista para completar antes de validar una carga.</p>
            </div>
            <div class="flex flex-wrap gap-2 xl:justify-end">
              <a :href="data.routes.templates.equipment" :class="secondaryButton"><ArrowDownTrayIcon class="mr-2 size-4" aria-hidden="true" />Plantilla de equipos</a>
              <a :href="data.routes.templates.readings" :class="secondaryButton"><ArrowDownTrayIcon class="mr-2 size-4" aria-hidden="true" />Plantilla de lecturas</a>
              <a :href="`${data.routes.upload}/plantilla/BIBLIOTECA_PREVENTIVA`" :class="secondaryButton"><ArrowDownTrayIcon class="mr-2 size-4" aria-hidden="true" />Plantilla general de camiones</a>
              <a :href="`${data.routes.upload}/biblioteca`" :class="secondaryButton"><WrenchScrewdriverIcon class="mr-2 size-4" aria-hidden="true" />Ver biblioteca preventiva</a>
            </div>
          </div>
        </section>

        <section class="rounded-xl border border-border-subtle bg-surface-subtle/40 p-4 sm:p-5" aria-labelledby="import-file-title">
          <div class="mb-4">
            <h3 id="import-file-title" class="text-sm font-bold text-ink">Validar archivo</h3>
            <p class="mt-1 text-sm text-ink-muted">Elegí el tipo de información y cargá el archivo correspondiente.</p>
          </div>
          <form method="post" enctype="multipart/form-data" :action="data.routes.upload" class="grid gap-4 lg:grid-cols-[minmax(13rem,16rem)_minmax(0,1fr)_auto] lg:items-start">
            <CsrfInput :csrf="data.csrf" />
            <FormField label="Tipo de importación" for-id="import-type">
              <select id="import-type" name="tipo" :class="fieldClass">
                <option value="EQUIPOS">Equipos</option>
                <option value="UNIDADES_TRANSPORTE">Unidades de transporte TSA</option>
                <option value="LECTURAS">Lecturas</option>
                <option value="VENCIMIENTOS">Vencimientos TSA (móviles y choferes)</option>
                <option value="BIBLIOTECA_PREVENTIVA">Biblioteca preventiva</option>
              </select>
            </FormField>
            <FormField label="Archivo CSV o XLSX" for-id="import-file" :hint="`Máximo ${data.maxSizeMb} MB y 5.000 filas. Unidades TSA, vencimientos TSA y biblioteca preventiva requieren XLSX.`">
              <input id="import-file" type="file" name="archivo" accept=".csv,.xlsx" required :class="fieldClass" />
            </FormField>
            <button type="submit" :class="`${primaryButton} lg:mt-6 lg:min-w-40`"><ArrowUpTrayIcon class="mr-2 size-5" aria-hidden="true" />Validar archivo</button>
          </form>
        </section>

        <section class="rounded-xl border border-border-subtle bg-surface-subtle/40 p-4 sm:p-5" aria-labelledby="driver-import-title">
          <div class="mb-4">
            <h3 id="driver-import-title" class="text-sm font-bold text-ink">Choferes por móvil</h3>
            <p class="mt-1 text-sm text-ink-muted">Revisá la asignación de cada chofer a su móvil mediante un archivo XLSX.</p>
          </div>
          <form method="post" enctype="multipart/form-data" :action="data.routes.driverAssignmentsPreview" class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-start">
            <CsrfInput :csrf="data.csrf" />
            <FormField label="Archivo XLSX" for-id="drivers-file" hint="Lee hojas como Argentina/Brasil y busca cada móvil por patente. Primero muestra una vista previa: no modifica datos.">
              <input id="drivers-file" type="file" name="archivo_choferes" accept=".xlsx" required :class="fieldClass" />
            </FormField>
            <button type="submit" :class="`${primaryButton} lg:mt-6 lg:min-w-40`"><ArrowUpTrayIcon class="mr-2 size-5" aria-hidden="true" />Revisar choferes</button>
          </form>
        </section>
      </div>
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
