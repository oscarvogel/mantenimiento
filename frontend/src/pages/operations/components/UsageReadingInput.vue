<script setup>
import { computed } from 'vue'
import FormField from './FormField.vue'
import { fieldClass, formatHours, formatKilometers, parseFlexibleNumber, readingDelta } from '../helpers.js'

const props = defineProps({
  equipment: { type: Object, required: true },
  modelValue: { type: Object, required: true },
  csrfDisabled: { type: Boolean, default: false },
  showDate: { type: Boolean, default: false },
  showNotes: { type: Boolean, default: false },
  names: { type: Object, default: () => ({ kilometers: 'kilometers', hours: 'hours', recordedAt: 'recordedAt', notes: 'notes' }) },
  labels: { type: Object, default: () => ({ kilometers: 'Kilometraje total actual', hours: 'Horómetro total actual', current: 'Último registrado' }) },
  idPrefix: { type: String, default: 'reading' },
})
const emit = defineEmits(['update:modelValue', 'update:recordedAt', 'update:notes', 'focus-next'])
const update = (key, value) => emit('update:modelValue', { ...props.modelValue, [key]: value })
const deltaText = (key, unit) => {
  const delta = readingDelta(props.modelValue[`current${key === 'kilometers' ? 'Km' : 'Hours'}`], props.modelValue[key])
  if (delta === null) return null
  if (delta === 0) return 'Sin variación'
  return `${delta > 0 ? '+' : ''}${unit === 'km' ? Math.round(delta).toLocaleString('es-AR') : delta.toLocaleString('es-AR', { minimumFractionDigits: 1, maximumFractionDigits: 1 })} ${unit}`
}
const warning = (key) => {
  const current = parseFlexibleNumber(props.modelValue[`current${key === 'kilometers' ? 'Km' : 'Hours'}`])
  const next = parseFlexibleNumber(props.modelValue[key])
  return current !== null && next !== null && next < current
}
const hoursInvalid = computed(() => props.modelValue.hours !== '' && parseFlexibleNumber(props.modelValue.hours) === null)
const kmDelta = computed(() => deltaText('kilometers', 'km'))
const hoursDelta = computed(() => deltaText('hours', 'h'))
</script>

<template>
  <div class="grid gap-3 sm:grid-cols-2">
    <FormField v-if="equipment.controlsKm" :label="labels.kilometers" :for-id="`${idPrefix}-km`">
      <span class="mb-1 block text-xs font-normal text-ink-muted">{{ labels.current }}: {{ formatKilometers(modelValue.currentKm) }}</span>
      <input :id="`${idPrefix}-km`" data-reading-input="true" :name="names.kilometers" type="text" inputmode="numeric" autocomplete="off" :value="modelValue.kilometers" placeholder="Ingresá el total del equipo" :disabled="csrfDisabled" :class="fieldClass" @keydown.enter.prevent="emit('focus-next', $event)" @input="update('kilometers', $event.target.value)" />
      <p v-if="kmDelta" class="mt-1 text-xs" :class="warning('kilometers') ? 'text-danger-strong' : 'text-ink-muted'">{{ warning('kilometers') ? 'El valor es menor al último registro.' : kmDelta }}</p>
    </FormField>
    <FormField v-if="equipment.controlsHours" :label="labels.hours" :for-id="`${idPrefix}-hours`">
      <span class="mb-1 block text-xs font-normal text-ink-muted">{{ labels.current }}: {{ formatHours(modelValue.currentHours) }}</span>
      <input :id="`${idPrefix}-hours`" data-reading-input="true" :name="names.hours" type="text" inputmode="decimal" autocomplete="off" :value="modelValue.hours" placeholder="Ingresá el total del equipo" :disabled="csrfDisabled" :class="fieldClass" @keydown.enter.prevent="emit('focus-next', $event)" @input="update('hours', $event.target.value)" />
      <p v-if="hoursInvalid" class="mt-1 text-xs text-danger-strong">El horómetro debe ser un número positivo con un decimal como máximo. Podés usar coma o punto.</p>
      <p v-else-if="hoursDelta" class="mt-1 text-xs" :class="warning('hours') ? 'text-danger-strong' : 'text-ink-muted'">{{ warning('hours') ? 'El valor es menor al último registro.' : hoursDelta }}</p>
    </FormField>
    <FormField v-if="showDate" label="Fecha y hora de la lectura" :for-id="`${idPrefix}-date`" class="sm:col-span-2">
      <input :id="`${idPrefix}-date`" :name="names.recordedAt" type="datetime-local" :value="modelValue.recordedAt" :disabled="csrfDisabled" :class="fieldClass" @input="emit('update:recordedAt', $event.target.value)" />
    </FormField>
    <FormField v-if="showNotes" label="Observación opcional" :for-id="`${idPrefix}-notes`" class="sm:col-span-2">
      <input :id="`${idPrefix}-notes`" :name="names.notes" maxlength="1000" :value="modelValue.notes" :disabled="csrfDisabled" :class="fieldClass" @input="emit('update:notes', $event.target.value)" />
    </FormField>
  </div>
</template>
