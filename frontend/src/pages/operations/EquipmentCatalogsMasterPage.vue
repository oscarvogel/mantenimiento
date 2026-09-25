<script setup>
import { computed, ref } from 'vue'
import {
  AdjustmentsHorizontalIcon,
  BuildingOffice2Icon,
  ClipboardDocumentListIcon,
  Cog6ToothIcon,
  MagnifyingGlassIcon,
  PencilSquareIcon,
  PlusIcon,
  RectangleStackIcon,
  TagIcon,
  WrenchScrewdriverIcon,
  XMarkIcon,
} from '@heroicons/vue/24/outline'
import CsrfInput from './components/CsrfInput.vue'
import EmptyState from './components/EmptyState.vue'
import PageHeading from './components/PageHeading.vue'
import PaginationBar from './components/PaginationBar.vue'
import PanelCard from './components/PanelCard.vue'
import StatusBadge from './components/StatusBadge.vue'
import { dangerButton, fieldClass, primaryButton, secondaryButton } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })

const section = ref('types')
const query = ref('')
const status = ref('all')
const brandFilter = ref('')
const typeFilter = ref('')
const modal = ref(null)

const activeTypes = computed(() => props.data.catalogs.types.filter((item) => item.active))
const activeBrands = computed(() => props.data.catalogs.brands.filter((item) => item.active))

const normalizedQuery = computed(() => query.value.trim().toLocaleLowerCase('es'))
const matchesStatus = (item) => status.value === 'all' || (status.value === 'active' ? item.active : !item.active)
const matchesQuery = (value) => !normalizedQuery.value || String(value ?? '').toLocaleLowerCase('es').includes(normalizedQuery.value)

const filteredTypes = computed(() => props.data.catalogs.types.filter((item) => matchesStatus(item) && matchesQuery(item.name)))
const filteredBrands = computed(() => props.data.management.brands.items.filter((item) => matchesStatus(item) && matchesQuery(item.name)))
const filteredModels = computed(() => props.data.management.models.items.filter((item) => {
  if (!matchesStatus(item) || !matchesQuery([item.name, item.brandName, item.typeName].join(' '))) return false
  if (brandFilter.value && String(item.brandId) !== brandFilter.value) return false
  if (typeFilter.value && String(item.typeId) !== typeFilter.value) return false
  return true
}))

const masterLinks = computed(() => [
  { key: 'equipment', label: 'Equipos', description: 'Tipos, marcas y modelos', href: props.data.routes.index, icon: RectangleStackIcon, current: true },
  { key: 'expirations', label: 'Tipos de vencimiento', description: 'Documentación y alertas', href: props.data.routes.expirationTypes, icon: ClipboardDocumentListIcon },
  { key: 'services', label: 'Servicios', description: 'Tipos de servicio', href: props.data.routes.services, icon: WrenchScrewdriverIcon },
  { key: 'library', label: 'Tareas y plantillas', description: 'Biblioteca preventiva', href: props.data.routes.preventiveLibrary, icon: Cog6ToothIcon },
  { key: 'providers', label: 'Proveedores / talleres', description: 'Prestadores externos', href: props.data.routes.providers, icon: BuildingOffice2Icon },
  { key: 'branches', label: 'Sucursales', description: 'Bases operativas', href: props.data.routes.branches, icon: BuildingOffice2Icon },
])

const openCreateBrand = () => { modal.value = { kind: 'brand-create' } }
const openEditBrand = (brand) => { modal.value = { kind: 'brand-edit', item: brand } }
const openCreateModel = () => { modal.value = { kind: 'model-create' } }
const openEditModel = (model) => { modal.value = { kind: 'model-edit', item: model } }
const openEditType = (type) => { modal.value = { kind: 'type-edit', item: type } }
const closeModal = () => { modal.value = null }

const selectSection = (key) => {
  section.value = key
  query.value = ''
  status.value = 'all'
  brandFilter.value = ''
  typeFilter.value = ''
}

const sectionTitle = computed(() => ({
  types: 'Tipos de equipo',
  brands: 'Marcas',
  models: 'Modelos',
}[section.value]))

const sectionCount = computed(() => ({
  types: props.data.catalogs.types.length,
  brands: props.data.management.brands.total,
  models: props.data.management.models.total,
}[section.value]))
</script>

