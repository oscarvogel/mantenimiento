<script setup>
import BaseDialog from '../../../ui/BaseDialog.vue'
import { computed, ref, watch } from 'vue'
import { fieldClass, primaryButton, secondaryButton } from '../helpers.js'

const props = defineProps({
  open: { type: Boolean, default: false },
  initialName: { type: String, default: '' },
  createUrl: { type: String, default: '' },
  csrf: { type: Object, default: () => ({ name: 'csrf_test_name', hash: '' }) },
})
const emit = defineEmits(['close', 'created'])

const name = ref('')
const saving = ref(false)
const error = ref('')

const canSubmit = computed(() => !saving.value && name.value.trim().length >= 2)

const reset = () => {
  name.value = props.initialName ?? ''
  error.value = ''
}

const close = () => {
  if (saving.value) return
  emit('close')
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
    emit('close')
  } catch (exception) {
    error.value = exception.message || 'No se pudo dar de alta la categoría.'
  } finally {
    saving.value = false
  }
}

// BaseDialog shows the modal and focuses the first field.
watch(() => props.open, (open) => {
  if (open) reset()
}, { immediate: true })
</script>

<template>
  <BaseDialog :open="open" eyebrow="Alta rápida" title="Dar de alta una categoría de gasto" description="Queda en el catálogo de tu empresa y sirve para los próximos viajes." :persistent="saving" @close="close">
      <form class="space-y-4 p-6" @submit.prevent="save">
        <label class="block text-sm font-medium text-ink">
          Nombre de la categoría <span class="text-danger-strong">*</span>
          <input v-model="name" type="text" name="nombre" maxlength="120" autocomplete="off" placeholder="Ej.: Peajes" required :class="[fieldClass, 'mt-1']" />
        </label>

        <p v-if="error" class="rounded-lg border border-danger/30 bg-danger-subtle px-3 py-2 text-sm font-medium text-danger-strong" role="alert">{{ error }}</p>

        <div class="flex flex-col-reverse gap-2 border-t border-border-subtle pt-4 sm:flex-row sm:justify-end">
          <button type="button" :class="secondaryButton" :disabled="saving" @click="close">Cancelar</button>
          <button type="submit" :class="primaryButton" :disabled="!canSubmit">{{ saving ? 'Guardando…' : 'Guardar categoría' }}</button>
        </div>
      </form>
  </BaseDialog>
</template>