<template>
  <div role="status" aria-live="polite" aria-label="Cargando panel" class="animate-pulse">
    <div class="h-7 w-56 rounded bg-surface-muted"></div>
    <div class="mt-3 h-4 w-80 max-w-full rounded bg-surface-muted"></div>
    <div class="mt-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
      <div v-for="index in 3" :key="index" class="h-44 rounded-xl border border-border bg-white p-6">
        <div class="h-4 w-24 rounded bg-surface-muted"></div>
        <div class="mt-4 h-9 w-16 rounded bg-surface-muted"></div>
        <div class="mt-7 h-4 w-28 rounded bg-surface-muted"></div>
      </div>
    </div>
    <div class="mt-6 h-72 rounded-xl border border-border bg-white"></div>
    <span class="sr-only">Cargando información de mantenimiento</span>
  </div>
</template>
