<script setup>
import { ClipboardDocumentCheckIcon } from '@heroicons/vue/24/outline'

defineProps({
  actionUrl: {
    type: String,
    required: true,
  },
})
</script>

<template>
  <div class="flex flex-col items-center px-6 py-14 text-center">
    <span class="flex size-12 items-center justify-center rounded-full bg-primary-subtle text-primary">
      <ClipboardDocumentCheckIcon class="size-6" aria-hidden="true" />
    </span>
    <h3 class="mt-4 text-base font-semibold text-ink">No hay mantenimientos próximos</h3>
    <p class="mt-1 max-w-sm text-sm text-ink-muted">
      Cuando existan servicios programados o vencidos, aparecerán en este listado.
    </p>
    <a
      v-if="actionUrl !== '#'"
      :href="actionUrl"
      class="mt-5 rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground shadow-sm hover:bg-primary-hover active:bg-primary-active"
    >
      Ver planes de mantenimiento
    </a>
  </div>
</template>
