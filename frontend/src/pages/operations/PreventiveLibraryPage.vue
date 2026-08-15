<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import {
  ArrowDownTrayIcon,
  ChevronDownIcon,
  ChevronRightIcon,
  MagnifyingGlassIcon,
  PencilSquareIcon,
  PlusIcon,
  TrashIcon,
} from '@heroicons/vue/24/outline'
import EmptyState from './components/EmptyState.vue'
import FrequencyEditModal from './components/FrequencyEditModal.vue'
import PageHeading from './components/PageHeading.vue'
import StatusBadge from './components/StatusBadge.vue'
import TaskAddModal from './components/TaskAddModal.vue'
import TaskEditModal from './components/TaskEditModal.vue'
import { useAlerts } from '../../composables/useAlerts.js'
import { fieldClass, primaryButton, secondaryButton } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })
const alerts = useAlerts()

const templates = computed(() => props.data.templates ?? [])
const services = computed(() => props.data.services ?? [])
const items = computed(() => props.data.items ?? [])
const searchQuery = ref('')
const selectedTemplateId = ref(null)

const EDIT_HINT_KEY = (templateId, itemId) => 'biblioteca:edit:lastItem:' + templateId + ':' + itemId
const expandedItems = ref(new Set())

const valueOrBlank = (value) => (value === null || value === undefined ? '' : String(value))
const normalize = (value) => valueOrBlank(value).trim().toLowerCase()

const formatNumber = (value) => {
  if (value === null || value === undefined || value === '') return null
  const number = Number(value)
  if (Number.isNaN(number)) return null
  return number.toLocaleString('es-AR', { maximumFractionDigits: 1 })
}

const formatPriority = (priority) => {
  if (!priority) return '-'
  return priority === 'CRITICA' ? 'CRÍTICA' : priority
}

const formatInterval = (item) => {
  const parts = []
  if (formatNumber(item.intervalKm)) parts.push(formatNumber(item.intervalKm) + ' km')
  if (formatNumber(item.intervalHours)) parts.push(formatNumber(item.intervalHours) + ' h')
  if (formatNumber(item.intervalDays)) parts.push(formatNumber(item.intervalDays) + ' d')
  return parts.length > 0 ? 'Cada ' + parts.join(' / ') : ''
}

const formatWarning = (item) => {
  const parts = []
  if (formatNumber(item.warningKm)) parts.push(formatNumber(item.warningKm) + ' km')
  if (formatNumber(item.warningHours)) parts.push(formatNumber(item.warningHours) + ' h')
  if (formatNumber(item.warningDays)) parts.push(formatNumber(item.warningDays) + ' d')
  return parts.length > 0 ? 'Avisar ' + parts.join(' / ') + ' antes' : ''
}

const taskChips = (task) => {
  const chips = []
  if (task.mandatory) chips.push('Obligatoria')
  if (!task.active) chips.push('Inactiva')
  if (task.requiresPart) chips.push('Repuesto')
  if (task.requiresControl) chips.push('Control')
  if (task.requiresPhoto) chips.push('Foto')
  return chips
}

const itemSearchText = (item) => [
  item.templateCode,
  item.templateName,
  item.equipmentType,
  item.serviceCode,
  item.serviceName,
  item.priority,
  item.notes,
  ...(item.tasks ?? []).flatMap((task) => [task.code, task.name, task.description, task.observations]),
].map(normalize).join(' ')

const selectedTemplate = computed(() => templates.value.find((t) => t.id === selectedTemplateId.value) ?? null)

const filteredItems = computed(() => {
  const query = normalize(searchQuery.value)
  return items.value.filter((item) => {
    if (selectedTemplateId.value !== null && item.templateId !== selectedTemplateId.value) return false
    if (query === '') return true
    return itemSearchText(item).includes(query)
  })
})

const serviceCountInTemplate = computed(() => {
  if (selectedTemplateId.value === null) return 0
  const ids = new Set()
  for (const item of items.value) {
    if (item.templateId === selectedTemplateId.value) ids.add(item.serviceTypeId)
  }
  return ids.size
})

const searchActive = computed(() => normalize(searchQuery.value) !== '')

function onTemplateChange(event) {
  const value = Number(event.target.value)
  selectedTemplateId.value = Number.isNaN(value) ? null : value
}

function isExpanded(item) {
  return expandedItems.value.has(item.id)
}

function toggleItem(item) {
  const next = new Set(expandedItems.value)
  if (next.has(item.id)) next.delete(item.id)
  else {
    next.add(item.id)
    persistEditHint(item)
  }
  expandedItems.value = next
}

