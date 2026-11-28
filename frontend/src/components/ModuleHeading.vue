<script setup>
// Arriba de la barra de un módulo: la vuelta al portal (/inicio) y el nombre del módulo.
// Para cambiar de módulo se pasa por el portal, que es el origen de la navegación (AGENTS.md).
import { computed } from 'vue'
import { ArrowLeftIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  modules: { type: Array, default: () => [] },
  // Shown when the server did not mark an active module.
  fallbackTitle: { type: String, required: true },
})

const portal = computed(() => props.modules.find((item) => item.key === 'platform-home'))
const title = computed(() => props.modules.find((item) => item.active && item.key !== 'platform-home')?.label ?? props.fallbackTitle)
</script>

<template>
  <div class="w-full min-w-0">
    <a
      v-if="portal?.href"
      :href="portal.href"
      class="ui-interactive -mx-1 inline-flex min-h-9 items-center gap-1.5 rounded-md px-1 text-xs font-semibold text-secondary-200 hover:text-white"
    >
      <ArrowLeftIcon class="size-4" aria-hidden="true" />
      Volver al portal
    </a>
    <p class="truncate text-base font-bold text-white">{{ title }}</p>
  </div>
</template>
