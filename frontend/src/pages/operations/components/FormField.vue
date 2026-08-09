<script setup>
defineProps({ label: { type: String, required: true }, forId: { type: String, required: true }, hint: { type: String, default: '' } })
</script>

<template>
  <div>
    <label :for="forId" class="mb-1.5 block text-sm font-semibold text-ink">{{ label }}</label>
    <slot></slot>
    <p v-if="hint" class="mt-1.5 text-xs text-ink-muted">{{ hint }}</p>
  </div>
</template>
