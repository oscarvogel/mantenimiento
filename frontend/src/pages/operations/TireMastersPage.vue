<script setup>
import { computed, nextTick, onMounted, ref } from 'vue'
import { PencilSquareIcon } from '@heroicons/vue/24/outline'
import CsrfInput from './components/CsrfInput.vue'
import FormField from './components/FormField.vue'
import PageHeading from './components/PageHeading.vue'
import { fieldClass, primaryButton, secondaryButton } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })

const ORDER = ['marcas', 'modelos', 'tipos-cubierta', 'depositos']

const catalogs = computed(() => props.data.catalogs ?? {})
const keys = computed(() => ORDER.filter((key) => catalogs.value[key]))
const branches = computed(() => props.data.branches ?? [])
const activeBrands = computed(() => (catalogs.value.marcas?.items ?? []).filter((brand) => brand.active))

const initialKey = () => {
  const hash = typeof window === 'undefined' ? '' : window.location.hash.replace('#', '')
  return catalogs.value[hash] ? hash : keys.value[0] ?? 'marcas'
}

const selected = ref(initialKey())
const query = ref('')
const status = ref('all')
const editing = ref(null)

const nameField = (key) => (key === 'tipos-cubierta' ? 'descripcion' : 'nombre')

const copy = {
  marcas: { singular: 'marca', create: 'Nueva marca', placeholder: 'Ej.: Michelin', empty: 'Todavía no hay marcas cargadas. Cargá la primera para poder dar de alta neumáticos.' },
  modelos: { singular: 'modelo', create: 'Nuevo modelo', placeholder: 'Ej.: X Multi D', empty: 'Todavía no hay modelos cargados.' },
  'tipos-cubierta': { singular: 'tipo de cubierta', create: 'Nuevo tipo de cubierta', placeholder: 'Ej.: Lisa', empty: 'Todavía no hay tipos de cubierta cargados.' },
  depositos: { singular: 'depósito', create: 'Nuevo depósito', placeholder: 'Ej.: Depósito central', empty: 'Todavía no hay depósitos cargados.' },
}

const items = computed(() => catalogs.value[selected.value]?.items ?? [])

const visibleItems = computed(() => {
  const text = query.value.trim().toLocaleLowerCase('es')
  return items.value.filter((item) => {
    if (status.value === 'active' && !item.active) return false
    if (status.value === 'inactive' && item.active) return false
    if (text === '') return true
    return [item.name, item.brandName, item.branchName, item.location]
      .filter(Boolean)
      .some((value) => String(value).toLocaleLowerCase('es').includes(text))
  })
})

const cannotCreateModel = computed(() => selected.value === 'modelos' && activeBrands.value.length === 0)
const cannotCreateDeposit = computed(() => selected.value === 'depositos' && branches.value.length === 0)

const tablist = ref(null)

// En móvil las pestañas desbordan: la elegida tiene que quedar a la vista.
const revealSelectedTab = async () => {
  await nextTick()
  tablist.value?.querySelector('[aria-selected="true"]')?.scrollIntoView?.({ block: 'nearest', inline: 'nearest' })
}

onMounted(revealSelectedTab)

const select = (key) => {
  selected.value = key
  query.value = ''
  status.value = 'all'
  if (typeof window !== 'undefined') window.history.replaceState(null, '', `#${key}`)
  revealSelectedTab()
}

const detail = (item) => {
  if (selected.value === 'modelos') return item.brandName
  if (selected.value === 'depositos') return [item.branchName, item.location].filter(Boolean).join(' · ')
  return ''
}

const startEdit = (item) => {
  editing.value = { ...item, catalog: selected.value }
}

const stopEdit = () => {
  editing.value = null
}

const brandOptionsFor = (item) => {
  const options = [...activeBrands.value]
  if (item?.brandId && !options.some((brand) => brand.id === item.brandId)) {
    options.unshift({ id: item.brandId, name: `${item.brandName} (inactiva)` })
  }
  return options
}
</script>

