<script setup>
import BaseDialog from '../../../ui/BaseDialog.vue'
import { computed, ref, watch } from 'vue'
import AutocompleteSelect from './AutocompleteSelect.vue'
import { fieldClass, primaryButton, secondaryButton } from '../helpers.js'

const props = defineProps({
  open: { type: Boolean, default: false },
  initialName: { type: String, default: '' },
  createUrl: { type: String, default: '' },
  provincesUrl: { type: String, default: '' },
  csrf: { type: Object, default: () => ({ name: 'csrf_test_name', hash: '' }) },
})
const emit = defineEmits(['close', 'created'])

const name = ref('')
const provinceId = ref('')
const provinces = ref([])
const loadingProvinces = ref(false)
const provincesError = ref('')
const saving = ref(false)
const error = ref('')

const provinceOptions = computed(() => provinces.value.map((province) => ({
  value: String(province.id),
  label: province.label,
})))
const canSubmit = computed(() => !saving.value && name.value.trim().length >= 2 && provinceId.value !== '')

const reset = () => {
  name.value = props.initialName ?? ''
  provinceId.value = ''
  error.value = ''
}

const loadProvinces = async () => {
  if (provinces.value.length > 0 || loadingProvinces.value || !props.provincesUrl) return
  loadingProvinces.value = true
  provincesError.value = ''
  try {
    const response = await fetch(props.provincesUrl, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
    if (!response.ok) throw new Error('No se pudieron cargar las provincias.')
    const result = await response.json()
    provinces.value = Array.isArray(result.provinces) ? result.provinces : []
    if (provinces.value.length === 0) provincesError.value = 'No hay provincias activas para elegir.'
  } catch {
    provincesError.value = 'No se pudieron cargar las provincias. Revisá la conexión e intentá de nuevo.'
  } finally {
    loadingProvinces.value = false
  }
}

const close = () => {
  if (saving.value) return
  emit('close')
}

const save = async () => {
  if (!canSubmit.value) {
    error.value = name.value.trim().length < 2
      ? 'Escribí el nombre de la localidad (mínimo 2 letras).'
      : 'Elegí la provincia de la localidad.'
    return
  }

  saving.value = true
  error.value = ''
  const body = new URLSearchParams({
    [props.csrf.name]: props.csrf.hash,
    nombre: name.value.trim(),
    provincia_id: String(provinceId.value),
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
    if (!response.ok || !result.locality) {
      throw new Error(result.message || 'No se pudo dar de alta la localidad.')
    }
    emit('created', result.locality)
  } catch (exception) {
    error.value = exception.message || 'No se pudo dar de alta la localidad.'
  } finally {
    saving.value = false
  }
}

const retryProvinces = () => {
  provinces.value = []
  void loadProvinces()
}

watch(() => props.open, async (open) => {
  if (!open) return
  reset()
  await loadProvinces()
}, { immediate: true })
</script>

<template>
  <BaseDialog :open="open" eyebrow="Alta rápida" title="Dar de alta una localidad" description="Solo pedimos los datos básicos. El país se toma de la provincia." :persistent="saving" title-id="locality-create-title" @close="close">
        <form class="space-y-4 p-6" @submit.prevent="save">
          <label class="block text-sm font-medium text-ink">
            Nombre de la localidad <span class="text-danger-strong">*</span>
            <input v-model="name" type="text" name="nombre" maxlength="120" autocomplete="off" placeholder="Ej.: Capitán Leonidas" required :class="[fieldClass, 'mt-1']" />
          </label>
          <label class="block text-sm font-medium text-ink">
            Provincia <span class="text-danger-strong">*</span>
            <div class="mt-1">
              <AutocompleteSelect
                v-model="provinceId"
                name="provincia_id"
                :options="provinceOptions"
                :placeholder="loadingProvinces ? 'Cargando provincias…' : 'Buscá la provincia'"
                :disabled="loadingProvinces || saving"
                required
              />
            </div>
          </label>

          <p v-if="provincesError" class="rounded-lg border border-danger/30 bg-danger-subtle px-3 py-2 text-sm font-medium text-danger-strong" role="alert">
            {{ provincesError }}
            <button type="button" class="ml-1 font-semibold underline" @click="retryProvinces">Reintentar</button>
          </p>
          <p v-if="error" class="rounded-lg border border-danger/30 bg-danger-subtle px-3 py-2 text-sm font-medium text-danger-strong" role="alert">{{ error }}</p>
          <p class="text-xs text-ink-muted">Si la localidad ya existe en esa provincia, se reutiliza para no duplicarla.</p>

          <div class="flex flex-col-reverse gap-2 border-t border-border-subtle pt-4 sm:flex-row sm:justify-end">
            <button type="button" :class="secondaryButton" :disabled="saving" @click="close">Cancelar</button>
            <button type="submit" :class="primaryButton" :disabled="!canSubmit">{{ saving ? 'Guardando…' : 'Guardar localidad' }}</button>
          </div>
        </form>
  </BaseDialog>
</template>
