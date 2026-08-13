<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { XMarkIcon } from '@heroicons/vue/24/outline'
import CsrfInput from './CsrfInput.vue'
import FormField from './FormField.vue'
import { fieldClass, primaryButton, secondaryButton } from '../helpers.js'

const props = defineProps({
  open: { type: Boolean, required: true },
  task: { type: Object, default: null },
  csrf: { type: Object, required: true },
  canEdit: { type: Boolean, default: false },
})

const emit = defineEmits(['close', 'saved'])

const form = ref(null)
const errorMessage = ref('')
const submitting = ref(false)
const localTask = ref(null)

const storageKey = (taskId) => 'biblioteca:edit:lastTask:' + taskId

watch(
  () => props.open,
  (next) => {
    if (!next) return
    errorMessage.value = ''
    submitting.value = false
    if (!props.task) {
      localTask.value = null
      return
    }
    localTask.value = {
      id: props.task.id,
      serviceTypeId: props.task.serviceTypeId,
      name: props.task.name ?? '',
      code: props.task.code ?? '',
      description: props.task.description ?? '',
      procedure: props.task.procedure ?? '',
      order: props.task.order ?? 1,
      durationMinutes: props.task.durationMinutes ?? '',
      observations: props.task.observations ?? '',
      mandatory: !!props.task.mandatory,
      active: !!props.task.active,
      requiresPart: !!props.task.requiresPart,
      requiresControl: !!props.task.requiresControl,
      requiresPhoto: !!props.task.requiresPhoto,
    }
    nextTick(() => {
      const firstField = form.value?.querySelector('input:not([type="hidden"]), textarea, select')
      firstField?.focus()
    })
  },
)

