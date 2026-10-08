<script setup>
import BrandLogo from './BrandLogo.vue'

defineProps({
  compact: {
    type: Boolean,
    default: false,
  },
})

</script>

<template>
  <div class="flex min-w-0 items-center gap-3">
    <BrandLogo size="sidebar" />
    <div v-if="!compact" class="min-w-0">
      <p class="truncate text-base font-bold tracking-tight text-ink">Mantenimiento</p>
      <p class="truncate text-xs font-medium text-ink-muted">Gestión de flota</p>
    </div>
  </div>
</template>
