<script setup>
import { computed, ref } from 'vue'
import { PlusIcon } from '@heroicons/vue/20/solid'
import CsrfInput from './components/CsrfInput.vue'
import FormField from './components/FormField.vue'
import PageHeading from './components/PageHeading.vue'
import { fieldClass, primaryButton, secondaryButton, today } from './helpers.js'
import { tireStatusTone } from './tireStatus.js'

const props = defineProps({ data: { type: Object, required: true } })

const options = computed(() => props.data.options ?? {})
const branches = computed(() => options.value.branches ?? [])
const brands = computed(() => options.value.brands ?? [])
const old = computed(() => props.data.oldInput ?? {})
const filters = computed(() => props.data.filters ?? {})
const items = computed(() => props.data.items ?? [])
const hasFilters = computed(() => Boolean(filters.value.q || filters.value.estado || filters.value.sucursal || filters.value.marca))
const truncated = computed(() => props.data.total > items.value.length)

const wantsCreate = () => Object.keys(old.value).length > 0
  || (typeof window !== 'undefined' && window.location.hash === '#alta')
const creating = ref(Boolean(props.data.canOperate) && wantsCreate())

const form = ref({
  branch: old.value.sucursal_id ?? (branches.value.length === 1 ? String(branches.value[0].id) : ''),
  brand: old.value.marca_id ?? '',
  model: old.value.modelo_id ?? '',
})

const modelsForBrand = computed(() => (options.value.models ?? []).filter((model) => String(model.brandId) === String(form.value.brand)))
const currentYear = new Date().getFullYear()

const onBrandChange = () => {
  if (!modelsForBrand.value.some((model) => String(model.id) === String(form.value.model))) form.value.model = ''
}

const describe = (item) => [item.brandName, item.modelName, item.size].filter(Boolean).join(' · ')
</script>