const title = computed(() => {
  if (!localTask.value) return 'Editar tarea'
  return localTask.value.name ? 'Editar: ' + localTask.value.name : 'Editar tarea'
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

function persistExpandHint(taskId) {
  if (typeof window === 'undefined' || !props.task) return
  try {
    window.localStorage.setItem(storageKey(taskId), '1')
  } catch {
    /* ignore */
  }
}

async function onSubmit(event) {
  event.preventDefault()
  if (!props.canEdit || !props.task || !form.value) return
  errorMessage.value = ''
  submitting.value = true
  persistExpandHint(props.task.id)
  try {
    const data = new FormData(form.value)
    const response = await fetch(props.task.updateUrl, {
      method: 'POST',
      body: data,
      credentials: 'same-origin',
      redirect: 'follow',
    })
    if (response.redirected) {
      window.location.assign(response.url)
      return
    }
    if (!response.ok) {
      errorMessage.value = (await safeReadError(response)) || 'No se pudo guardar la tarea.'
      submitting.value = false
      return
    }
    let payload = null
    try {
      payload = await response.json()
    } catch {
      /* ignore */
    }
    emit('saved', { task: localTask.value, payload })
    submitting.value = false
  } catch (err) {
    errorMessage.value = err?.message || 'Error de red al guardar la tarea.'
    submitting.value = false
  }
}

async function safeReadError(response) {
  try {
    const ct = response.headers.get('content-type') || ''
    if (ct.includes('application/json')) {
      const j = await response.json()
      return j?.error || j?.message || ''
    }
    const t = await response.text()
    return t.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 240)
  } catch {
    return ''
  }
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open && localTask"
      class="fixed inset-0 z-50 flex items-end justify-center bg-ink/50 px-4 py-6 sm:items-center"
      role="dialog"
      aria-modal="true"
      :aria-labelledby="'task-modal-title-' + localTask.id"
      tabindex="-1"
      @click="onBackdrop"
      @keydown="onKeydown"
    >
      <div class="flex w-full max-w-2xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
        <header class="flex items-start justify-between gap-3 border-b border-border-subtle px-5 py-4">
          <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Edición de tarea</p>
            <h2 :id="'task-modal-title-' + localTask.id" class="truncate text-base font-bold text-ink">
              {{ title }}
            </h2>
            <p v-if="localTask.code" class="mt-0.5 font-mono text-xs text-ink-muted">{{ localTask.code }}</p>
          </div>
          <button
            type="button"
            :class="secondaryButton"
            class="!min-h-9 !px-2"
            aria-label="Cerrar"
            :disabled="submitting"
            @click="close"
          >
            <XMarkIcon class="size-4" aria-hidden="true" />
          </button>
        </header>
        <form
          ref="form"
          class="flex max-h-[70vh] flex-col"
          @submit="onSubmit"
        >
          <CsrfInput :csrf="csrf" />
          <input type="hidden" name="tipo_servicio_id" :value="localTask.serviceTypeId" />
          <div class="grid flex-1 gap-3 overflow-y-auto px-5 py-4 md:grid-cols-2">
            <FormField label="Nombre" :for-id="'task-modal-' + localTask.id + '-name'" class="md:col-span-2">
              <input
                :id="'task-modal-' + localTask.id + '-name'"
                name="nombre"
                type="text"
                maxlength="150"
                required
                v-model="localTask.name"
                :disabled="!canEdit"
                :class="fieldClass"
              />
            </FormField>
            <FormField label="Orden" :for-id="'task-modal-' + localTask.id + '-order'">
              <input
                :id="'task-modal-' + localTask.id + '-order'"
                name="orden"
                type="number"
                min="1"
                required
                v-model.number="localTask.order"
                :disabled="!canEdit"
                :class="fieldClass"
              />
            </FormField>
            <FormField label="Duración estimada (min)" :for-id="'task-modal-' + localTask.id + '-duration'">
              <input
                :id="'task-modal-' + localTask.id + '-duration'"
                name="duracion_estimada_min"
                type="number"
                min="0"
                v-model="localTask.durationMinutes"
                :disabled="!canEdit"
                :class="fieldClass"
              />
            </FormField>
            <FormField label="Descripción" :for-id="'task-modal-' + localTask.id + '-description'" class="md:col-span-2">
              <textarea
                :id="'task-modal-' + localTask.id + '-description'"
                name="descripcion"
                rows="2"
                v-model="localTask.description"
                :disabled="!canEdit"
                :class="fieldClass"
              ></textarea>
            </FormField>
            <FormField label="Procedimiento" :for-id="'task-modal-' + localTask.id + '-procedure'" class="md:col-span-2">
              <textarea
                :id="'task-modal-' + localTask.id + '-procedure'"
                name="procedimiento"
                rows="3"
                v-model="localTask.procedure"
                :disabled="!canEdit"
                :class="fieldClass"
              ></textarea>
            </FormField>
            <FormField label="Observaciones de la relación" :for-id="'task-modal-' + localTask.id + '-observations'" class="md:col-span-2">
              <textarea
                :id="'task-modal-' + localTask.id + '-observations'"
                name="observaciones"
                rows="2"
                maxlength="500"
                v-model="localTask.observations"
                :disabled="!canEdit"
                :class="fieldClass"
              ></textarea>
            </FormField>
            <div class="flex flex-wrap items-center gap-x-5 gap-y-2 md:col-span-2">
              <label class="flex items-center gap-2 text-sm font-semibold text-ink">
                <input
                  name="obligatoria"
                  type="checkbox"
                  value="1"
                  v-model="localTask.mandatory"
                  :disabled="!canEdit"
                  class="size-4 rounded border-border text-primary focus:ring-primary/20"
                />Obligatoria
              </label>
              <label class="flex items-center gap-2 text-sm font-semibold text-ink">
                <input
                  name="activo"
                  type="checkbox"
                  value="1"
                  v-model="localTask.active"
                  :disabled="!canEdit"
                  class="size-4 rounded border-border text-primary focus:ring-primary/20"
                />Activa
              </label>
              <label class="flex items-center gap-2 text-sm font-semibold text-ink">
                <input
                  name="requiere_repuesto"
                  type="checkbox"
                  value="1"
                  v-model="localTask.requiresPart"
                  :disabled="!canEdit"
                  class="size-4 rounded border-border text-primary focus:ring-primary/20"
                />Requiere repuesto
              </label>
              <label class="flex items-center gap-2 text-sm font-semibold text-ink">
                <input
                  name="requiere_control"
                  type="checkbox"
                  value="1"
                  v-model="localTask.requiresControl"
                  :disabled="!canEdit"
                  class="size-4 rounded border-border text-primary focus:ring-primary/20"
                />Requiere control
              </label>
              <label class="flex items-center gap-2 text-sm font-semibold text-ink">
                <input
                  name="requiere_foto"
                  type="checkbox"
                  value="1"
                  v-model="localTask.requiresPhoto"
                  :disabled="!canEdit"
                  class="size-4 rounded border-border text-primary focus:ring-primary/20"
                />Requiere foto
              </label>
            </div>
            <p
              v-if="errorMessage"
              class="rounded-md bg-danger-subtle px-3 py-2 text-sm text-danger-strong md:col-span-2"
              role="alert"
            >
              {{ errorMessage }}
            </p>
          </div>
          <footer class="flex flex-wrap items-center justify-end gap-2 border-t border-border-subtle bg-surface-subtle px-5 py-3">
            <button
              type="button"
              :class="secondaryButton"
              :disabled="submitting"
              @click="close"
            >Cancelar</button>
            <button
              type="submit"
              :class="primaryButton"
              :disabled="submitting || !canEdit"
            >{{ submitting ? 'Guardando…' : 'Guardar tarea' }}</button>
          </footer>
        </form>
      </div>
    </div>
  </Teleport>
</template>