<template>
  <div class="space-y-6">
    <PageHeading
      eyebrow="Neumáticos · Configuración"
      title="Maestros de neumáticos"
      description="Marcas, modelos, tipos de cubierta y depósitos que se usan al dar de alta y mover neumáticos. Un registro inactivo se conserva en lo que ya lo usa."
    />

    <!-- Los mensajes del servidor (data.flash) los muestra el shell: no se repiten acá. -->
    <div ref="tablist" class="flex gap-2 overflow-x-auto pb-1" role="tablist" aria-label="Maestros de neumáticos">
      <button
        v-for="key in keys"
        :key="key"
        type="button"
        role="tab"
        :aria-selected="selected === key"
        :class="selected === key ? primaryButton : secondaryButton"
        class="shrink-0"
        @click="select(key)"
      >
        {{ catalogs[key].label }}
        <span class="ml-2 rounded-full bg-black/10 px-2 text-xs">{{ catalogs[key].items.length }}</span>
      </button>
    </div>

    <section v-if="data.canEdit" class="rounded-xl border border-border bg-surface-raised p-4 sm:p-5" :aria-labelledby="`tire-master-create-${selected}`">
      <h2 :id="`tire-master-create-${selected}`" class="text-base font-bold text-ink">{{ copy[selected].create }}</h2>

      <p v-if="cannotCreateModel" class="mt-2 text-sm text-ink-muted">
        Para cargar un modelo primero tiene que haber una marca activa.
        <button type="button" class="font-semibold text-primary hover:text-primary-hover" @click="select('marcas')">Ir a Marcas</button>
      </p>
      <p v-else-if="cannotCreateDeposit" class="mt-2 text-sm text-ink-muted">
        No tenés sucursales activas asignadas para ubicar un depósito.
      </p>

      <form v-else method="post" :action="data.urls.create" class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <CsrfInput :csrf="data.csrf" />
        <input type="hidden" name="catalogo" :value="selected" />

        <FormField v-if="selected === 'modelos'" label="Marca" for-id="tire-master-brand">
          <select id="tire-master-brand" name="marca_id" required :class="fieldClass">
            <option value="" disabled selected>Elegí una marca</option>
            <option v-for="brand in activeBrands" :key="brand.id" :value="brand.id">{{ brand.name }}</option>
          </select>
        </FormField>

        <FormField v-if="selected === 'depositos'" label="Sucursal" for-id="tire-master-branch">
          <select id="tire-master-branch" name="sucursal_id" required :class="fieldClass">
            <option v-if="branches.length > 1" value="" disabled selected>Elegí una sucursal</option>
            <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
          </select>
        </FormField>

        <FormField label="Nombre" for-id="tire-master-name">
          <input id="tire-master-name" :name="nameField(selected)" required :maxlength="selected === 'tipos-cubierta' ? 40 : selected === 'depositos' ? 80 : 60" :placeholder="copy[selected].placeholder" autocomplete="off" :class="fieldClass" />
        </FormField>

        <FormField v-if="selected === 'depositos'" label="Ubicación (opcional)" for-id="tire-master-location">
          <input id="tire-master-location" name="ubicacion" maxlength="200" placeholder="Ej.: Ruta 14, galpón 2" autocomplete="off" :class="fieldClass" />
        </FormField>

        <div class="flex items-end">
          <button type="submit" :class="primaryButton" class="w-full md:w-auto">Crear {{ copy[selected].singular }}</button>
        </div>
      </form>
    </section>

    <section class="overflow-hidden rounded-xl border border-border bg-surface-raised" :aria-labelledby="`tire-master-list-${selected}`">
      <div class="flex flex-col gap-3 border-b border-border-subtle p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
        <h2 :id="`tire-master-list-${selected}`" class="text-base font-bold text-ink">
          {{ catalogs[selected]?.label }}
          <span class="ml-1 text-sm font-medium text-ink-muted">({{ visibleItems.length }} de {{ items.length }})</span>
        </h2>
        <div class="grid gap-2 sm:grid-cols-[minmax(12rem,16rem)_10rem]">
          <label>
            <span class="sr-only">Buscar en {{ catalogs[selected]?.label }}</span>
            <input v-model="query" type="search" placeholder="Buscar" :class="fieldClass" />
          </label>
          <label>
            <span class="sr-only">Filtrar por estado</span>
            <select v-model="status" :class="fieldClass">
              <option value="all">Todos</option>
              <option value="active">Activos</option>
              <option value="inactive">Inactivos</option>
            </select>
          </label>
        </div>
      </div>

      <p v-if="items.length === 0" class="p-5 text-sm text-ink-muted">{{ copy[selected].empty }}</p>
      <p v-else-if="visibleItems.length === 0" class="p-5 text-sm text-ink-muted">Ningún registro coincide con la búsqueda.</p>

      <ul v-else class="divide-y divide-border-subtle">
        <li v-for="item in visibleItems" :key="item.id" class="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
          <div class="min-w-0">
            <p class="truncate font-semibold text-ink">{{ item.name }}</p>
            <p v-if="detail(item)" class="mt-0.5 truncate text-sm text-ink-muted">{{ detail(item) }}</p>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <span :class="item.active ? 'bg-success-soft text-success-strong' : 'bg-surface-subtle text-ink-muted'" class="rounded-full px-2.5 py-1 text-xs font-semibold">
              {{ item.active ? 'Activo' : 'Inactivo' }}
            </span>
            <template v-if="data.canEdit">
              <button type="button" :class="secondaryButton" @click="startEdit(item)">
                <PencilSquareIcon class="mr-1.5 size-4" aria-hidden="true" />
                Editar
              </button>
              <form method="post" :action="item.statusUrl">
                <CsrfInput :csrf="data.csrf" />
                <input type="hidden" name="activo" :value="item.active ? '0' : '1'" />
                <button type="submit" :class="secondaryButton">{{ item.active ? 'Inactivar' : 'Activar' }}</button>
              </form>
            </template>
          </div>
        </li>
      </ul>
    </section>

    <Teleport to="body">
      <div v-if="editing" class="fixed inset-0 z-[80] flex items-center justify-center bg-black/45 p-4" role="presentation" @mousedown.self="stopEdit" @keydown.esc="stopEdit">
        <section role="dialog" aria-modal="true" aria-labelledby="tire-master-edit-title" class="w-full max-w-lg rounded-xl bg-surface-raised shadow-2xl">
          <header class="border-b border-border-subtle px-6 py-4">
            <h2 id="tire-master-edit-title" class="text-lg font-bold text-ink">Editar {{ copy[editing.catalog].singular }}</h2>
          </header>
          <form method="post" :action="editing.updateUrl" class="grid gap-4 p-6">
            <CsrfInput :csrf="data.csrf" />

            <FormField v-if="editing.catalog === 'modelos'" label="Marca" for-id="tire-master-edit-brand">
              <select id="tire-master-edit-brand" name="marca_id" required :class="fieldClass">
                <option v-for="brand in brandOptionsFor(editing)" :key="brand.id" :value="brand.id" :selected="brand.id === editing.brandId">{{ brand.name }}</option>
              </select>
            </FormField>

            <FormField v-if="editing.catalog === 'depositos'" label="Sucursal" for-id="tire-master-edit-branch">
              <select id="tire-master-edit-branch" name="sucursal_id" required :class="fieldClass">
                <option v-for="branch in branches" :key="branch.id" :value="branch.id" :selected="branch.id === editing.branchId">{{ branch.name }}</option>
              </select>
            </FormField>

            <FormField label="Nombre" for-id="tire-master-edit-name">
              <input id="tire-master-edit-name" :name="nameField(editing.catalog)" required :maxlength="editing.catalog === 'tipos-cubierta' ? 40 : editing.catalog === 'depositos' ? 80 : 60" :value="editing.name" autocomplete="off" :class="fieldClass" />
            </FormField>

            <FormField v-if="editing.catalog === 'depositos'" label="Ubicación (opcional)" for-id="tire-master-edit-location">
              <input id="tire-master-edit-location" name="ubicacion" maxlength="200" :value="editing.location" autocomplete="off" :class="fieldClass" />
            </FormField>

            <div class="flex justify-end gap-2 border-t border-border-subtle pt-4">
              <button type="button" :class="secondaryButton" @click="stopEdit">Cancelar</button>
              <button type="submit" :class="primaryButton">Guardar cambios</button>
            </div>
          </form>
        </section>
      </div>
    </Teleport>
  </div>
</template>
