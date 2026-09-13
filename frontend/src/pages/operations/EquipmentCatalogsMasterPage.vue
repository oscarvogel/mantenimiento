<script setup>
import CsrfInput from './components/CsrfInput.vue'
import EmptyState from './components/EmptyState.vue'
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
    <PageHeading eyebrow="Maestros" title="Catálogos de equipos" description="Administrá marcas y modelos utilizados por la flota." />

    <section class="grid gap-6 xl:grid-cols-2">
      <PanelCard title="Marcas" :count="data.management.brands.total">
        <form method="post" :action="data.routes.createBrand" class="mb-5 flex flex-col gap-2 sm:flex-row">
          <CsrfInput :csrf="data.csrf" />
          <label class="sr-only" for="new-brand">Nueva marca</label>
          <input id="new-brand" name="nombre" maxlength="100" required placeholder="Nueva marca" :class="fieldClass" />
          <button type="submit" :class="primaryButton">Crear marca</button>
        </form>
        <EmptyState v-if="data.management.brands.items.length === 0" title="No hay marcas" />
        <ul v-else class="divide-y divide-border-subtle">
          <li v-for="brand in data.management.brands.items" :key="brand.id" class="py-4 first:pt-0">
            <form method="post" :action="brand.updateUrl" class="flex gap-2">
              <CsrfInput :csrf="data.csrf" />
              <label class="sr-only" :for="'brand-' + brand.id">Nombre de marca</label>
              <input :id="'brand-' + brand.id" name="nombre" maxlength="100" required :value="brand.name" :disabled="!brand.active" :class="fieldClass" />
              <button type="submit" :disabled="!brand.active" :class="secondaryButton">Guardar</button>
            </form>
            <form v-if="brand.active" method="post" :action="brand.inactivateUrl" data-confirm data-confirm-title="¿Inactivar la marca?" data-confirm-text="La marca dejará de estar disponible para nuevos equipos." data-confirm-button="Inactivar" data-confirm-danger="true" class="mt-2">
              <CsrfInput :csrf="data.csrf" />
              <button type="submit" :class="dangerButton">Inactivar {{ brand.name }}</button>
            </form>
            <StatusBadge v-else status="BAJA" />
          </li>
        </ul>
        <template #footer><PaginationBar :pagination="data.management.brands.pagination" /></template>
      </PanelCard>

      <PanelCard title="Modelos" :count="data.management.models.total">
        <form method="post" :action="data.routes.createModel" class="mb-5 grid gap-3 sm:grid-cols-2">
          <CsrfInput :csrf="data.csrf" />
          <FormField label="Marca" for-id="new-model-brand">
            <select id="new-model-brand" name="marca_id" required :class="fieldClass">
              <option v-for="brand in data.catalogs.brands.filter((item) => item.active)" :key="brand.id" :value="brand.id">{{ brand.name }}</option>
            </select>
          </FormField>
          <FormField label="Tipo de equipo" for-id="new-model-type">
            <select id="new-model-type" name="tipo_equipo_id" required :class="fieldClass">
              <option v-for="type in data.catalogs.types.filter((item) => item.active)" :key="type.id" :value="type.id">{{ type.name }}</option>
            </select>
          </FormField>
          <FormField label="Nombre" for-id="new-model-name">
            <input id="new-model-name" name="nombre" maxlength="100" required placeholder="Modelo" :class="fieldClass" />
          </FormField>
          <button type="submit" :class="[primaryButton, 'self-end']">Crear modelo</button>
        </form>
        <EmptyState v-if="data.management.models.items.length === 0" title="No hay modelos" />
        <ul v-else class="divide-y divide-border-subtle">
          <li v-for="model in data.management.models.items" :key="model.id" class="py-4 first:pt-0">
            <p class="mb-2 text-xs text-ink-muted">{{ model.brandName }} · {{ model.typeName }}</p>
            <form method="post" :action="model.updateUrl" class="flex gap-2">
              <CsrfInput :csrf="data.csrf" />
              <label class="sr-only" :for="'model-' + model.id">Nombre de modelo</label>
              <input :id="'model-' + model.id" name="nombre" maxlength="100" required :value="model.name" :disabled="!model.active" :class="fieldClass" />
              <button type="submit" :disabled="!model.active" :class="secondaryButton">Guardar</button>
            </form>
            <form v-if="model.active" method="post" :action="model.inactivateUrl" data-confirm data-confirm-title="¿Inactivar el modelo?" data-confirm-text="El modelo dejará de estar disponible para nuevos equipos." data-confirm-button="Inactivar" data-confirm-danger="true" class="mt-2">
              <CsrfInput :csrf="data.csrf" />
              <button type="submit" :class="dangerButton">Inactivar</button>
            </form>
            <StatusBadge v-else status="BAJA" />
          </li>
        </ul>
        <template #footer><PaginationBar :pagination="data.management.models.pagination" /></template>
      </PanelCard>
    </section>
  </div>
</template>
