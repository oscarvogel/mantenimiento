<script setup>
import { FunnelIcon, QrCodeIcon, WrenchScrewdriverIcon } from '@heroicons/vue/24/outline'
import CsrfInput from './components/CsrfInput.vue'
import EmptyState from './components/EmptyState.vue'
import FlashMessages from './components/FlashMessages.vue'
import FormField from './components/FormField.vue'
import PageHeading from './components/PageHeading.vue'
import PaginationBar from './components/PaginationBar.vue'
import PanelCard from './components/PanelCard.vue'
import StatusBadge from './components/StatusBadge.vue'
import { dangerButton, fieldClass, primaryButton, secondaryButton } from './helpers.js'

defineProps({ data: { type: Object, required: true } })
</script>

<template>
  <div>
    <PageHeading eyebrow="Registro de activos" title="Equipos y catálogos" description="Buscá equipos, consultá su ficha técnica y administrá las marcas y modelos disponibles.">
      <template #actions><a :href="data.routes.maintenance" :class="secondaryButton"><WrenchScrewdriverIcon class="mr-2 size-5" aria-hidden="true" />Circuito preventivo</a></template>
    </PageHeading>
    <FlashMessages :flash="data.flash" />

    <PanelCard title="Listado de equipos" :count="data.equipment.total" flush class="mb-6">
      <form method="get" :action="data.routes.index" class="grid gap-4 border-b border-border-subtle p-5 sm:grid-cols-2 sm:p-6 xl:grid-cols-5">
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
            <tbody class="divide-y divide-border-subtle"><tr v-for="equipment in data.equipment.items" :key="equipment.id" class="hover:bg-brand-50/60"><td class="px-6 py-4"><strong class="text-ink">{{ equipment.code }}</strong><br><span class="text-xs text-ink-muted">{{ equipment.typeName }}<template v-if="equipment.plate"> · {{ equipment.plate }}</template></span></td><td class="px-6 py-4 text-ink">{{ equipment.brandName || 'Sin marca' }}<template v-if="equipment.modelName"> · {{ equipment.modelName }}</template><br><span class="text-xs text-ink-muted">{{ equipment.year || 'Año sin informar' }}</span></td><td class="px-6 py-4 text-ink-muted">{{ equipment.branchCode }} · {{ equipment.branchName }}</td><td class="px-6 py-4 text-ink"><span>{{ equipment.currentKm === null ? '—' : `${equipment.currentKm} km` }}</span><br><span>{{ equipment.currentHours === null ? '—' : `${equipment.currentHours} h` }}</span></td><td class="px-6 py-4"><StatusBadge :status="equipment.status" /></td><td class="px-6 py-4"><div class="flex justify-end gap-2"><a :href="equipment.detailUrl" :class="secondaryButton">Ficha</a><a :href="equipment.qrUrl" target="_blank" rel="noopener" :class="secondaryButton"><QrCodeIcon class="mr-1.5 size-4" aria-hidden="true" />QR</a></div></td></tr></tbody>
          </table>
        </div>
        <ul class="divide-y divide-border-subtle lg:hidden"><li v-for="equipment in data.equipment.items" :key="equipment.id" class="p-5"><div class="flex items-start justify-between gap-3"><div><p class="font-bold text-ink">{{ equipment.code }}</p><p class="mt-1 text-sm text-ink-muted">{{ equipment.typeName }} · {{ equipment.branchName }}</p></div><StatusBadge :status="equipment.status" /></div><dl class="mt-4 grid grid-cols-2 gap-3 text-sm"><div><dt class="text-xs text-ink-muted">Ficha</dt><dd class="mt-1 font-medium text-ink">{{ equipment.brandName || 'Sin marca' }} {{ equipment.modelName || '' }}</dd></div><div><dt class="text-xs text-ink-muted">Uso</dt><dd class="mt-1 font-medium text-ink">{{ equipment.currentKm === null ? '—' : `${equipment.currentKm} km` }}<br>{{ equipment.currentHours === null ? '—' : `${equipment.currentHours} h` }}</dd></div></dl><div class="mt-4 flex gap-2"><a :href="equipment.detailUrl" :class="`${secondaryButton} flex-1`">Ver ficha</a><a :href="equipment.qrUrl" target="_blank" rel="noopener" :class="secondaryButton" aria-label="Abrir código QR"><QrCodeIcon class="size-5" aria-hidden="true" /></a></div></li></ul>
      </template>
      <template #footer><PaginationBar :pagination="data.equipment.pagination" /></template>
    </PanelCard>

    <section v-if="data.canEdit" class="grid gap-6 xl:grid-cols-2">
      <PanelCard title="Marcas" :count="data.catalogs.brands.length">
        <form method="post" :action="data.routes.createBrand" class="mb-5 flex flex-col gap-2 sm:flex-row">
          <CsrfInput :csrf="data.csrf" /><label class="sr-only" for="new-brand">Nueva marca</label><input id="new-brand" name="nombre" maxlength="100" required placeholder="Nueva marca" :class="fieldClass" /><button type="submit" :class="primaryButton">Crear marca</button>
        </form>
        <EmptyState v-if="data.catalogs.brands.length === 0" title="No hay marcas" />
        <ul v-else class="divide-y divide-border-subtle"><li v-for="brand in data.catalogs.brands" :key="brand.id" class="py-4 first:pt-0"><form method="post" :action="brand.updateUrl" class="flex gap-2"><CsrfInput :csrf="data.csrf" /><label class="sr-only" :for="`brand-${brand.id}`">Nombre de marca</label><input :id="`brand-${brand.id}`" name="nombre" maxlength="100" required :value="brand.name" :disabled="!brand.active" :class="fieldClass" /><button type="submit" :disabled="!brand.active" :class="secondaryButton">Guardar</button></form><form v-if="brand.active" method="post" :action="brand.inactivateUrl" class="mt-2"><CsrfInput :csrf="data.csrf" /><button type="submit" :class="dangerButton">Inactivar {{ brand.name }}</button></form><StatusBadge v-else status="BAJA" /></li></ul>
      </PanelCard>

      <PanelCard title="Modelos" :count="data.catalogs.models.length">
        <form method="post" :action="data.routes.createModel" class="mb-5 grid gap-3 sm:grid-cols-2">
          <CsrfInput :csrf="data.csrf" /><FormField label="Marca" for-id="new-model-brand"><select id="new-model-brand" name="marca_id" required :class="fieldClass"><option v-for="brand in data.catalogs.brands.filter((item) => item.active)" :key="brand.id" :value="brand.id">{{ brand.name }}</option></select></FormField><FormField label="Tipo de equipo" for-id="new-model-type"><select id="new-model-type" name="tipo_equipo_id" required :class="fieldClass"><option v-for="type in data.catalogs.types.filter((item) => item.active)" :key="type.id" :value="type.id">{{ type.name }}</option></select></FormField><FormField label="Nombre" for-id="new-model-name"><input id="new-model-name" name="nombre" maxlength="100" required placeholder="Modelo" :class="fieldClass" /></FormField><button type="submit" :class="`${primaryButton} self-end`">Crear modelo</button>
        </form>
        <EmptyState v-if="data.catalogs.models.length === 0" title="No hay modelos" />
        <ul v-else class="divide-y divide-border-subtle"><li v-for="model in data.catalogs.models" :key="model.id" class="py-4 first:pt-0"><p class="mb-2 text-xs text-ink-muted">{{ model.brandName }} · {{ model.typeName }}</p><form method="post" :action="model.updateUrl" class="flex gap-2"><CsrfInput :csrf="data.csrf" /><label class="sr-only" :for="`model-${model.id}`">Nombre de modelo</label><input :id="`model-${model.id}`" name="nombre" maxlength="100" required :value="model.name" :disabled="!model.active" :class="fieldClass" /><button type="submit" :disabled="!model.active" :class="secondaryButton">Guardar</button></form><form v-if="model.active" method="post" :action="model.inactivateUrl" class="mt-2"><CsrfInput :csrf="data.csrf" /><button type="submit" :class="dangerButton">Inactivar</button></form><StatusBadge v-else status="BAJA" /></li></ul>
      </PanelCard>
    </section>
  </div>
</template>
