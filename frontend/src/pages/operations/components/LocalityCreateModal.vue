<script setup>
import { computed, nextTick, ref, watch } from 'vue'
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
const nameInput = ref(null)

const provinceOptions = computed(() => provinces.value)
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
  await nextTick()
  nameInput.value?.focus()
  await loadProvinces()
}, { immediate: true })
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="fixed inset-0 z-50 flex items-end justify-center bg-black/60 p-0 sm:items-center sm:p-6" @click.self="close" @keydown.esc="close">
      <section class="max-h-[92vh] w-full max-w-lg overflow-y-auto rounded-t-2xl border border-border bg-surface-raised p-5 shadow-2xl sm:rounded-2xl sm:p-6" role="dialog" aria-modal="true" aria-labelledby="locality-create-title">
        <header class="flex items-start justify-between gap-4">
          <div>
            <p class="text-xs font-bold uppercase tracking-wide text-primary">Alta rápida</p>
            <h2 id="locality-create-title" class="mt-1 text-xl font-semibold text-ink">Dar de alta una localidad</h2>
            <p class="mt-1 text-sm text-ink-muted">Solo pedimos los datos básicos. El país se toma de la provincia.</p>
          </div>
          <button type="button" class="grid size-9 shrink-0 place-items-center rounded-lg text-ink-muted hover:bg-surface-subtle hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary" aria-label="Cerrar" :disabled="saving" @click="close">×</button>
        </header>

        <form class="mt-5 space-y-4" @submit.prevent="save">
          <label class="block text-sm font-medium text-ink">
            Nombre de la localidad <span class="text-red-700">*</span>
            <input ref="nameInput" v-model="name" type="text" name="nombre" maxlength="120" autocomplete="off" placeholder="Ej.: Capitán Leonidas" required :class="[fieldClass, 'mt-1']" />
          </label>
          <label class="block text-sm font-medium text-ink">
            Provincia <span class="text-red-700">*</span>
            <select v-model="provinceId" name="provincia_id" required :disabled="loadingProvinces || saving" :class="[fieldClass, 'mt-1']">
              <option value="">{{ loadingProvinces ? 'Cargando provincias…' : 'Elegí la provincia' }}</option>
              <option v-for="province in provinceOptions" :key="province.id" :value="String(province.id)">{{ province.label }}</option>
            </select>
          </label>

          <p v-if="provincesError" class="rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-sm font-medium text-red-900" role="alert">
            {{ provincesError }}
            <button type="button" class="ml-1 font-semibold underline" @click="retryProvinces">Reintentar</button>
          </p>
          <p v-if="error" class="rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-sm font-medium text-red-900" role="alert">{{ error }}</p>
          <p class="text-xs text-ink-muted">Si la localidad ya existe en esa provincia, se reutiliza para no duplicarla.</p>

          <div class="flex flex-col-reverse gap-2 border-t border-border-subtle pt-4 sm:flex-row sm:justify-end">
            <button type="button" :class="secondaryButton" :disabled="saving" @click="close">Cancelar</button>
            <button type="submit" :class="primaryButton" :disabled="!canSubmit">{{ saving ? 'Guardando…' : 'Guardar localidad' }}</button>
          </div>
        </form>
      </section>
    </div>
  </Teleport>
</template>
