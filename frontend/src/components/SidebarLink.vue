<script setup>
// One row of the navy sidebar: link, current page or a module not available yet.
import * as uiLabels from '../ui/labels.js'

const props = defineProps({
  item: { type: Object, required: true },
  label: { type: String, required: true },
  active: { type: Boolean, default: false },
  icon: { type: [Object, Function], required: true },
  showStatus: { type: Boolean, default: false },
  nested: { type: Boolean, default: false },
  compact: { type: Boolean, default: false },
  // Una sola marca amarilla por página: si hay dos, el navegador cancela la transición.
  marker: { type: Boolean, default: true },
})
const emit = defineEmits(['navigate'])
</script>

<template>
  <span
    v-if="props.item.disabled"
    class="platform-nav-disabled flex min-h-11 cursor-not-allowed items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium"
    aria-disabled="true"
    :aria-label="`${label}: ${uiLabels.humanizeCode(props.item.status ?? props.item.badge ?? 'No disponible')}`"
  >
    <component :is="icon" class="size-5 shrink-0" aria-hidden="true" />
    <span class="min-w-0 flex-1 leading-5">
      <span class="block">{{ label }}</span>
      <span v-if="showStatus && props.item.status" class="platform-nav-status block text-xs leading-4">{{ uiLabels.humanizeCode(props.item.status) }}</span>
    </span>
    <span v-if="props.item.badge" class="platform-nav-badge rounded-full px-2 py-0.5 text-xs font-bold leading-4">{{ props.item.badge }}</span>
  </span>
  <a
    v-else
    :href="props.item.href"
    class="ui-nav-item platform-nav-item group flex items-center gap-3 rounded-lg px-3 text-sm font-medium"
    :class="[
      nested ? 'min-h-10 py-2' : 'min-h-11 py-2.5',
      active ? 'platform-nav-active' : 'text-secondary-100 hover:bg-white/10 hover:text-white',
      compact ? 'justify-center focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent-300' : '',
    ]"
    :aria-current="active ? 'page' : undefined"
    @click="emit('navigate')"
  >
    <span v-if="active && marker" class="platform-nav-marker" data-test="nav-marker" aria-hidden="true" />
    <component
      :is="icon"
      class="shrink-0"
      :class="[nested ? 'size-4' : 'size-5', active ? 'text-white' : 'text-secondary-300 group-hover:text-white']"
      aria-hidden="true"
    />
    <span :class="compact ? 'sr-only' : 'min-w-0 flex-1 leading-5'">
      <span class="block">{{ label }}</span>
      <!-- Solo los módulos del portal traen descripción: dice qué se hace en cada uno. -->
      <span v-if="!compact && props.item.description" class="mt-0.5 block text-xs font-normal leading-4 text-white/65">{{ props.item.description }}</span>
    </span>
    <span v-if="props.item.badge" :class="compact ? 'sr-only' : 'platform-nav-badge rounded-full px-2 py-0.5 text-xs font-bold leading-4'">{{ props.item.badge }}</span>
  </a>
</template>