function persistEditHint(item) {
  if (typeof window === 'undefined' || !item) return
  try { window.localStorage.setItem(EDIT_HINT_KEY(item.templateId, item.id), '1') } catch { /* ignore */ }
}

function showActionError(message) {
  alerts.error('No se pudo completar la acción', message)
}

const modalOpen = ref(false)
const modalTask = ref(null)
const modalItem = ref(null)

function openTaskModal(item, task) {
  if (!props.data.canEdit) return
  if (!task.updateUrl) {
    showActionError('No está disponible la URL para editar esta tarea. Recargá la página y, si continúa, revisá el payload de Biblioteca.')
    return
  }
  modalItem.value = item
  modalTask.value = task
  persistEditHint(item)
  modalOpen.value = true
}

function closeTaskModal() {
  modalOpen.value = false
  modalTask.value = null
  modalItem.value = null
}

function onTaskSaved() { closeTaskModal() }

const frequencyModalOpen = ref(false)
const frequencyItem = ref(null)

function openFrequencyModal(item) {
  if (!props.data.canEdit) return
  if (!item.updateUrl) {
    showActionError('No está disponible la URL para editar la frecuencia de este servicio.')
    return
  }
  frequencyItem.value = item
  persistEditHint(item)
  frequencyModalOpen.value = true
}

function closeFrequencyModal() {
  frequencyModalOpen.value = false
  frequencyItem.value = null
}

function onFrequencySaved() { closeFrequencyModal() }

const addTaskModalOpen = ref(false)
const addTaskItem = ref(null)

function openAddTaskModal(item) {
  if (!props.data.canEdit) return
  addTaskItem.value = item
  persistEditHint(item)
  addTaskModalOpen.value = true
}

function closeAddTaskModal() {
  addTaskModalOpen.value = false
  addTaskItem.value = null
}

function onTaskAdded() {
  closeAddTaskModal()
  window.location.reload()
}

function taskDetachUrl(task) {
  if (task.detachUrl) return task.detachUrl
  if (task.updateUrl) return task.updateUrl.replace(/\/$/, '') + '/desvincular'
  return ''
}

async function safeReadError(response) {
  try {
    const contentType = response.headers.get('content-type') || ''
    if (contentType.includes('application/json')) {
      const payload = await response.json()
      return payload?.error || payload?.message || ''
    }
    const text = await response.text()
    return text.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 240)
  } catch { return '' }
}

async function detachTask(item, task) {
  if (!props.data.canEdit) return
  const url = taskDetachUrl(task)
  if (!url) {
    showActionError('No está disponible la URL para quitar esta tarea del servicio.')
    return
  }
  const serviceName = item.serviceName || 'este servicio'
  const accepted = await alerts.confirm({
    title: `¿Quitar “${task.name}” de “${serviceName}”?`,
    text: 'La tarea seguirá existiendo en el catálogo y podrá volver a utilizarse.',
    button: 'Quitar',
    cancel: 'Cancelar',
    danger: true,
  })
  if (!accepted) return

  persistEditHint(item)
  const body = new FormData()
  if (props.data.csrf?.name && props.data.csrf?.hash) body.append(props.data.csrf.name, props.data.csrf.hash)
  body.append('tipo_servicio_id', String(item.serviceTypeId))
  body.append('item_id', String(item.id))

  try {
    const response = await fetch(url, {
      method: 'POST', body, credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    })
    if (!response.ok) {
      showActionError((await safeReadError(response)) || 'No se pudo quitar la tarea del servicio.')
      return
    }
    window.location.reload()
  } catch (error) {
    showActionError(error?.message || 'Error de red al quitar la tarea del servicio.')
  }
}

function readStoredHints() {
  if (typeof window === 'undefined') return
  const expand = new Set()
  try {
    for (let i = 0; i < window.localStorage.length; i += 1) {
      const key = window.localStorage.key(i)
      if (!key) continue
      if (key.startsWith('biblioteca:edit:lastItem:')) {
        const suffix = key.slice('biblioteca:edit:lastItem:'.length)
        const sep = suffix.indexOf(':')
        if (sep <= 0) continue
        const templateId = Number(suffix.slice(0, sep))
        const itemId = Number(suffix.slice(sep + 1))
        if (Number.isNaN(itemId)) continue
        if (selectedTemplateId.value === null || templateId === selectedTemplateId.value) expand.add(itemId)
      }
      if (key.startsWith('biblioteca:edit:lastTask:')) window.localStorage.removeItem(key)
    }
  } catch { /* ignore */ }
  if (expand.size > 0) expandedItems.value = new Set([...expandedItems.value, ...expand])
}

