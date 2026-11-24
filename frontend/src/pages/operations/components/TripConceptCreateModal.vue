<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { fieldClass, primaryButton, secondaryButton } from '../helpers.js'

const props = defineProps({
  open: { type: Boolean, default: false },
  initialName: { type: String, default: '' },
  createUrl: { type: String, default: '' },
  csrf: { type: Object, default: () => ({ name: 'csrf_test_name', hash: '' }) },
  defaultCategory: { type: String, default: 'GASTO' },
  defaultUnit: { type: String, default: 'UNIDAD' },
  restrictDailyEarning: { type: Boolean, default: false },
  currencies: { type: Array, default: () => [] },
})
const emit = defineEmits(['close', 'created'])

const dialog = ref(null)
const name = ref('')
const category = ref('GASTO')
const unit = ref('UNIDAD')
const amount = ref('')
const currency = ref('ARS')
const amountEditable = ref(true)
const saving = ref(false)
const error = ref('')
const nameInput = ref(null)

const categoryOptions = [
  { value: 'GASTO', label: 'Gastos' },
  { value: 'HABER', label: 'Haberes' },
  { value: 'AMBOS', label: 'Gastos y haberes' },
]
const unitOptions = [
  { value: 'DIA', label: 'Día' },
  { value: 'UNIDAD', label: 'Unidad' },
  { value: 'FIJO', label: 'Importe fijo' },
]
const currencyOptions = computed(() => (props.currencies.length ? props.currencies : [{ code: 'ARS', label: 'Peso argentino' }]))
const parsedAmount = computed(() => Number(String(amount.value ?? '').replace(',', '.')))
const canSubmit = computed(() => !saving.value
  && name.value.trim().length >= 2
  && String(amount.value ?? '').trim() !== ''
  && Number.isFinite(parsedAmount.value)
  && parsedAmount.value >= 0)

const reset = () => {
  name.value = props.initialName ?? ''
  // Desde el diario de bordo el concepto tiene que ser un haber por día:
  // es lo único que el diario sabe liquidar.
  category.value = props.restrictDailyEarning ? 'HABER' : props.defaultCategory
  unit.value = props.restrictDailyEarning ? 'DIA' : props.defaultUnit
  amount.value = ''
  currency.value = 'ARS'
  amountEditable.value = true
  error.value = ''
}

const close = () => {
  if (saving.value) return
  dialog.value?.close?.()
}

const save = async () => {
  if (!canSubmit.value) {
    error.value = name.value.trim().length < 2
      ? 'Escribí el nombre del concepto (mínimo 2 letras).'
      : 'Ingresá un importe unitario válido, mayor o igual a cero.'
    return
  }

  saving.value = true
  error.value = ''
  const body = new URLSearchParams({
    [props.csrf.name]: props.csrf.hash,
    nombre: name.value.trim(),
    categoria: category.value,
    unidad: unit.value,
    importe_unitario: String(parsedAmount.value),
    moneda: currency.value,
    permite_editar_importe: amountEditable.value ? '1' : '0',
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
    if (!response.ok || !result.concept) {
      throw new Error(result.message || 'No se pudo dar de alta el concepto.')
    }
    emit('created', result.concept)
    dialog.value?.close?.()
  } catch (exception) {
    error.value = exception.message || 'No se pudo dar de alta el concepto.'
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
          <h2 class="mt-1 text-lg font-bold text-ink">Crear un concepto</h2>
          <p class="mt-1 text-sm text-ink-muted">Queda en el catálogo de tu empresa y sirve para los próximos viajes.</p>
        </div>
        <button type="button" aria-label="Cerrar" class="grid size-9 shrink-0 place-items-center rounded-lg text-ink-muted hover:bg-surface-subtle hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary" :disabled="saving" @click="close">×</button>
      </header>

      <form class="mt-5 space-y-4" @submit.prevent="save">
        <label class="block text-sm font-medium text-ink">
          Nombre del concepto <span class="text-red-700">*</span>
          <input ref="nameInput" v-model="name" type="text" name="nombre" maxlength="120" autocomplete="off" placeholder="Ej.: Viático cena" required :class="[fieldClass, 'mt-1']" />
        </label>

        <div class="grid gap-3 sm:grid-cols-2">
          <label class="block text-sm font-medium text-ink">
            Aplica a <span class="text-red-700">*</span>
            <select v-model="category" name="categoria" :disabled="restrictDailyEarning" :class="[fieldClass, 'mt-1']">
              <option v-for="option in categoryOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
          </label>
          <label class="block text-sm font-medium text-ink">
            Se mide por <span class="text-red-700">*</span>
            <select v-model="unit" name="unidad" :disabled="restrictDailyEarning" :class="[fieldClass, 'mt-1']">
              <option v-for="option in unitOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
          </label>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
          <label class="block text-sm font-medium text-ink">
            Importe unitario <span class="text-red-700">*</span>
            <input v-model="amount" type="number" name="importe_unitario" min="0" step="0.01" placeholder="0,00" required :class="[fieldClass, 'mt-1']" />
          </label>
          <label class="block text-sm font-medium text-ink">
            Moneda
            <select v-model="currency" name="moneda" :class="[fieldClass, 'mt-1']">
              <option v-for="option in currencyOptions" :key="option.code" :value="option.code">{{ option.code }} · {{ option.label }}</option>
            </select>
          </label>
        </div>

        <label class="flex items-center gap-3 rounded-lg border border-border-subtle bg-surface px-3 py-3 text-sm font-medium text-ink">
          <input v-model="amountEditable" type="checkbox" name="permite_editar_importe" class="size-4 rounded border-border text-primary focus:ring-primary" />
          <span>Permitir editar el importe al cargarlo</span>
        </label>

        <p v-if="error" class="rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-sm font-medium text-red-900" role="alert">{{ error }}</p>

        <div class="flex flex-col-reverse gap-2 border-t border-border-subtle pt-4 sm:flex-row sm:justify-end">
          <button type="button" :class="secondaryButton" :disabled="saving" @click="close">Cancelar</button>
          <button type="submit" :class="primaryButton" :disabled="!canSubmit">{{ saving ? 'Guardando…' : 'Guardar concepto' }}</button>
        </div>
      </form>
    </div>
  </dialog>
</template>
