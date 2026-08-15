<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { MagnifyingGlassIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import CsrfInput from './CsrfInput.vue'
import FormField from './FormField.vue'
import { fieldClass, primaryButton, secondaryButton } from '../helpers.js'

const props = defineProps({
  open: { type: Boolean, required: true },
  item: { type: Object, default: null },
  csrf: { type: Object, required: true },
  canEdit: { type: Boolean, default: false },
  importBaseUrl: { type: String, required: true },
})

const emit = defineEmits(['close', 'saved'])
const mode = ref('existing')
const query = ref('')
const results = ref([])
const selectedTask = ref(null)
const searching = ref(false)
const submitting = ref(false)
const errorMessage = ref('')
const searchTimer = ref(null)
const searchInput = ref(null)

const relation = ref({ order: 1, mandatory: true, observations: '' })
const newTask = ref({
  code: '',
  name: '',
  description: '',
  procedure: '',
  durationMinutes: '',
  active: true,
  requiresPart: false,
  requiresControl: false,
  requiresPhoto: false,
})

const libraryBase = computed(() => props.importBaseUrl.replace(/\/$/, '') + '/biblioteca')
const searchUrl = computed(() => libraryBase.value + '/tareas/buscar')
const linkUrl = computed(() => libraryBase.value + '/servicios/' + props.item?.serviceTypeId + '/tareas')
const createUrl = computed(() => linkUrl.value + '/nueva')

watch(
  () => props.open,
  (next) => {
    if (!next) return
    mode.value = 'existing'
    query.value = ''
    results.value = []
    selectedTask.value = null
    errorMessage.value = ''
    submitting.value = false
    const maxOrder = Math.max(0, ...(props.item?.tasks ?? []).map((task) => Number(task.order) || 0))
    relation.value = { order: maxOrder + 1, mandatory: true, observations: '' }
    newTask.value = {
      code: '',
      name: '',
      description: '',
      procedure: '',
      durationMinutes: '',
      active: true,
      requiresPart: false,
      requiresControl: false,
      requiresPhoto: false,
    }
    nextTick(() => searchInput.value?.focus())
  },
)

watch(query, (value) => {
  if (!props.open || mode.value !== 'existing') return
  if (searchTimer.value) clearTimeout(searchTimer.value)

  const selectedLabel = selectedTask.value
    ? selectedTask.value.code + ' - ' + selectedTask.value.name
    : null
  if (selectedLabel === value) {
    results.value = []
    return
  }

  selectedTask.value = null
  if (value.trim().length < 2) {
    results.value = []
    return
  }
  searchTimer.value = setTimeout(() => searchTasks(value.trim()), 250)
})

function close() {
  if (submitting.value) return
  emit('close')
}

function onBackdrop(event) {
  if (event.target === event.currentTarget) close()
}

function onKeydown(event) {
  if (event.key === 'Escape') {
    event.preventDefault()
    close()
  }
}

function setMode(nextMode) {
  mode.value = nextMode
  errorMessage.value = ''
  selectedTask.value = null
  if (nextMode === 'existing') nextTick(() => searchInput.value?.focus())
}

async function searchTasks(search) {
  if (!props.item?.serviceTypeId) return
  searching.value = true
  errorMessage.value = ''
  try {
    const url = new URL(searchUrl.value, window.location.origin)
    url.searchParams.set('q', search)
    url.searchParams.set('tipo_servicio_id', String(props.item.serviceTypeId))
    const response = await fetch(url.toString(), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    })
    const payload = await response.json().catch(() => null)
    if (!response.ok) {
      errorMessage.value = payload?.message || 'No se pudo buscar el catálogo de tareas.'
      results.value = []
      return
    }
    results.value = payload?.tasks ?? []
  } catch (error) {
    errorMessage.value = error?.message || 'Error de red al buscar tareas.'
    results.value = []
  } finally {
    searching.value = false
  }
}

function selectTask(task) {
  if (task.alreadyLinked) return
  selectedTask.value = task
  query.value = task.code + ' - ' + task.name
  results.value = []
}

function appendRelation(data) {
  data.append('orden', String(relation.value.order))
  if (relation.value.mandatory) data.append('obligatoria', '1')
  data.append('observaciones', relation.value.observations ?? '')
}

function appendCsrf(data) {
  if (props.csrf?.name && props.csrf?.hash) data.append(props.csrf.name, props.csrf.hash)
}

async function post(url, data) {
  const response = await fetch(url, {
    method: 'POST',
    body: data,
    credentials: 'same-origin',
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
  })
  const payload = await response.json().catch(() => null)
  if (!response.ok) throw new Error(payload?.message || 'No se pudo completar la operación.')
  return payload
}

