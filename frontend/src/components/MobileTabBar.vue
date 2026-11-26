<script setup>
// Phone-only bar with the daily actions within thumb reach. It shows only the items the
// server sent (permissions stay server-side); "Menú" opens the full sidebar.
import { computed } from 'vue'
import { ArrowPathRoundedSquareIcon, Bars3Icon, ClipboardDocumentCheckIcon, ExclamationTriangleIcon, HomeIcon, WrenchScrewdriverIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  navigation: { type: Array, required: true },
  menuOpen: { type: Boolean, default: false },
})
const emit = defineEmits(['open-menu'])

// First key found wins: a technician gets "Mi trabajo"; without it, all orders.
const shortcuts = [
  { keys: ['dashboard', 'trips-operations-dashboard'], label: 'Resumen', icon: HomeIcon },
  { keys: ['quick-readings'], label: 'Km/horas', icon: ArrowPathRoundedSquareIcon },
  // Spec §6.2: a persistent "Informar falla" on the phone. Accent yellow reads on navy.
  { keys: ['report-failure'], label: 'Falla', icon: ExclamationTriangleIcon, accent: true },
  { keys: ['my-work', 'work-orders', 'trips-operations'], label: 'Órdenes', icon: WrenchScrewdriverIcon },
]

const tabs = computed(() => shortcuts
  .map((shortcut) => {
    const item = shortcut.keys
      .map((key) => props.navigation.find((entry) => entry.key === key && !entry.disabled && entry.href))
      .find(Boolean)
    if (!item) return null
    const labels = { 'my-work': 'Mi trabajo', 'trips-operations': 'Viajes' }
    const icon = item.key === 'my-work' ? ClipboardDocumentCheckIcon : shortcut.icon
    return { ...shortcut, label: labels[item.key] ?? shortcut.label, icon, item }
  })
  .filter(Boolean))
// One text color per state: stacking two leaves the winner to the CSS order.
const tabClass = (tab) => {
  if (tab.item.active) return 'bg-white/10 text-white'
  return tab.accent ? 'text-accent-400 hover:text-accent-300' : 'text-secondary-200 hover:text-white'
}
</script>

<template>
  <nav v-if="tabs.length" aria-label="Accesos rápidos" class="mobile-tabbar fixed inset-x-0 bottom-0 z-30 border-t border-white/10 lg:hidden">
    <ul class="mx-auto flex max-w-lg">
      <li v-for="tab in tabs" :key="tab.item.key" class="flex-1">
        <a
          :href="tab.item.href"
          class="ui-interactive flex min-h-16 flex-col items-center justify-center gap-1 text-xs font-semibold"
          :class="tabClass(tab)"
          :aria-current="tab.item.active ? 'page' : undefined"
        >
          <component :is="tab.icon" class="size-6" aria-hidden="true" />
          {{ tab.label }}
        </a>
      </li>
      <li class="flex-1">
        <button
          type="button"
          class="ui-interactive flex min-h-16 w-full flex-col items-center justify-center gap-1 text-xs font-semibold text-secondary-200 hover:text-white"
          aria-controls="mobile-main-menu"
          :aria-expanded="menuOpen ? 'true' : 'false'"
          @click="emit('open-menu', $event.currentTarget)"
        >
          <Bars3Icon class="size-6" aria-hidden="true" />
          Menú
        </button>
      </li>
    </ul>
  </nav>
</template>