<template>
  <div>
    <PageHeading eyebrow="Administración" title="Maestros" description="Gestioná los catálogos del sistema desde un único lugar." />

    <section class="mb-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
      <a
        v-for="item in masterLinks"
        :key="item.key"
        :href="item.href"
        class="ui-interactive flex items-center gap-4 rounded-xl border p-4 transition"
        :class="item.current ? 'border-primary/40 bg-primary-subtle text-primary' : 'border-border bg-surface-raised text-ink hover:bg-surface-muted'"
      >
        <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-surface-subtle">
          <component :is="item.icon" class="size-6" aria-hidden="true" />
        </span>
        <span class="min-w-0">
          <strong class="block text-sm">{{ item.label }}</strong>
          <span class="mt-0.5 block text-xs text-ink-muted">{{ item.description }}</span>
        </span>
      </a>
    </section>

    <PanelCard title="Catálogos de equipos" :count="sectionCount" flush>
      <div class="border-b border-border-subtle p-4 sm:p-5">
        <div class="flex flex-wrap gap-2" role="tablist" aria-label="Catálogos de equipos">
          <button type="button" :class="[secondaryButton, section === 'types' ? '!border-primary !bg-primary-subtle !text-primary' : '']" @click="selectSection('types')">Tipos de equipo <span class="ml-2 text-xs">{{ data.catalogs.types.length }}</span></button>
          <button type="button" :class="[secondaryButton, section === 'brands' ? '!border-primary !bg-primary-subtle !text-primary' : '']" @click="selectSection('brands')">Marcas <span class="ml-2 text-xs">{{ data.management.brands.total }}</span></button>
          <button type="button" :class="[secondaryButton, section === 'models' ? '!border-primary !bg-primary-subtle !text-primary' : '']" @click="selectSection('models')">Modelos <span class="ml-2 text-xs">{{ data.management.models.total }}</span></button>
        </div>

        <div class="mt-4 grid gap-3 lg:grid-cols-[minmax(15rem,1fr)_12rem_auto]">
          <label class="relative block">
            <span class="sr-only">Buscar en {{ sectionTitle }}</span>
            <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-3.5 size-4 text-ink-subtle" aria-hidden="true" />
            <input v-model="query" :placeholder="`Buscar en ${sectionTitle.toLowerCase()}…`" :class="[fieldClass, 'pl-9']" />
          </label>
          <label>
            <span class="sr-only">Estado</span>
            <select v-model="status" :class="fieldClass">
              <option value="all">Todos los estados</option>
              <option value="active">Activos</option>
              <option value="inactive">Inactivos</option>
            </select>
          </label>
          <button v-if="section === 'brands'" type="button" :class="primaryButton" @click="openCreateBrand"><PlusIcon class="mr-2 size-5" aria-hidden="true" />Nueva marca</button>
          <button v-if="section === 'models'" type="button" :class="primaryButton" @click="openCreateModel"><PlusIcon class="mr-2 size-5" aria-hidden="true" />Nuevo modelo</button>
          <div v-if="section === 'types'" class="flex items-center rounded-lg border border-border bg-surface-subtle px-3 text-sm text-ink-muted">
            <AdjustmentsHorizontalIcon class="mr-2 size-5" aria-hidden="true" />Configuración de uso
          </div>
        </div>

        <div v-if="section === 'models'" class="mt-3 grid gap-3 sm:grid-cols-2">
          <select v-model="brandFilter" :class="fieldClass" aria-label="Filtrar por marca">
            <option value="">Todas las marcas</option>
            <option v-for="brand in data.catalogs.brands" :key="brand.id" :value="String(brand.id)">{{ brand.name }}</option>
          </select>
          <select v-model="typeFilter" :class="fieldClass" aria-label="Filtrar por tipo">
            <option value="">Todos los tipos</option>
            <option v-for="type in data.catalogs.types" :key="type.id" :value="String(type.id)">{{ type.name }}</option>
          </select>
        </div>
      </div>

      <div v-if="section === 'types'">
        <EmptyState v-if="filteredTypes.length === 0" title="No encontramos tipos de equipo" description="Probá cambiar la búsqueda o el estado." />
        <div v-else class="overflow-x-auto">
          <table class="w-full min-w-[44rem] text-left text-sm">
            <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted">
              <tr><th class="px-5 py-3">Tipo</th><th class="px-5 py-3">Kilómetros</th><th class="px-5 py-3">Horas</th><th class="px-5 py-3">Estado</th><th class="px-5 py-3 text-right">Acciones</th></tr>
            </thead>
            <tbody class="divide-y divide-border-subtle">
              <tr v-for="type in filteredTypes" :key="type.id" class="hover:bg-surface-subtle/60">
                <td class="px-5 py-3 font-semibold text-ink">{{ type.name }}</td>
                <td class="px-5 py-3"><StatusBadge :status="type.controlsKm ? 'ACTIVO' : 'BAJA'" /></td>
                <td class="px-5 py-3"><StatusBadge :status="type.controlsHours ? 'ACTIVO' : 'BAJA'" /></td>
                <td class="px-5 py-3"><StatusBadge :status="type.active ? 'ACTIVO' : 'BAJA'" /></td>
                <td class="px-5 py-3 text-right"><button type="button" :class="secondaryButton" :disabled="!type.active" @click="openEditType(type)"><PencilSquareIcon class="mr-1.5 size-4" aria-hidden="true" />Editar control</button></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div v-if="section === 'brands'">
        <EmptyState v-if="filteredBrands.length === 0" title="No encontramos marcas" description="Probá cambiar la búsqueda o el estado." />
        <div v-else class="overflow-x-auto">
          <table class="w-full min-w-[40rem] text-left text-sm">
            <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted">
              <tr><th class="px-5 py-3">Marca</th><th class="px-5 py-3">Estado</th><th class="px-5 py-3 text-right">Acciones</th></tr>
            </thead>
            <tbody class="divide-y divide-border-subtle">
              <tr v-for="brand in filteredBrands" :key="brand.id" class="hover:bg-surface-subtle/60">
                <td class="px-5 py-3 font-semibold text-ink">{{ brand.name }}</td>
                <td class="px-5 py-3"><StatusBadge :status="brand.active ? 'ACTIVO' : 'BAJA'" /></td>
                <td class="px-5 py-3"><div class="flex justify-end gap-2"><button type="button" :class="secondaryButton" :disabled="!brand.active" @click="openEditBrand(brand)">Editar</button><form v-if="brand.active" method="post" :action="brand.inactivateUrl" data-confirm data-confirm-title="¿Inactivar la marca?" data-confirm-text="La marca dejará de estar disponible para nuevos equipos. El historial se conserva." data-confirm-button="Inactivar" data-confirm-danger="true"><CsrfInput :csrf="data.csrf" /><button type="submit" :class="dangerButton">Inactivar</button></form></div></td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="border-t border-border-subtle"><PaginationBar :pagination="data.management.brands.pagination" /></div>
      </div>

      <div v-if="section === 'models'">
        <EmptyState v-if="filteredModels.length === 0" title="No encontramos modelos" description="Probá cambiar la búsqueda o los filtros." />
        <div v-else class="overflow-x-auto">
          <table class="w-full min-w-[58rem] text-left text-sm">
            <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted">
              <tr><th class="px-5 py-3">Modelo</th><th class="px-5 py-3">Marca</th><th class="px-5 py-3">Tipo</th><th class="px-5 py-3">Estado</th><th class="px-5 py-3 text-right">Acciones</th></tr>
            </thead>
            <tbody class="divide-y divide-border-subtle">
              <tr v-for="model in filteredModels" :key="model.id" class="hover:bg-surface-subtle/60">
                <td class="px-5 py-3 font-semibold text-ink">{{ model.name }}</td>
                <td class="px-5 py-3 text-ink-muted">{{ model.brandName }}</td>
                <td class="px-5 py-3 text-ink-muted">{{ model.typeName }}</td>
                <td class="px-5 py-3"><StatusBadge :status="model.active ? 'ACTIVO' : 'BAJA'" /></td>
                <td class="px-5 py-3"><div class="flex justify-end gap-2"><button type="button" :class="secondaryButton" :disabled="!model.active" @click="openEditModel(model)">Editar</button><form v-if="model.active" method="post" :action="model.inactivateUrl" data-confirm data-confirm-title="¿Inactivar el modelo?" data-confirm-text="El modelo dejará de estar disponible para nuevos equipos. El historial se conserva." data-confirm-button="Inactivar" data-confirm-danger="true"><CsrfInput :csrf="data.csrf" /><button type="submit" :class="dangerButton">Inactivar</button></form></div></td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="border-t border-border-subtle"><PaginationBar :pagination="data.management.models.pagination" /></div>
      </div>
    </PanelCard>

    <Teleport to="body">
      <div v-if="modal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4 backdrop-blur-[1px]" @click.self="closeModal">
        <section class="w-full max-w-xl overflow-hidden rounded-2xl border border-border bg-surface-raised shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="master-modal-title">
          <header class="flex items-start justify-between gap-4 border-b border-border-subtle px-5 py-4">
            <div>
              <p class="text-xs font-bold uppercase tracking-[0.14em] text-primary">Maestros</p>
              <h2 id="master-modal-title" class="mt-1 text-xl font-bold text-ink">
                <template v-if="modal.kind === 'brand-create'">Nueva marca</template>
                <template v-else-if="modal.kind === 'brand-edit'">Editar marca</template>
                <template v-else-if="modal.kind === 'model-create'">Nuevo modelo</template>
                <template v-else-if="modal.kind === 'model-edit'">Editar modelo</template>
                <template v-else>Configurar {{ modal.item.name }}</template>
              </h2>
            </div>
            <button type="button" :class="secondaryButton" aria-label="Cerrar" @click="closeModal"><XMarkIcon class="size-5" aria-hidden="true" /></button>
          </header>

          <div class="p-5">
            <form v-if="modal.kind === 'brand-create'" method="post" :action="data.routes.createBrand" class="space-y-4">
              <CsrfInput :csrf="data.csrf" />
              <label class="block"><span class="mb-1.5 block text-sm font-semibold text-ink">Nombre</span><input name="nombre" maxlength="100" required autofocus :class="fieldClass" placeholder="Ej. IVECO" /></label>
              <div class="flex justify-end gap-2 border-t border-border-subtle pt-4"><button type="button" :class="secondaryButton" @click="closeModal">Cancelar</button><button type="submit" :class="primaryButton">Crear marca</button></div>
            </form>

            <form v-else-if="modal.kind === 'brand-edit'" method="post" :action="modal.item.updateUrl" class="space-y-4">
              <CsrfInput :csrf="data.csrf" />
              <label class="block"><span class="mb-1.5 block text-sm font-semibold text-ink">Nombre</span><input name="nombre" maxlength="100" required :value="modal.item.name" :class="fieldClass" /></label>
              <div class="flex justify-end gap-2 border-t border-border-subtle pt-4"><button type="button" :class="secondaryButton" @click="closeModal">Cancelar</button><button type="submit" :class="primaryButton">Guardar cambios</button></div>
            </form>

            <form v-else-if="modal.kind === 'model-create'" method="post" :action="data.routes.createModel" class="grid gap-4 sm:grid-cols-2">
              <CsrfInput :csrf="data.csrf" />
              <label class="block"><span class="mb-1.5 block text-sm font-semibold text-ink">Marca</span><select name="marca_id" required :class="fieldClass"><option v-for="brand in activeBrands" :key="brand.id" :value="brand.id">{{ brand.name }}</option></select></label>
              <label class="block"><span class="mb-1.5 block text-sm font-semibold text-ink">Tipo de equipo</span><select name="tipo_equipo_id" required :class="fieldClass"><option v-for="type in activeTypes" :key="type.id" :value="type.id">{{ type.name }}</option></select></label>
              <label class="block sm:col-span-2"><span class="mb-1.5 block text-sm font-semibold text-ink">Nombre</span><input name="nombre" maxlength="100" required :class="fieldClass" placeholder="Ej. Stralis 600" /></label>
              <div class="flex justify-end gap-2 border-t border-border-subtle pt-4 sm:col-span-2"><button type="button" :class="secondaryButton" @click="closeModal">Cancelar</button><button type="submit" :class="primaryButton">Crear modelo</button></div>
            </form>

            <form v-else-if="modal.kind === 'model-edit'" method="post" :action="modal.item.updateUrl" class="space-y-4">
              <CsrfInput :csrf="data.csrf" />
              <div class="rounded-lg border border-border bg-surface-subtle p-3 text-sm text-ink-muted">{{ modal.item.brandName }} · {{ modal.item.typeName }}</div>
              <label class="block"><span class="mb-1.5 block text-sm font-semibold text-ink">Nombre</span><input name="nombre" maxlength="100" required :value="modal.item.name" :class="fieldClass" /></label>
              <div class="flex justify-end gap-2 border-t border-border-subtle pt-4"><button type="button" :class="secondaryButton" @click="closeModal">Cancelar</button><button type="submit" :class="primaryButton">Guardar cambios</button></div>
            </form>

            <form v-else method="post" :action="modal.item.updateUrl" class="space-y-4">
              <CsrfInput :csrf="data.csrf" />
              <div class="rounded-lg border border-border bg-surface-subtle p-3"><p class="font-semibold text-ink">{{ modal.item.name }}</p><p class="mt-1 text-xs text-ink-muted">Estas opciones definen qué lectura corresponde registrar para este tipo de equipo.</p></div>
              <label class="flex items-center justify-between gap-3 rounded-lg border border-border p-3 text-sm text-ink"><span><strong class="block">Controla kilómetros</strong><span class="text-xs text-ink-muted">Habilita kilometraje y recordatorios por km.</span></span><input type="checkbox" name="controla_km" value="1" :checked="modal.item.controlsKm" class="size-5 rounded border-border-strong" /></label>
              <label class="flex items-center justify-between gap-3 rounded-lg border border-border p-3 text-sm text-ink"><span><strong class="block">Controla horas</strong><span class="text-xs text-ink-muted">Habilita horómetro y mantenimiento por horas.</span></span><input type="checkbox" name="controla_horas" value="1" :checked="modal.item.controlsHours" class="size-5 rounded border-border-strong" /></label>
              <div class="flex justify-end gap-2 border-t border-border-subtle pt-4"><button type="button" :class="secondaryButton" @click="closeModal">Cancelar</button><button type="submit" :class="primaryButton">Guardar configuración</button></div>
            </form>
          </div>
        </section>
      </div>
    </Teleport>
  </div>
</template>