<template>
  <div class="space-y-6">
    <PageHeading
      eyebrow="Neumáticos"
      title="Padrón de neumáticos"
      description="Cada neumático con su estado y su sucursal. Entrá a la ficha para ver su historial y cambiarle el estado."
    >
      <template #actions>
        <div class="flex flex-wrap gap-2">
          <a :href="data.urls.masters" :class="secondaryButton">Maestros</a>
          <button v-if="data.canOperate" type="button" :class="primaryButton" @click="creating = true">
            <PlusIcon class="mr-1.5 size-5" aria-hidden="true" />
            Nuevo neumático
          </button>
        </div>
      </template>
    </PageHeading>

    <p v-if="data.canOperate && brands.length === 0" class="rounded-card bg-surface-raised px-4 py-3 text-sm text-ink-muted shadow-card">
      Todavía no hay marcas activas. Podés dar de alta neumáticos sin marca, o
      <a :href="data.urls.masters" class="font-semibold text-primary hover:text-primary-hover">cargarlas en Maestros</a>.
    </p>

    <form method="get" :action="data.urls.index" class="grid gap-3 rounded-card bg-surface-raised p-4 sm:grid-cols-2 lg:grid-cols-[minmax(12rem,1fr)_12rem_12rem_12rem_auto] sm:p-5 shadow-card" aria-label="Filtrar el padrón">
      <label>
        <span class="mb-1.5 block text-sm font-semibold text-ink">Buscar</span>
        <input name="q" type="search" :value="filters.q" placeholder="Número, marca, modelo o medida" :class="fieldClass" />
      </label>
      <label>
        <span class="mb-1.5 block text-sm font-semibold text-ink">Estado</span>
        <select name="estado" :class="fieldClass">
          <option value="">Todos</option>
          <option v-for="status in options.statuses" :key="status.value" :value="status.value" :selected="status.value === filters.estado">{{ status.label }}</option>
        </select>
      </label>
      <label v-if="branches.length > 1">
        <span class="mb-1.5 block text-sm font-semibold text-ink">Sucursal</span>
        <select name="sucursal" :class="fieldClass">
          <option value="">Todas</option>
          <option v-for="branch in branches" :key="branch.id" :value="branch.id" :selected="branch.id === filters.sucursal">{{ branch.name }}</option>
        </select>
      </label>
      <label v-if="brands.length">
        <span class="mb-1.5 block text-sm font-semibold text-ink">Marca</span>
        <select name="marca" :class="fieldClass">
          <option value="">Todas</option>
          <option v-for="brand in brands" :key="brand.id" :value="brand.id" :selected="brand.id === filters.marca">{{ brand.name }}</option>
        </select>
      </label>
      <div class="flex items-end gap-2">
        <button type="submit" :class="primaryButton">Filtrar</button>
        <a v-if="hasFilters" :href="data.urls.index" :class="secondaryButton">Limpiar</a>
      </div>
    </form>

    <section class="overflow-hidden rounded-card bg-surface-raised shadow-card" aria-labelledby="tire-registry-list">
      <div class="border-b border-border-subtle px-4 py-3 sm:px-5">
        <h2 id="tire-registry-list" class="text-base font-bold text-ink">
          {{ data.total }} {{ data.total === 1 ? 'neumático' : 'neumáticos' }}
        </h2>
        <p v-if="truncated" class="mt-1 text-sm text-ink-muted">Se muestran los primeros {{ items.length }}. Usá los filtros para encontrar el que buscás.</p>
      </div>

      <p v-if="items.length === 0 && hasFilters" class="p-5 text-sm text-ink-muted">Ningún neumático coincide con los filtros.</p>
      <p v-else-if="items.length === 0" class="p-5 text-sm text-ink-muted">
        Todavía no hay neumáticos en el padrón.
        <template v-if="data.canOperate">Dá de alta el primero con <strong>Nuevo neumático</strong>.</template>
      </p>

      <ul v-else class="divide-y divide-border-subtle">
        <li v-for="item in items" :key="item.id">
          <a :href="item.url" class="flex flex-col gap-2 px-4 py-4 hover:bg-surface-muted sm:flex-row sm:items-center sm:justify-between sm:px-5">
            <div class="min-w-0">
              <p class="font-semibold text-ink">{{ item.number }}</p>
              <p class="mt-0.5 truncate text-sm text-ink-muted">{{ describe(item) || 'Sin marca ni medida cargadas' }}</p>
              <p v-if="item.mountedOn" class="mt-0.5 truncate text-sm text-ink">Colocado en {{ item.mountedOn }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2 text-sm">
              <span class="text-ink-muted">{{ item.branchName }}</span>
              <span :class="tireStatusTone(item.status)" class="rounded-full px-2.5 py-1 text-xs font-semibold">{{ item.statusLabel }}</span>
            </div>
          </a>
        </li>
      </ul>
    </section>

    <Teleport to="body">
      <div v-if="creating" class="fixed inset-0 z-[80] flex items-start justify-center overflow-y-auto bg-black/45 p-4 sm:items-center" role="presentation" @mousedown.self="creating = false" @keydown.esc="creating = false">
        <section id="alta" role="dialog" aria-modal="true" aria-labelledby="tire-create-title" class="w-full max-w-2xl rounded-xl bg-surface-raised shadow-2xl">
          <header class="border-b border-border-subtle px-6 py-4">
            <h2 id="tire-create-title" class="text-lg font-bold text-ink">Nuevo neumático</h2>
            <p class="mt-1 text-sm text-ink-muted">El número es la identidad del neumático: después no se puede cambiar.</p>
          </header>
          <form method="post" :action="data.urls.create" class="grid gap-4 p-6 sm:grid-cols-2">
            <CsrfInput :csrf="data.csrf" />

            <FormField label="Número" for-id="tire-create-number" hint="El que está marcado en la cubierta. Hasta 20 caracteres.">
              <input id="tire-create-number" name="numero" required maxlength="20" autocomplete="off" :value="old.numero" placeholder="Ej.: TSA 181917" :class="fieldClass" class="uppercase" />
            </FormField>

            <FormField label="Sucursal" for-id="tire-create-branch">
              <select id="tire-create-branch" v-model="form.branch" name="sucursal_id" required :class="fieldClass">
                <option v-if="branches.length !== 1" value="" disabled>Elegí una sucursal</option>
                <option v-for="branch in branches" :key="branch.id" :value="String(branch.id)">{{ branch.name }}</option>
              </select>
            </FormField>

            <FormField label="Marca (opcional)" for-id="tire-create-brand">
              <select id="tire-create-brand" v-model="form.brand" name="marca_id" :class="fieldClass" @change="onBrandChange">
                <option value="">Sin marca</option>
                <option v-for="brand in brands" :key="brand.id" :value="String(brand.id)">{{ brand.name }}</option>
              </select>
            </FormField>

            <FormField label="Modelo (opcional)" for-id="tire-create-model" :hint="form.brand ? '' : 'Elegí primero la marca.'">
              <select id="tire-create-model" v-model="form.model" name="modelo_id" :disabled="!form.brand" :class="fieldClass">
                <option value="">Sin modelo</option>
                <option v-for="model in modelsForBrand" :key="model.id" :value="String(model.id)">{{ model.name }}</option>
              </select>
            </FormField>

            <FormField label="Tipo de cubierta (opcional)" for-id="tire-create-cover">
              <select id="tire-create-cover" name="tipo_cubierta_id" :class="fieldClass">
                <option value="">Sin tipo</option>
                <option v-for="cover in options.coverTypes" :key="cover.id" :value="cover.id" :selected="String(cover.id) === old.tipo_cubierta_id">{{ cover.name }}</option>
              </select>
            </FormField>

            <FormField label="Medida (opcional)" for-id="tire-create-size">
              <input id="tire-create-size" name="medida" maxlength="30" autocomplete="off" :value="old.medida" placeholder="Ej.: 295/80 R22.5" :class="fieldClass" />
            </FormField>

            <FormField label="Año de fabricación (opcional)" for-id="tire-create-year">
              <input id="tire-create-year" name="anio_fabricacion" type="number" inputmode="numeric" min="1900" :max="currentYear" :value="old.anio_fabricacion" :class="fieldClass" />
            </FormField>

            <FormField label="Estado inicial" for-id="tire-create-status" hint="Para cargar un neumático que ya está en uso, elegí su estado actual.">
              <select id="tire-create-status" name="estado_inicial" :class="fieldClass">
                <option v-for="status in options.initialStatuses" :key="status.value" :value="status.value" :selected="status.value === (old.estado_inicial ?? 'NUEVA')">{{ status.label }}</option>
              </select>
            </FormField>

            <FormField label="Fecha de alta" for-id="tire-create-date">
              <input id="tire-create-date" name="fecha_alta" type="date" :max="today()" :value="old.fecha_alta ?? today()" :class="fieldClass" />
            </FormField>

            <div class="flex justify-end gap-2 border-t border-border-subtle pt-4 sm:col-span-2">
              <button type="button" :class="secondaryButton" @click="creating = false">Cancelar</button>
              <button type="submit" :class="primaryButton">Dar de alta</button>
            </div>
          </form>
        </section>
      </div>
    </Teleport>
  </div>
</template>
