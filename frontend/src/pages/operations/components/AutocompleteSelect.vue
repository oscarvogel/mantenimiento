<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { CheckIcon, ChevronUpDownIcon, XMarkIcon } from '@heroicons/vue/20/solid'
import { fieldClass } from '../helpers.js'

const props = defineProps({
  modelValue: { type: [String, Number, Array, null], default: null },
  options: { type: Array, default: () => [] },
  name: { type: String, required: true },
  placeholder: { type: String, default: 'Seleccioná una opción' },
  multiple: { type: Boolean, default: false },
  required: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  clearable: { type: Boolean, default: true },
  emptyLabel: { type: String, default: 'Sin resultados' },
})
const emit = defineEmits(['update:modelValue'])

const root = ref(null)
const input = ref(null)
const open = ref(false)
const query = ref('')
const highlighted = ref(-1)

const same = (a, b) => String(a ?? '') === String(b ?? '')
const selectedValues = computed(() => {
  if (props.multiple) return Array.isArray(props.modelValue) ? props.modelValue : []
  return props.modelValue === null || props.modelValue === undefined || props.modelValue === '' ? [] : [props.modelValue]
})
const selectedOptions = computed(() => selectedValues.value
  .map((value) => props.options.find((option) => same(option.value, value)))
  .filter(Boolean))
const labelFor = (value) => selectedOptions.value.find((option) => same(option.value, value))?.label ?? String(value ?? '')
const displayText = computed(() => props.multiple ? '' : (selectedOptions.value[0]?.label ?? ''))
const hasSelection = computed(() => selectedValues.value.length > 0)
const filtered = computed(() => {
  const term = query.value.trim().toLowerCase()
  if (term === '') return props.options.slice(0, 50)

  return props.options
    .filter((option) => `${option.label} ${option.hint ?? ''} ${option.value}`.toLowerCase().includes(term))
    .slice(0, 50)
})
const inputValue = computed(() => open.value ? query.value : displayText.value)

const openList = () => {
  if (props.disabled) return
  open.value = true
  query.value = ''
  highlighted.value = filtered.value.length > 0 ? 0 : -1
}
const closeList = () => {
  open.value = false
  query.value = ''
  highlighted.value = -1
}
const onFocus = () => {
  if (!open.value) openList()
}
const onInput = (event) => {
  open.value = true
  query.value = event.target.value
  highlighted.value = filtered.value.length > 0 ? 0 : -1
}
const select = (option) => {
  if (props.multiple) {
    const exists = selectedValues.value.some((value) => same(value, option.value))
    emit('update:modelValue', exists ? selectedValues.value : [...selectedValues.value, option.value])
    query.value = ''
    open.value = true
    highlighted.value = 0
  } else {
    emit('update:modelValue', option.value)
    closeList()
  }
  input.value?.focus()
}
const remove = (value) => {
  if (props.multiple) {
    emit('update:modelValue', selectedValues.value.filter((item) => !same(item, value)))
  } else {
    emit('update:modelValue', null)
  }
  input.value?.focus()
}
const clear = () => {
  emit('update:modelValue', props.multiple ? [] : null)
  closeList()
  input.value?.focus()
}
const toggle = () => {
  if (open.value) closeList()
  else {
    openList()
    input.value?.focus()
  }
}
const onKeydown = (event) => {
  if (event.key === 'ArrowDown') {
    event.preventDefault()
    if (!open.value) openList()
    else highlighted.value = Math.min(filtered.value.length - 1, highlighted.value + 1)
  } else if (event.key === 'ArrowUp') {
    event.preventDefault()
    highlighted.value = Math.max(0, highlighted.value - 1)
  } else if (event.key === 'Enter') {
    event.preventDefault()
    const option = filtered.value[highlighted.value]
    if (option) select(option)
  } else if (event.key === 'Escape') {
    event.preventDefault()
    closeList()
  } else if (event.key === 'Tab') {
    closeList()
  } else if (event.key === 'Backspace' && query.value === '' && props.multiple && hasSelection.value) {
    remove(selectedValues.value[selectedValues.value.length - 1])
  }
}
const onClickOutside = (event) => {
  if (root.value && !root.value.contains(event.target)) closeList()
}