async function submitExisting() {
  if (!selectedTask.value) {
    errorMessage.value = 'Seleccioná una tarea existente del catálogo.'
    return
  }
  const data = new FormData()
  appendCsrf(data)
  data.append('tarea_id', String(selectedTask.value.id))
  appendRelation(data)
  const payload = await post(linkUrl.value, data)
  emit('saved', payload)
}

async function submitNew() {
  const code = newTask.value.code.trim()
  const name = newTask.value.name.trim()
  if (!code || !name) {
    errorMessage.value = 'Código y nombre son obligatorios.'
    return
  }

  const data = new FormData()
  appendCsrf(data)
  data.append('codigo', code)
  data.append('nombre', name)
  data.append('descripcion', newTask.value.description ?? '')
  data.append('procedimiento', newTask.value.procedure ?? '')
  data.append('duracion_estimada_min', newTask.value.durationMinutes ?? '')
  if (newTask.value.active) data.append('activo', '1')
  if (newTask.value.requiresPart) data.append('requiere_repuesto', '1')
  if (newTask.value.requiresControl) data.append('requiere_control', '1')
  if (newTask.value.requiresPhoto) data.append('requiere_foto', '1')
  appendRelation(data)
  const payload = await post(createUrl.value, data)
  emit('saved', payload)
}