onMounted(() => {
  if (templates.value.length > 0 && selectedTemplateId.value === null) selectedTemplateId.value = templates.value[0].id
  readStoredHints()
})

watch(selectedTemplateId, () => readStoredHints())
</script>

<template>
  <div>
    <PageHeading eyebrow="Mantenimiento preventivo" title="Biblioteca preventiva" description="Servicios y tareas del catálogo compartido. Elegí una plantilla para ver sus servicios." :back="{ label: 'Volver a importaciones', href: data.routes.back }" />

    <div class="mb-5 flex flex-wrap items-end gap-3">
      <div class="flex min-w-0 flex-1 flex-col gap-1">
        <label for="library-template" class="text-xs font-semibold text-ink-muted">Plantilla</label>
        <div class="relative">
          <select id="library-template" :value="selectedTemplateId ?? ''" :disabled="templates.length === 0" class="block min-h-11 w-full appearance-none rounded-lg border border-border bg-surface-raised pl-3 pr-9 text-sm font-semibold text-ink shadow-sm focus:border-border-focus focus:outline-none focus:ring-2 focus:ring-primary/15 disabled:cursor-not-allowed disabled:bg-surface-muted" @change="onTemplateChange">
            <option v-if="templates.length === 0" value="" disabled>Sin plantillas importadas</option>
            <option v-for="template in templates" :key="template.id" :value="template.id">{{ template.code }} - {{ template.name }}</option>
          </select>
          <ChevronDownIcon class="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-ink-subtle" aria-hidden="true" />
        </div>
        <p v-if="selectedTemplate" class="font-mono text-xs text-ink-muted">{{ selectedTemplate.code }} - {{ selectedTemplate.equipmentType }} - {{ selectedTemplate.itemCount }} ítems</p>
      </div>
      <div class="flex min-w-0 flex-1 flex-col gap-1">
        <label for="library-search" class="text-xs font-semibold text-ink-muted">Buscar</label>
        <label for="library-search" class="relative block">
          <span class="sr-only">Buscar en la plantilla</span>
          <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-ink-subtle" aria-hidden="true" />
          <input id="library-search" v-model="searchQuery" name="q" type="search" placeholder="Servicio, código o tarea" :class="fieldClass + ' pl-9'" />
        </label>
      </div>
      <a :href="data.routes.downloadTemplate" :class="secondaryButton"><ArrowDownTrayIcon class="mr-2 size-4" aria-hidden="true" />Descargar plantilla</a>
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-2 text-xs">
      <span class="inline-flex items-center gap-1.5 rounded-full bg-surface-muted px-2.5 py-1 font-semibold text-ink-muted">Plantillas<strong class="text-ink">{{ templates.length }}</strong></span>
      <span class="inline-flex items-center gap-1.5 rounded-full bg-surface-muted px-2.5 py-1 font-semibold text-ink-muted">Servicios<strong class="text-ink">{{ serviceCountInTemplate }}</strong></span>
      <span class="inline-flex items-center gap-1.5 rounded-full bg-surface-muted px-2.5 py-1 font-semibold text-ink-muted">Ítems<strong class="text-ink">{{ filteredItems.length }}</strong></span>
      <span v-if="searchActive" class="inline-flex items-center gap-1.5 rounded-full bg-primary/10 px-2.5 py-1 font-semibold text-primary">Filtrado</span>
    </div>

    <EmptyState v-if="items.length === 0" title="Todavía no hay biblioteca importada" description="Importá la biblioteca preventiva desde Excel para crear la primera plantilla y sus servicios." />
    <EmptyState v-else-if="filteredItems.length === 0" :title="searchActive ? 'Sin resultados para la búsqueda' : 'Esta plantilla no tiene servicios'" :description="searchActive ? 'Probá con otro servicio, código o palabra clave dentro de la plantilla seleccionada.' : 'Elegí otra plantilla o importá más servicios para esta.'" />

    <ul v-else class="overflow-hidden rounded-2xl border border-border-subtle bg-white">
      <li v-for="item in filteredItems" :key="item.id" class="border-b border-border-subtle last:border-b-0">
        <div class="flex flex-wrap items-center gap-3 px-4 py-3">
          <button type="button" class="flex min-w-0 flex-1 items-center gap-2 text-left" :aria-expanded="isExpanded(item)" :aria-controls="'library-item-' + item.id" @click="toggleItem(item)">
            <component :is="isExpanded(item) ? ChevronDownIcon : ChevronRightIcon" class="size-4 shrink-0 text-ink-muted" aria-hidden="true" />
            <span class="truncate text-sm font-bold text-ink">{{ item.serviceName }}</span>
            <span class="font-mono text-xs text-ink-muted">{{ item.serviceCode }}</span>
            <StatusBadge :status="item.active ? 'ACTIVO' : 'INACTIVO'" />
            <span class="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-semibold text-primary">{{ formatPriority(item.priority) }}</span>
          </button>
          <p class="font-mono text-xs text-ink">
            <span v-if="formatInterval(item)">{{ formatInterval(item) }}</span><span v-if="formatInterval(item) && formatWarning(item)"> - </span><span v-if="formatWarning(item)">{{ formatWarning(item) }}</span><span v-if="!formatInterval(item) && !formatWarning(item)">Sin frecuencia definida</span>
          </p>
          <button type="button" :class="secondaryButton" :disabled="!data.canEdit || !item.updateUrl" :title="!data.canEdit ? 'Solo lectura' : (!item.updateUrl ? 'Falta URL de edición' : 'Editar frecuencia')" @click="openFrequencyModal(item)"><PencilSquareIcon class="mr-1.5 size-4" aria-hidden="true" />Editar frecuencia</button>
        </div>

        <div v-show="isExpanded(item)" :id="'library-item-' + item.id" class="border-t border-border-subtle bg-surface-subtle/60 px-4 py-3">
          <div class="mb-3 flex items-center justify-between gap-3">
            <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Tareas</p>
            <button v-if="data.canEdit" type="button" :class="secondaryButton" class="!min-h-9 !px-3 !py-1.5 text-xs" @click="openAddTaskModal(item)"><PlusIcon class="mr-1.5 size-4" aria-hidden="true" />Agregar tarea</button>
          </div>

          <p v-if="!item.tasks || item.tasks.length === 0" class="rounded-lg border border-dashed border-border px-3 py-4 text-xs text-ink-muted">Este servicio aún no tiene tareas definidas. Usá <strong>Agregar tarea</strong> para vincular una existente o crear una nueva.</p>
          <ul v-else class="divide-y divide-border-subtle overflow-hidden rounded-lg border border-border-subtle bg-white">
            <li v-for="task in item.tasks" :key="task.id" class="flex flex-wrap items-center gap-3 px-3 py-2 sm:px-4">
              <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold text-ink">{{ task.name }}</p>
                <p class="font-mono text-xs text-ink-muted">{{ task.code }}<span v-if="task.durationMinutes"> - {{ task.durationMinutes }} min</span></p>
              </div>
              <div class="flex flex-wrap items-center gap-1">
                <span v-for="chip in taskChips(task)" :key="task.id + '-' + chip" class="rounded-full bg-surface-muted px-2 py-0.5 text-[11px] font-semibold text-ink-muted">{{ chip }}</span>
              </div>
              <div v-if="data.canEdit" class="flex items-center gap-2">
                <button type="button" :class="primaryButton" class="!min-h-9 !px-3 !py-1.5 text-xs" :disabled="!task.updateUrl" :title="task.updateUrl ? 'Editar tarea' : 'Falta URL de edición'" @click="openTaskModal(item, task)">Editar</button>
                <button type="button" :class="secondaryButton" class="!min-h-9 !px-3 !py-1.5 text-xs" :disabled="!taskDetachUrl(task)" :title="taskDetachUrl(task) ? 'Quitar tarea de este servicio' : 'Falta URL para quitar la tarea'" @click="detachTask(item, task)"><TrashIcon class="mr-1.5 size-4" aria-hidden="true" />Quitar</button>
              </div>
            </li>
          </ul>
        </div>
      </li>
    </ul>

    <TaskEditModal :open="modalOpen" :task="modalTask" :csrf="data.csrf" :can-edit="data.canEdit" @close="closeTaskModal" @saved="onTaskSaved" />
    <FrequencyEditModal :open="frequencyModalOpen" :item="frequencyItem" :csrf="data.csrf" :can-edit="data.canEdit" @close="closeFrequencyModal" @saved="onFrequencySaved" />
    <TaskAddModal :open="addTaskModalOpen" :item="addTaskItem" :csrf="data.csrf" :can-edit="data.canEdit" :import-base-url="data.routes.back" @close="closeAddTaskModal" @saved="onTaskAdded" />
  </div>
</template>
