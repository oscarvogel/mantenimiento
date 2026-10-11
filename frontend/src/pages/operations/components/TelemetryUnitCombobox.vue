<script setup>
import { computed, nextTick, ref, useId } from 'vue'
import { fieldClass } from '../helpers.js'

const props = defineProps({
  units: { type: Array, required: true },
  modelValue: { type: String, default: '' },
  persistedValue: { type: String, default: '' },
  usedUnitIds: { type: Array, default: () => [] },
  fieldName: { type: String, required: true },
  label: { type: String, required: true },
})

const emit = defineEmits(['update:modelValue'])
const inputId = `telemetry-unit-combobox-${useId()}`
const query = ref('')
const isOpen = ref(false)
const activeIndex = ref(0)
const listbox = ref(null)
const selectedUnit = computed(() => props.units.find((unit) => unit.id === props.modelValue) ?? null)
const filteredUnits = computed(() => {
  const needle = query.value.trim().toLocaleLowerCase('es-AR')
  return props.units
    .filter((unit) => !needle || `${unit.name} ${unit.id}`.toLocaleLowerCase('es-AR').includes(needle))
    .slice(0, 20)
})
const activeOptionId = computed(() => filteredUnits.value[activeIndex.value] ? `${inputId}-option-${activeIndex.value}` : undefined)

const openList = () => {
  query.value = ''
  activeIndex.value = 0
  isOpen.value = true
}

const updateQuery = (event) => {
  query.value = event.target.value
  activeIndex.value = 0
  isOpen.value = true
}

const choose = (unit) => {
  if (props.usedUnitIds.includes(unit.id) && unit.id !== props.modelValue) return
  emit('update:modelValue', unit.id)
  isOpen.value = false
  query.value = ''
}

const clear = () => {
  emit('update:modelValue', props.persistedValue ? '__unlink__' : '')
  isOpen.value = false
  query.value = ''
}

const scrollActiveOptionIntoView = async () => {
  await nextTick()
  listbox.value?.children[activeIndex.value]?.scrollIntoView?.({ block: 'nearest' })
}

const onKeydown = (event) => {
  if (event.key === 'Escape') {
    isOpen.value = false
    query.value = ''
    return
  }
  if (event.key === 'ArrowDown' && isOpen.value) {
    event.preventDefault()
    activeIndex.value = Math.min(activeIndex.value + 1, filteredUnits.value.length - 1)
    scrollActiveOptionIntoView()
    return
  }
  if (event.key === 'ArrowUp' && isOpen.value) {
    event.preventDefault()
    activeIndex.value = Math.max(activeIndex.value - 1, 0)
    scrollActiveOptionIntoView()
    return
  }
  if (event.key === 'Enter' && isOpen.value && filteredUnits.value[activeIndex.value]) {
    event.preventDefault()
    choose(filteredUnits.value[activeIndex.value])
  }
}
</script>

<template>
  <div class="relative min-w-56">
    <div class="flex gap-2">
      <input
        :id="inputId"
        type="search"
        role="combobox"
        aria-autocomplete="list"
        aria-haspopup="listbox"
        :aria-expanded="isOpen"
        :aria-controls="`${inputId}-listbox`"
        :aria-activedescendant="activeOptionId"
        :aria-label="label"
        :value="isOpen ? query : selectedUnit?.name ?? ''"
        :placeholder="selectedUnit?.name ?? 'Buscar patente o unidad…'"
        :class="fieldClass"
        autocomplete="off"
        @focus="openList"
        @input="updateQuery"
        @keydown="onKeydown"
        @blur="isOpen = false"
      />
      <button
        v-if="selectedUnit"
        type="button"
        class="ui-interactive inline-flex min-h-11 shrink-0 items-center justify-center rounded-lg border border-border px-3 text-xs font-semibold text-ink-muted hover:bg-surface-muted focus:outline-none focus:ring-2 focus:ring-primary/20"
        :aria-label="`Quitar unidad seleccionada para ${label}`"
        @mousedown.prevent
        @click="clear"
      >
        Quitar
      </button>
    </div>

    <input type="hidden" :name="fieldName" :value="modelValue">

    <div
      v-if="isOpen"
      :id="`${inputId}-listbox`"
      ref="listbox"
      role="listbox"
      :aria-label="`Unidades de Wialon para ${label}`"
      class="absolute z-30 mt-1 max-h-64 w-full overflow-y-auto rounded-lg border border-border bg-surface-raised p-1 shadow-xl"
      @mousedown.prevent
    >
      <button
        v-for="(unit, index) in filteredUnits"
        :id="`${inputId}-option-${index}`"
        :key="unit.id"
        type="button"
        role="option"
        :aria-selected="unit.id === modelValue"
        :disabled="usedUnitIds.includes(unit.id) && unit.id !== modelValue"
        class="ui-interactive block min-h-10 w-full rounded-md px-3 py-2 text-left text-sm text-ink hover:bg-surface-muted focus:bg-surface-muted focus:outline-none disabled:cursor-not-allowed disabled:opacity-45"
        :class="index === activeIndex ? 'bg-surface-muted' : ''"
        @click="choose(unit)"
      >
        {{ unit.name }}
      </button>
      <p v-if="filteredUnits.length === 0" class="px-3 py-3 text-sm text-ink-muted">No hay unidades que coincidan con esa búsqueda.</p>
    </div>
  </div>
</template>
