<script setup>
defineProps({
  compact: {
    type: Boolean,
    default: false,
  },
})

const baseUrl = document.body?.dataset?.baseUrl ?? '/'
const logoUrl = `${baseUrl}assets/brand/logo-mark.svg`
</script>

<template>
  <div class="flex min-w-0 items-center gap-3">
    <img
      :src="logoUrl"
      alt=""
      class="size-10 shrink-0 rounded-lg"
      width="40"
      height="40"
      aria-hidden="true"
    />
    <div v-if="!compact" class="min-w-0">
      <p class="truncate text-base font-bold tracking-tight text-ink-inverse">Mantenimiento</p>
      <p class="truncate text-xs font-medium text-brand-200">Gestión de flota</p>
    </div>
  </div>
</template>
