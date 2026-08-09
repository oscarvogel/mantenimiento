<script setup>
defineProps({
  active: {
    type: Boolean,
    required: true,
  },
  activeLabel: {
    type: String,
    default: 'Activo',
  },
  inactiveLabel: {
    type: String,
    default: 'Inactivo',
  },
})
</script>

<template>
  <span
    class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold"
    :class="active ? 'bg-success-subtle text-success-strong' : 'bg-surface-muted text-ink-muted'"
  >
    {{ active ? activeLabel : inactiveLabel }}
  </span>
</template>