onMounted(() => document.addEventListener('mousedown', onClickOutside))
onBeforeUnmount(() => document.removeEventListener('mousedown', onClickOutside))
</script>

<template>
  <div ref="root" class="relative">
    <div
      :class="[fieldClass, 'flex min-h-11 flex-wrap items-center gap-1.5 py-1.5 pr-16', disabled ? 'cursor-not-allowed opacity-60' : 'cursor-text']"
      @click="input?.focus()"
    >
      <template v-if="multiple">
        <span
          v-for="value in selectedValues"
          :key="value"
          class="inline-flex max-w-full items-center gap-1 rounded-md bg-primary-subtle px-2 py-1 text-xs font-semibold text-primary"
        >
          <span class="truncate">{{ labelFor(value) }}</span>
          <button v-if="!disabled" type="button" class="shrink-0 rounded-sm p-0.5 hover:bg-primary/10" :aria-label="`Quitar ${labelFor(value)}`" @click.stop="remove(value)">
            <XMarkIcon class="size-3" aria-hidden="true" />
          </button>
        </span>
      </template>
      <input
        ref="input"
        type="text"
        :value="inputValue"
        :placeholder="multiple && hasSelection ? '' : placeholder"
        :required="required && !hasSelection"
        :disabled="disabled"
        :aria-expanded="open"
        role="combobox"
        autocomplete="off"
        class="min-w-24 flex-1 border-0 bg-transparent p-0 text-sm text-ink placeholder:text-ink-subtle focus:outline-none focus:ring-0 disabled:cursor-not-allowed"
        @focus="onFocus"
        @input="onInput"
        @keydown="onKeydown"
      />
    </div>

    <button
      v-if="clearable && hasSelection && !disabled"
      type="button"
      class="absolute right-8 top-1/2 -translate-y-1/2 rounded-md p-1 text-ink-muted hover:bg-surface-muted hover:text-ink"
      aria-label="Limpiar selección"
      @mousedown.prevent
      @click.stop="clear"
    >
      <XMarkIcon class="size-4" aria-hidden="true" />
    </button>
    <button
      type="button"
      class="absolute right-2 top-1/2 -translate-y-1/2 rounded-md p-1 text-ink-muted hover:bg-surface-muted hover:text-ink"
      :disabled="disabled"
      aria-label="Abrir opciones"
      @mousedown.prevent
      @click.stop="toggle"
    >
      <ChevronUpDownIcon class="size-4" aria-hidden="true" />
    </button>

    <input v-if="!multiple" type="hidden" :name="name" :value="selectedValues[0] ?? ''" />
    <template v-else>
      <input v-for="value in selectedValues" :key="`hidden-${value}`" type="hidden" :name="name" :value="value" />
    </template>

    <ul v-if="open" class="absolute z-50 mt-1 max-h-64 w-full overflow-auto rounded-lg border border-border bg-surface-raised py-1 shadow-xl" role="listbox">
      <li v-if="filtered.length === 0" class="px-3 py-2 text-sm text-ink-muted">{{ emptyLabel }}</li>
      <li v-for="(option, index) in filtered" :key="option.value">
        <button
          type="button"
          class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm"
          :class="index === highlighted ? 'bg-primary-subtle text-primary' : 'text-ink hover:bg-surface-muted'"
          role="option"
          :aria-selected="selectedValues.some((value) => same(value, option.value))"
          @mousedown.prevent="select(option)"
          @mouseenter="highlighted = index"
        >
          <span class="min-w-0 flex-1">
            <span class="block truncate font-medium">{{ option.label }}</span>
            <span v-if="option.hint" class="block truncate text-xs text-ink-muted">{{ option.hint }}</span>
          </span>
          <CheckIcon v-if="selectedValues.some((value) => same(value, option.value))" class="size-4 shrink-0 text-primary" aria-hidden="true" />
        </button>
      </li>
    </ul>
  </div>
</template>
