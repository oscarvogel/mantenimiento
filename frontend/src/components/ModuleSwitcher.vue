<script setup>
// Top of the module sidebar: the way back to the portal (/inicio) and the switch to
// another module. It replaces the old module bar under the header (AGENTS.md: the
// portal is the origin and every module shows a visible return to it).
import { computed } from 'vue'
import {
  ArrowLeftIcon,
  BeakerIcon,
  ChartBarSquareIcon,
  ChevronUpDownIcon,
  CircleStackIcon,
  Cog6ToothIcon,
  DocumentTextIcon,
  HomeIcon,
  SparklesIcon,
  Squares2X2Icon,
  TruckIcon,
  WrenchScrewdriverIcon,
} from '@heroicons/vue/24/outline'
import * as uiLabels from '../ui/labels.js'

const props = defineProps({
  modules: { type: Array, default: () => [] },
  // Shown when the server did not mark an active module.
  fallbackTitle: { type: String, required: true },
})

const icons = {
  'platform-home': HomeIcon,
  'module-wrench': WrenchScrewdriverIcon,
  'module-truck': TruckIcon,
  'module-fuel': BeakerIcon,
  'module-tires': CircleStackIcon,
  'module-billing': DocumentTextIcon,
  'module-management': ChartBarSquareIcon,
  'module-reports': ChartBarSquareIcon,
  'module-automations': Cog6ToothIcon,
  'module-ai': SparklesIcon,
}
const iconFor = (name) => icons[name] ?? Squares2X2Icon

const portal = computed(() => props.modules.find((item) => item.key === 'platform-home'))
const current = computed(() => props.modules.find((item) => item.active && item.key !== 'platform-home'))
const others = computed(() => props.modules.filter((item) => item.key !== 'platform-home' && !item.active))
const title = computed(() => current.value?.label ?? props.fallbackTitle)
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
    <details v-if="others.length" class="group relative">
      <summary
        class="ui-interactive -mx-2 flex min-h-11 cursor-pointer list-none items-center gap-2 rounded-lg px-2 hover:bg-white/10 [&::-webkit-details-marker]:hidden"
        aria-label="Cambiar de módulo"
      >
        <span class="min-w-0 flex-1">
          <span class="block truncate text-base font-bold text-white">{{ title }}</span>
          <span class="block truncate text-xs text-secondary-200">Cambiar de módulo</span>
        </span>
        <ChevronUpDownIcon class="size-5 shrink-0 text-secondary-300" aria-hidden="true" />
      </summary>
      <ul class="absolute inset-x-0 top-full z-40 mt-2 rounded-xl border border-border bg-surface-raised p-1.5 text-ink shadow-xl">
        <li v-for="item in others" :key="item.key">
          <a
            v-if="!item.disabled && item.href"
            :href="item.href"
            class="ui-interactive flex min-h-11 items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-ink hover:bg-surface-muted"
          >
            <component :is="iconFor(item.icon)" class="size-5 shrink-0 text-ink-muted" aria-hidden="true" />
            <span class="min-w-0 flex-1">{{ item.label }}</span>
          </a>
          <div
            v-else
            class="flex min-h-11 items-center gap-3 rounded-lg px-3 py-2 text-sm text-ink-subtle"
            :aria-label="`${item.label}: ${uiLabels.humanizeCode(item.status || 'Próximamente')}`"
          >
            <component :is="iconFor(item.icon)" class="size-5 shrink-0" aria-hidden="true" />
            <span class="min-w-0 flex-1">{{ item.label }}</span>
            <span class="text-xs">{{ uiLabels.humanizeCode(item.status || 'Próximamente') }}</span>
          </div>
        </li>
      </ul>
    </details>
    <p v-else class="truncate text-base font-bold text-white">{{ title }}</p>
  </div>
</template>
