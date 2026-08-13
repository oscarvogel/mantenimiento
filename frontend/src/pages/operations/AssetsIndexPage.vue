<script setup>
import { CalendarDaysIcon, ChevronDownIcon, FunnelIcon, PlusIcon, QrCodeIcon, Squares2X2Icon, WrenchScrewdriverIcon } from '@heroicons/vue/24/outline'
import { computed, ref, watch } from 'vue'
import CsrfInput from './components/CsrfInput.vue'
import EmptyState from './components/EmptyState.vue'
import FormField from './components/FormField.vue'
import PageHeading from './components/PageHeading.vue'
import PaginationBar from './components/PaginationBar.vue'
import PanelCard from './components/PanelCard.vue'
import StatusBadge from './components/StatusBadge.vue'
import EquipmentThumbnail from './components/EquipmentThumbnail.vue'
import { dangerButton, fieldClass, nowLocal, primaryButton, secondaryButton, today } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })
const selectedTypeId = ref(String(props.data.old.tipo_equipo_id || props.data.catalogs.types.find((item) => item.active)?.id || ''))
const selectedBrandId = ref(String(props.data.old.marca_id || ''))
const selectedModelId = ref(String(props.data.old.modelo_id || ''))
const selectedType = computed(() => props.data.catalogs.types.find((type) => String(type.id) === selectedTypeId.value) ?? null)
const compatibleModels = computed(() => props.data.catalogs.models.filter((model) => (
  model.active && String(model.brandId) === selectedBrandId.value && String(model.typeId) === selectedTypeId.value
)))
watch([selectedTypeId, selectedBrandId], () => { selectedModelId.value = '' })
</script>