async function onSubmit() {
  if (!props.canEdit || submitting.value) return
  errorMessage.value = ''
  submitting.value = true
  try {
    if (mode.value === 'existing') await submitExisting()
    else await submitNew()
  } catch (error) {
    errorMessage.value = error?.message || 'No se pudo agregar la tarea.'
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open && item"
      class="fixed inset-0 z-50 flex items-end justify-center bg-ink/50 px-4 py-6 sm:items-center"
      role="dialog"
      aria-modal="true"
      :aria-labelledby="'task-add-title-' + item.id"
      tabindex="-1"
      @click="onBackdrop"
      @keydown="onKeydown"
    >
      <div class="flex w-full max-w-2xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
        <header class="flex items-start justify-between gap-3 border-b border-border-subtle px-5 py-4">
          <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Tareas del servicio</p>
            <h2 :id="'task-add-title-' + item.id" class="truncate text-base font-bold text-ink">Agregar tarea</h2>
            <p class="mt-0.5 truncate text-xs text-ink-muted">{{ item.serviceName }} · {{ item.serviceCode }}</p>
          </div>
          <button type="button" :class="secondaryButton" class="!min-h-9 !px-2" :disabled="submitting" aria-label="Cerrar" @click="close">
            <XMarkIcon class="size-4" aria-hidden="true" />
          </button>
        </header>

        <div class="flex border-b border-border-subtle px-5 pt-3">
          <button type="button" class="border-b-2 px-3 py-2 text-sm font-semibold" :class="mode === 'existing' ? 'border-primary text-primary' : 'border-transparent text-ink-muted'" @click="setMode('existing')">Buscar existente</button>
          <button type="button" class="border-b-2 px-3 py-2 text-sm font-semibold" :class="mode === 'new' ? 'border-primary text-primary' : 'border-transparent text-ink-muted'" @click="setMode('new')">Crear nueva</button>
        </div>

        <form class="flex max-h-[75vh] flex-col" @submit.prevent="onSubmit">
          <CsrfInput :csrf="csrf" />
          <div class="grid flex-1 gap-3 overflow-y-auto px-5 py-4 md:grid-cols-2">
            <template v-if="mode === 'existing'">
              <div class="relative md:col-span-2">
                <label class="mb-1 block text-xs font-semibold text-ink-muted" :for="'task-search-' + item.id">Buscar por código o nombre</label>
                <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-[2.35rem] size-4 text-ink-subtle" aria-hidden="true" />
                <input :id="'task-search-' + item.id" ref="searchInput" v-model="query" type="search" autocomplete="off" placeholder="Escribí al menos 2 caracteres" :class="fieldClass + ' pl-9'" />
                <div v-if="query.trim().length >= 2 && !selectedTask" class="absolute z-10 mt-1 max-h-56 w-full overflow-y-auto rounded-lg border border-border bg-white shadow-lg">
                  <p v-if="searching" class="px-3 py-2 text-sm text-ink-muted">Buscando…</p>
                  <button v-for="task in results" :key="task.id" type="button" class="flex w-full items-center justify-between gap-3 border-b border-border-subtle px-3 py-2 text-left last:border-0" :class="task.alreadyLinked ? 'cursor-not-allowed bg-surface-muted opacity-60' : 'hover:bg-surface-subtle'" :disabled="task.alreadyLinked" @click="selectTask(task)">
                    <span class="min-w-0"><span class="block truncate text-sm font-semibold text-ink">{{ task.name }}</span><span class="font-mono text-xs text-ink-muted">{{ task.code }}</span></span>
                    <span v-if="task.alreadyLinked" class="shrink-0 rounded-full bg-surface-muted px-2 py-0.5 text-[11px] font-semibold text-ink-muted">Ya agregada</span>
                    <span v-else-if="!task.active" class="shrink-0 rounded-full bg-warning-subtle px-2 py-0.5 text-[11px] font-semibold text-warning-strong">Inactiva</span>
                  </button>
                  <p v-if="!searching && results.length === 0" class="px-3 py-2 text-sm text-ink-muted">Sin coincidencias.</p>
                </div>
              </div>
              <p v-if="selectedTask" class="rounded-lg bg-primary/5 px-3 py-2 text-sm text-ink md:col-span-2">Seleccionada: <strong>{{ selectedTask.code }} · {{ selectedTask.name }}</strong></p>
            </template>

            <template v-else>
              <FormField label="Código" :for-id="'new-task-code-' + item.id"><input :id="'new-task-code-' + item.id" v-model="newTask.code" name="codigo" type="text" maxlength="50" required :class="fieldClass" /></FormField>
              <FormField label="Nombre" :for-id="'new-task-name-' + item.id"><input :id="'new-task-name-' + item.id" v-model="newTask.name" name="nombre" type="text" maxlength="150" required :class="fieldClass" /></FormField>
              <FormField label="Descripción" :for-id="'new-task-description-' + item.id" class="md:col-span-2"><textarea :id="'new-task-description-' + item.id" v-model="newTask.description" rows="2" :class="fieldClass"></textarea></FormField>
              <FormField label="Procedimiento" :for-id="'new-task-procedure-' + item.id" class="md:col-span-2"><textarea :id="'new-task-procedure-' + item.id" v-model="newTask.procedure" rows="2" :class="fieldClass"></textarea></FormField>
              <FormField label="Duración estimada (min)" :for-id="'new-task-duration-' + item.id"><input :id="'new-task-duration-' + item.id" v-model="newTask.durationMinutes" type="number" min="0" step="1" :class="fieldClass" /></FormField>
              <div class="flex flex-wrap items-center gap-x-4 gap-y-2 self-end pb-2">
                <label class="flex items-center gap-2 text-sm font-semibold text-ink"><input v-model="newTask.active" type="checkbox" class="size-4 rounded border-border text-primary" />Activa</label>
                <label class="flex items-center gap-2 text-sm font-semibold text-ink"><input v-model="newTask.requiresPart" type="checkbox" class="size-4 rounded border-border text-primary" />Repuesto</label>
                <label class="flex items-center gap-2 text-sm font-semibold text-ink"><input v-model="newTask.requiresControl" type="checkbox" class="size-4 rounded border-border text-primary" />Control</label>
                <label class="flex items-center gap-2 text-sm font-semibold text-ink"><input v-model="newTask.requiresPhoto" type="checkbox" class="size-4 rounded border-border text-primary" />Foto</label>
              </div>
            </template>

            <div class="mt-2 border-t border-border-subtle pt-3 md:col-span-2">
              <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-ink-muted">En este servicio</p>
              <div class="grid gap-3 md:grid-cols-2">
                <FormField label="Orden" :for-id="'task-relation-order-' + item.id"><input :id="'task-relation-order-' + item.id" v-model.number="relation.order" type="number" min="1" step="1" required :class="fieldClass" /></FormField>
                <label class="flex items-center gap-2 self-end pb-3 text-sm font-semibold text-ink"><input v-model="relation.mandatory" type="checkbox" class="size-4 rounded border-border text-primary" />Obligatoria</label>
                <FormField label="Observaciones de la relación" :for-id="'task-relation-notes-' + item.id" class="md:col-span-2"><textarea :id="'task-relation-notes-' + item.id" v-model="relation.observations" rows="2" maxlength="500" :class="fieldClass"></textarea></FormField>
              </div>
            </div>

            <p v-if="errorMessage" class="rounded-md bg-danger-subtle px-3 py-2 text-sm text-danger-strong md:col-span-2" role="alert">{{ errorMessage }}</p>
          </div>

          <footer class="flex flex-wrap items-center justify-end gap-2 border-t border-border-subtle bg-surface-subtle px-5 py-3">
            <button type="button" :class="secondaryButton" :disabled="submitting" @click="close">Cancelar</button>
            <button type="submit" :class="primaryButton" :disabled="submitting || !canEdit">{{ submitting ? 'Guardando…' : (mode === 'existing' ? 'Agregar tarea' : 'Crear y agregar') }}</button>
          </footer>
        </form>
      </div>
    </div>
  </Teleport>
</template>
