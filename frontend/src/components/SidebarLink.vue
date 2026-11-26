<script setup>
// One row of the navy sidebar: link, current page or a module not available yet.
import * as uiLabels from '../ui/labels.js'

const props = defineProps({
  item: { type: Object, required: true },
  label: { type: String, required: true },
  active: { type: Boolean, default: false },
  icon: { type: [Object, Function], required: true },
  // Brand SVG drawn for dark backgrounds; null falls back to the Heroicon.
  iconUrl: { type: String, default: null },
  showStatus: { type: Boolean, default: false },
  nested: { type: Boolean, default: false },
})
const emit = defineEmits(['navigate', 'icon-error'])
</script>

<template>
  <span
    v-if="props.item.disabled"
    class="platform-nav-disabled flex min-h-11 cursor-not-allowed items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium"
    aria-disabled="true"
    :aria-label="`${label}: ${uiLabels.humanizeCode(props.item.status ?? props.item.badge ?? 'No disponible')}`"
  >
    <img v-if="iconUrl" :src="iconUrl" alt="" class="size-6 shrink-0" width="24" height="24" aria-hidden="true" @error="emit('icon-error')" />
    <component :is="icon" v-else class="size-5 shrink-0" aria-hidden="true" />
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
    ]"
    :aria-current="active ? 'page' : undefined"
    @click="emit('navigate')"
  >
    <img v-if="iconUrl" :src="iconUrl" alt="" class="size-6 shrink-0" width="24" height="24" aria-hidden="true" @error="emit('icon-error')" />
    <component
      :is="icon"
      v-else
      class="shrink-0"
      :class="[nested ? 'size-4' : 'size-5', active ? 'text-white' : 'text-secondary-300 group-hover:text-white']"
      aria-hidden="true"
    />
    <span class="min-w-0 flex-1 leading-5" :title="props.item.status ? `${label}: ${uiLabels.humanizeCode(props.item.status)}` : label">
      <span class="block">{{ label }}</span>
      <span v-if="showStatus && props.item.status" class="platform-nav-status block text-xs leading-4">{{ uiLabels.humanizeCode(props.item.status) }}</span>
    </span>
    <span v-if="props.item.badge" class="platform-nav-badge rounded-full px-2 py-0.5 text-xs font-bold leading-4">{{ props.item.badge }}</span>
  </a>
</template>