<template>
  <div>
    <PageHeading eyebrow="Registro de activos" title="Equipos" description="Consultá la flota y accedé a la ficha completa de cada unidad.">
      <template #actions>
        <div class="flex flex-wrap gap-2">
          <a v-if="data.canEdit" href="#alta-equipo" :class="primaryButton"><PlusIcon class="mr-2 size-5" aria-hidden="true" />Nuevo equipo</a>
          <a :href="data.routes.maintenance" :class="secondaryButton"><WrenchScrewdriverIcon class="mr-2 size-5" aria-hidden="true" />Circuito preventivo</a>
        </div>
      </template>
    </PageHeading>

    <PanelCard title="Listado de equipos" :count="data.equipment.total" flush class="mb-6">
      <form method="get" :action="data.routes.index" class="grid gap-4 border-b border-border-subtle p-5 sm:grid-cols-2 sm:p-6 xl:grid-cols-5">
        <input type="hidden" name="per_page" :value="data.filters.perPage" />
        <input type="hidden" name="brand_page" :value="data.management.brands.pagination.page" />
        <input type="hidden" name="brand_per_page" :value="data.management.brands.pagination.perPage" />
        <input type="hidden" name="model_page" :value="data.management.models.pagination.page" />
        <input type="hidden" name="model_per_page" :value="data.management.models.pagination.perPage" />
        <FormField label="Buscar" for-id="asset-filter-q"><input id="asset-filter-q" name="q" maxlength="100" :value="data.filters.q" placeholder="Código, patente o chasis" :class="fieldClass" /></FormField>
        <FormField label="Tipo" for-id="asset-filter-type"><select id="asset-filter-type" name="tipo_id" :class="fieldClass"><option value="">Todos</option><option v-for="type in data.catalogs.types" :key="type.id" :value="type.id" :selected="String(data.filters.typeId) === String(type.id)">{{ type.name }}</option></select></FormField>
        <FormField label="Marca" for-id="asset-filter-brand"><select id="asset-filter-brand" name="marca_id" :class="fieldClass"><option value="">Todas</option><option v-for="brand in data.catalogs.brands.filter((item) => item.active)" :key="brand.id" :value="brand.id" :selected="String(data.filters.brandId) === String(brand.id)">{{ brand.name }}</option></select></FormField>
        <FormField label="Sucursal ID" for-id="asset-filter-branch"><input id="asset-filter-branch" name="sucursal_id" type="number" min="1" :value="data.filters.branchId" :class="fieldClass" /></FormField>
        <FormField label="Estado" for-id="asset-filter-status"><select id="asset-filter-status" name="estado" :class="fieldClass"><option value="">Todos</option><option value="ACTIVO" :selected="data.filters.status === 'ACTIVO'">Activo</option><option value="BAJA" :selected="data.filters.status === 'BAJA'">Baja</option></select></FormField>
        <div class="flex flex-wrap gap-2 sm:col-span-2 xl:col-span-5"><button type="submit" :class="primaryButton"><FunnelIcon class="mr-2 size-5" aria-hidden="true" />Aplicar filtros</button><a :href="data.routes.index" :class="secondaryButton">Limpiar</a></div>
      </form>

      <EmptyState v-if="data.equipment.items.length === 0" title="No encontramos equipos" description="Probá cambiar o limpiar los filtros aplicados." />
      <template v-else>
        <div class="hidden overflow-x-auto lg:block">
          <table class="w-full min-w-[62rem] text-left text-sm">
            <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted"><tr><th class="px-6 py-3">Equipo</th><th class="px-6 py-3">Ficha técnica</th><th class="px-6 py-3">Sucursal</th><th class="px-6 py-3">Uso actual</th><th class="px-6 py-3">Estado</th><th class="px-6 py-3 text-right">Acciones</th></tr></thead>
            <tbody class="divide-y divide-border-subtle"><tr v-for="equipment in data.equipment.items" :key="equipment.id" class="hover:bg-brand-50/60"><td class="px-6 py-4"><div class="flex items-center gap-3"><EquipmentThumbnail :url="equipment.photoUrl" :code="equipment.code" /><div><strong class="text-ink">{{ equipment.code }}</strong><br><span class="text-xs text-ink-muted">{{ equipment.typeName }}<template v-if="equipment.plate"> · {{ equipment.plate }}</template></span></div></div></td><td class="px-6 py-4 text-ink">{{ equipment.brandName || 'Sin marca' }}<template v-if="equipment.modelName"> · {{ equipment.modelName }}</template><br><span class="text-xs text-ink-muted">{{ equipment.year || 'Año sin informar' }}</span></td><td class="px-6 py-4 text-ink-muted">{{ equipment.branchCode }} · {{ equipment.branchName }}</td><td class="px-6 py-4 text-ink"><span>{{ equipment.currentKm === null ? '—' : `${equipment.currentKm} km` }}</span><br><span>{{ equipment.currentHours === null ? '—' : `${equipment.currentHours} h` }}</span></td><td class="px-6 py-4"><StatusBadge :status="equipment.status" /></td><td class="px-6 py-4"><div class="flex justify-end gap-2"><a :href="equipment.detailUrl" :class="secondaryButton">Ficha</a><a :href="equipment.qrUrl" target="_blank" rel="noopener" :class="secondaryButton"><QrCodeIcon class="mr-1.5 size-4" aria-hidden="true" />QR</a><a v-if="equipment.assignPlanUrl" :href="equipment.assignPlanUrl" :class="secondaryButton"><CalendarDaysIcon class="mr-1.5 size-4" aria-hidden="true" />Asignar plan</a></div></td></tr></tbody>
          </table>
        </div>
        <ul class="divide-y divide-border-subtle lg:hidden"><li v-for="equipment in data.equipment.items" :key="equipment.id" class="p-5"><div class="flex items-start justify-between gap-3"><div class="flex gap-3"><EquipmentThumbnail :url="equipment.photoUrl" :code="equipment.code" size="lg" /><div><p class="font-bold text-ink">{{ equipment.code }}</p><p class="mt-1 text-sm text-ink-muted">{{ equipment.typeName }} · {{ equipment.branchName }}</p></div></div><StatusBadge :status="equipment.status" /></div><dl class="mt-4 grid grid-cols-2 gap-3 text-sm"><div><dt class="text-xs text-ink-muted">Ficha</dt><dd class="mt-1 font-medium text-ink">{{ equipment.brandName || 'Sin marca' }} {{ equipment.modelName || '' }}</dd></div><div><dt class="text-xs text-ink-muted">Uso</dt><dd class="mt-1 font-medium text-ink">{{ equipment.currentKm === null ? '—' : `${equipment.currentKm} km` }}<br>{{ equipment.currentHours === null ? '—' : `${equipment.currentHours} h` }}</dd></div></dl><div class="mt-4 flex gap-2"><a :href="equipment.detailUrl" :class="`${secondaryButton} flex-1`">Ver ficha</a><a v-if="equipment.assignPlanUrl" :href="equipment.assignPlanUrl" :class="`${secondaryButton} flex-1`">Asignar plan</a><a :href="equipment.qrUrl" target="_blank" rel="noopener" :class="secondaryButton" aria-label="Abrir código QR"><QrCodeIcon class="size-5" aria-hidden="true" /></a></div></li></ul>
      </template>
      <template #footer><PaginationBar :pagination="data.equipment.pagination" /></template>
    </PanelCard>

    <details v-if="data.canEdit" id="alta-equipo" class="ui-details-animated group mb-6 scroll-mt-6 overflow-hidden rounded-xl border border-border bg-surface-raised shadow-card">
      <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4 sm:px-6">
        <span>
          <span class="block font-bold text-ink">Registrar un nuevo equipo</span>
          <span class="mt-1 block text-sm font-normal text-ink-muted">Abrí este panel únicamente cuando necesites dar de alta una unidad.</span>
        </span>
        <ChevronDownIcon class="size-5 shrink-0 text-ink-muted transition-transform group-open:rotate-180" aria-hidden="true" />
      </summary>
      <div class="border-t border-border-subtle p-5 sm:p-6">
        <form v-if="data.catalogs.branches.length" method="post" :action="data.routes.createEquipment" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <CsrfInput :csrf="data.csrf" />
          <FormField label="Sucursal" for-id="new-equipment-branch"><select id="new-equipment-branch" name="sucursal_id" required :class="fieldClass"><option v-for="branch in data.catalogs.branches" :key="branch.id" :value="branch.id" :selected="String(data.old.sucursal_id) === String(branch.id)">{{ branch.code }} · {{ branch.name }}</option></select></FormField>
          <FormField label="Tipo de equipo" for-id="new-equipment-type"><select id="new-equipment-type" v-model="selectedTypeId" name="tipo_equipo_id" required :class="fieldClass"><option v-for="type in data.catalogs.types.filter((item) => item.active)" :key="type.id" :value="String(type.id)">{{ type.name }}</option></select></FormField>
          <FormField label="Código interno" for-id="new-equipment-code"><input id="new-equipment-code" name="codigo" maxlength="50" required :value="data.old.codigo" class="uppercase" :class="fieldClass" /></FormField>
          <FormField label="Patente" for-id="new-equipment-plate"><input id="new-equipment-plate" name="patente" maxlength="20" :value="data.old.patente" class="uppercase" :class="fieldClass" /></FormField>
          <FormField label="Marca" for-id="new-equipment-brand"><select id="new-equipment-brand" v-model="selectedBrandId" name="marca_id" :class="fieldClass"><option value="">Sin informar</option><option v-for="brand in data.catalogs.brands.filter((item) => item.active)" :key="brand.id" :value="String(brand.id)">{{ brand.name }}</option></select></FormField>
          <FormField label="Modelo" for-id="new-equipment-model"><select id="new-equipment-model" v-model="selectedModelId" name="modelo_id" :disabled="!selectedBrandId || !selectedTypeId" :class="fieldClass"><option value="">{{ selectedBrandId && selectedTypeId ? 'Sin informar' : 'Seleccioná marca y tipo' }}</option><option v-for="model in compatibleModels" :key="model.id" :value="String(model.id)">{{ model.name }}</option></select></FormField>
          <FormField label="Fecha de alta" for-id="new-equipment-date"><input id="new-equipment-date" type="date" name="fecha_alta" required :value="data.old.fecha_alta || today()" :max="today()" :class="fieldClass" /></FormField>
          <FormField label="Año" for-id="new-equipment-year"><input id="new-equipment-year" type="number" min="1900" max="9999" name="anio" :value="data.old.anio" :class="fieldClass" /></FormField>
          <FormField label="Chasis" for-id="new-equipment-chassis"><input id="new-equipment-chassis" name="chasis" maxlength="100" :value="data.old.chasis" class="uppercase" :class="fieldClass" /></FormField>
          <FormField label="Motor" for-id="new-equipment-engine"><input id="new-equipment-engine" name="motor" maxlength="100" :value="data.old.motor" class="uppercase" :class="fieldClass" /></FormField>
          <FormField v-if="selectedType?.controlsKm" label="Kilometraje actual (opcional)" for-id="new-equipment-current-km" hint="Es una lectura actual; no se usa como base histórica del plan."><input id="new-equipment-current-km" type="number" min="0" name="km_actual_inicial" :value="data.old.km_actual_inicial" :class="fieldClass" /></FormField>
          <FormField v-if="selectedType?.controlsHours" label="Horómetro actual (opcional)" for-id="new-equipment-current-hours" hint="Es una lectura actual; no se usa como base histórica del plan."><input id="new-equipment-current-hours" type="number" min="0" step="0.1" name="horas_actuales_inicial" :value="data.old.horas_actuales_inicial" :class="fieldClass" /></FormField>
          <FormField v-if="selectedType?.controlsKm || selectedType?.controlsHours" label="Fecha de lectura actual" for-id="new-equipment-reading-date"><input id="new-equipment-reading-date" type="datetime-local" name="fecha_lectura_inicial" :value="data.old.fecha_lectura_inicial || nowLocal()" :class="fieldClass" /></FormField>
          <FormField label="Observaciones" for-id="new-equipment-notes" class="sm:col-span-2"><textarea id="new-equipment-notes" name="observaciones" rows="3" :value="data.old.observaciones" :class="fieldClass"></textarea></FormField>
          <div class="flex items-end"><button type="submit" :class="primaryButton">Crear equipo</button></div>
        </form>
        <EmptyState v-else title="No hay sucursales disponibles" description="Necesitás una sucursal activa y autorizada para registrar equipos." />
      </div>
    </details>

    <details v-if="data.canEdit" class="ui-details-animated group overflow-hidden rounded-xl border border-border bg-surface-raised shadow-card">
      <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4 sm:px-6">
        <span class="flex items-center gap-3">
          <span class="rounded-lg bg-brand-50 p-2 text-primary"><Squares2X2Icon class="size-5" aria-hidden="true" /></span>
          <span><span class="block font-bold text-ink">Catálogos de equipos</span><span class="mt-1 block text-sm font-normal text-ink-muted">Administrá marcas y modelos sin ocupar el flujo operativo diario.</span></span>
        </span>
        <ChevronDownIcon class="size-5 shrink-0 text-ink-muted transition-transform group-open:rotate-180" aria-hidden="true" />
      </summary>
      <section class="grid gap-6 border-t border-border-subtle p-5 sm:p-6 xl:grid-cols-2">
      <PanelCard title="Marcas" :count="data.management.brands.total">
        <form method="post" :action="data.routes.createBrand" class="mb-5 flex flex-col gap-2 sm:flex-row">
          <CsrfInput :csrf="data.csrf" /><label class="sr-only" for="new-brand">Nueva marca</label><input id="new-brand" name="nombre" maxlength="100" required placeholder="Nueva marca" :class="fieldClass" /><button type="submit" :class="primaryButton">Crear marca</button>
        </form>
        <EmptyState v-if="data.management.brands.items.length === 0" title="No hay marcas" />
        <ul v-else class="divide-y divide-border-subtle"><li v-for="brand in data.management.brands.items" :key="brand.id" class="py-4 first:pt-0"><form method="post" :action="brand.updateUrl" class="flex gap-2"><CsrfInput :csrf="data.csrf" /><label class="sr-only" :for="`brand-${brand.id}`">Nombre de marca</label><input :id="`brand-${brand.id}`" name="nombre" maxlength="100" required :value="brand.name" :disabled="!brand.active" :class="fieldClass" /><button type="submit" :disabled="!brand.active" :class="secondaryButton">Guardar</button></form><form v-if="brand.active" method="post" :action="brand.inactivateUrl" data-confirm data-confirm-title="¿Inactivar la marca?" data-confirm-text="La marca dejará de estar disponible para nuevos equipos." data-confirm-button="Inactivar" data-confirm-danger="true" class="mt-2"><CsrfInput :csrf="data.csrf" /><button type="submit" :class="dangerButton">Inactivar {{ brand.name }}</button></form><StatusBadge v-else status="BAJA" /></li></ul>
        <template #footer><PaginationBar :pagination="data.management.brands.pagination" /></template>
      </PanelCard>

      <PanelCard title="Modelos" :count="data.management.models.total">
        <form method="post" :action="data.routes.createModel" class="mb-5 grid gap-3 sm:grid-cols-2">
          <CsrfInput :csrf="data.csrf" /><FormField label="Marca" for-id="new-model-brand"><select id="new-model-brand" name="marca_id" required :class="fieldClass"><option v-for="brand in data.catalogs.brands.filter((item) => item.active)" :key="brand.id" :value="brand.id">{{ brand.name }}</option></select></FormField><FormField label="Tipo de equipo" for-id="new-model-type"><select id="new-model-type" name="tipo_equipo_id" required :class="fieldClass"><option v-for="type in data.catalogs.types.filter((item) => item.active)" :key="type.id" :value="type.id">{{ type.name }}</option></select></FormField><FormField label="Nombre" for-id="new-model-name"><input id="new-model-name" name="nombre" maxlength="100" required placeholder="Modelo" :class="fieldClass" /></FormField><button type="submit" :class="`${primaryButton} self-end`">Crear modelo</button>
        </form>
        <EmptyState v-if="data.management.models.items.length === 0" title="No hay modelos" />
        <ul v-else class="divide-y divide-border-subtle"><li v-for="model in data.management.models.items" :key="model.id" class="py-4 first:pt-0"><p class="mb-2 text-xs text-ink-muted">{{ model.brandName }} · {{ model.typeName }}</p><form method="post" :action="model.updateUrl" class="flex gap-2"><CsrfInput :csrf="data.csrf" /><label class="sr-only" :for="`model-${model.id}`">Nombre de modelo</label><input :id="`model-${model.id}`" name="nombre" maxlength="100" required :value="model.name" :disabled="!model.active" :class="fieldClass" /><button type="submit" :disabled="!model.active" :class="secondaryButton">Guardar</button></form><form v-if="model.active" method="post" :action="model.inactivateUrl" data-confirm data-confirm-title="¿Inactivar el modelo?" data-confirm-text="El modelo dejará de estar disponible para nuevos equipos." data-confirm-button="Inactivar" data-confirm-danger="true" class="mt-2"><CsrfInput :csrf="data.csrf" /><button type="submit" :class="dangerButton">Inactivar</button></form><StatusBadge v-else status="BAJA" /></li></ul>
        <template #footer><PaginationBar :pagination="data.management.models.pagination" /></template>
      </PanelCard>
      </section>
    </details>
  </div>
</template>
