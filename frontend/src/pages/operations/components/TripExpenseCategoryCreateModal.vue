<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { fieldClass, primaryButton, secondaryButton } from '../helpers.js'

const props = defineProps({
  open: { type: Boolean, default: false },
  initialName: { type: String, default: '' },
  createUrl: { type: String, default: '' },
  csrf: { type: Object, default: () => ({ name: 'csrf_test_name', hash: '' }) },
})
const emit = defineEmits(['close', 'created'])

const dialog = ref(null)
const name = ref('')
const saving = ref(false)
const error = ref('')
const nameInput = ref(null)

const canSubmit = computed(() => !saving.value && name.value.trim().length >= 2)

const reset = () => {
  name.value = props.initialName ?? ''
  error.value = ''
}

const close = () => {
  if (saving.value) return
  dialog.value?.close?.()
}

const save = async () => {
  if (!canSubmit.value) {
    error.value = 'Escribí el nombre de la categoría (mínimo 2 letras).'
    return
  }

  saving.value = true
  error.value = ''
  const body = new URLSearchParams({
    [props.csrf.name]: props.csrf.hash,
    nombre: name.value.trim(),
  })
  try {
    const response = await fetch(props.createUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
      },
      body: body.toString(),
    })
    const result = await response.json().catch(() => ({}))
    if (!response.ok || !result.category) {
      throw new Error(result.message || 'No se pudo dar de alta la categoría.')
    }
    emit('created', result.category)
    dialog.value?.close?.()
  } catch (exception) {
    error.value = exception.message || 'No se pudo dar de alta la categoría.'
  } finally {
    saving.value = false
  }
}

watch(() => props.open, async (open) => {
  if (!open) {
    dialog.value?.close?.()
    return
  }
  reset()
  await nextTick()
  dialog.value?.showModal?.()
  nameInput.value?.focus()
}, { immediate: true })
</script>

<template>
  <dialog ref="dialog" class="m-auto w-[min(34rem,calc(100vw-2rem))] max-h-[calc(100vh-2rem)] overflow-y-auto rounded-xl border border-border bg-surface-raised p-0 text-ink shadow-xl backdrop:bg-black/60" @close="emit('close')" @click.self="close">
    <div v-if="open" class="p-5 sm:p-6">
      <header class="flex items-start justify-between gap-4 border-b border-border-subtle pb-4">
        <div>
          <p class="text-xs font-bold uppercase tracking-wide text-primary">Alta rápida</p>
          <h2 class="mt-1 text-lg font-bold text-ink">Dar de alta una categoría de gasto</h2>
          <p class="mt-1 text-sm text-ink-muted">Queda en el catálogo de tu empresa y sirve para los próximos viajes.</p>
        </div>
        <button type="button" aria-label="Cerrar" class="grid size-9 shrink-0 place-items-center rounded-lg text-ink-muted hover:bg-surface-subtle hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary" :disabled="saving" @click="close">✕</button>
      </header>

      <form class="mt-5 space-y-4" @submit.prevent="save">
        <label class="block text-sm font-medium text-ink">
          Nombre de la categoría <span class="text-red-700">*</span>
          <input ref="nameInput" v-model="name" type="text" name="nombre" maxlength="120" autocomplete="off" placeholder="Ej.: Peajes" required :class="[fieldClass, 'mt-1']" />
        </label>

        <p v-if="error" class="rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-sm font-medium text-red-900" role="alert">{{ error }}</p>

        <div class="flex flex-col-reverse gap-2 border-t border-border-subtle pt-4 sm:flex-row sm:justify-end">
          <button type="button" :class="secondaryButton" :disabled="saving" @click="close">Cancelar</button>
          <button type="submit" :class="primaryButton" :disabled="!canSubmit">{{ saving ? 'Guardando…' : 'Guardar categoría' }}</button>
        </div>
      </form>
    </div>
  </dialog>
</template>