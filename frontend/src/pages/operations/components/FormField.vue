<script setup>
import { computed } from 'vue'

const props = defineProps({
  label: { type: String, required: true },
  forId: { type: String, required: true },
  hint: { type: String, default: '' },
  error: { type: String, default: '' },
})

const hintId = computed(() => `${props.forId}-hint`)
const errorId = computed(() => `${props.forId}-error`)
// Bind these on the control: <FormField v-slot="field"><input v-bind="field.control" ...>
const control = computed(() => ({
  'aria-describedby': [props.hint && hintId.value, props.error && errorId.value].filter(Boolean).join(' ') || undefined,
  'aria-invalid': props.error ? 'true' : undefined,
}))
</script>

<template>
  <div>
    <label :for="forId" class="mb-1.5 block text-sm font-semibold text-ink">{{ label }}</label>
    <slot :control="control"></slot>
    <p v-if="hint" :id="hintId" class="mt-1.5 text-xs text-ink-muted">{{ hint }}</p>
    <p v-if="error" :id="errorId" class="mt-1.5 text-xs font-semibold text-danger-strong" role="alert">{{ error }}</p>
  </div>
</template>
