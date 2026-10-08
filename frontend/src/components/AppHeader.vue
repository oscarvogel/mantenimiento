<script setup>
import {
  Bars3Icon,
  BeakerIcon,
  ChartBarSquareIcon,
  CircleStackIcon,
  Cog6ToothIcon,
  DocumentTextIcon,
  HomeIcon,
  SparklesIcon,
  Squares2X2Icon,
  TruckIcon,
  WrenchScrewdriverIcon,
} from '@heroicons/vue/24/outline'
import { ref } from 'vue'
import AppNotificationBell from './AppNotificationBell.vue'
import ThemeToggle from './ThemeToggle.vue'

defineProps({
  user: {
    type: Object,
    required: true,
  },
  company: {
    type: Object,
    required: true,
  },
  notifications: {
    type: Object,
    default: () => ({ enabled: false, summaryUrl: '#', centerUrl: '#' }),
  },
  homeUrl: {
    type: String,
    default: null,
  },
  moduleNavigation: {
    type: Array,
    default: () => [],
  },
  menuOpen: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['open-menu'])
const menuButton = ref(null)
const moduleIcons = {
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

const moduleIconFor = (name) => moduleIcons[name] ?? Squares2X2Icon

defineExpose({
  focusMenuButton: () => menuButton.value?.focus(),
})
</script>

<template>
  <div class="sticky top-0 z-20 bg-surface-raised">
  <header class="flex h-[4.5rem] items-center border-b border-border bg-surface-raised px-4 sm:px-6 lg:px-7 xl:px-9">
    <button
      ref="menuButton"
      type="button"
      class="ui-interactive mr-3 rounded-lg p-2 text-ink-muted hover:bg-surface-muted hover:text-ink lg:hidden"
      :aria-label="menuOpen ? 'Cerrar menú principal' : 'Abrir menú principal'"
      @click="emit('open-menu')"
      aria-controls="mobile-main-menu"
      :aria-expanded="menuOpen"
    >
      <Bars3Icon class="size-6" aria-hidden="true" />
    </button>

    <div class="min-w-0 flex-1">
      <p class="truncate text-sm font-semibold text-ink">{{ company.name }}</p>
      <p class="mt-0.5 truncate text-xs text-ink-muted">{{ company.scopeLabel }}</p>
    </div>

    <div class="flex items-center gap-1 sm:gap-3">
      <a
        v-if="homeUrl"
        :href="homeUrl"
        class="ui-interactive flex size-10 shrink-0 items-center justify-center rounded-lg text-ink-muted hover:bg-surface-muted hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-border-focus"
        aria-label="Ir al Centro de mandos"
        title="Centro de mandos"
      >
        <Squares2X2Icon class="size-5" aria-hidden="true" />
      </a>
      <ThemeToggle />
      <AppNotificationBell v-if="notifications.enabled" v-bind="notifications" />
      <div class="flex min-w-0 items-center gap-3">
        <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-primary-subtle text-xs font-bold text-primary">
          {{ user.initials }}
        </span>
        <div class="hidden min-w-0 text-left md:block">
          <p class="max-w-40 truncate text-sm font-semibold text-ink">{{ user.name }}</p>
          <p class="max-w-40 truncate text-xs text-ink-muted">{{ user.roleLabel }}</p>
        </div>
      </div>
    </div>
  </header>
  <nav
    v-if="moduleNavigation.length"
    aria-label="Módulos de la plataforma"
    class="border-b border-border bg-surface-raised px-4 sm:px-6 lg:px-7 xl:px-9"
  >
    <ul class="mx-auto flex max-w-[96rem] flex-wrap items-center gap-x-2 gap-y-1.5 py-2.5">
      <li v-for="item in moduleNavigation" :key="item.key" class="shrink-0">
        <a
          v-if="!item.disabled && item.href"
          :href="item.href"
          :aria-current="item.active ? 'page' : undefined"
          class="ui-interactive inline-flex min-h-10 items-center gap-2 rounded-lg border px-3 py-2 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-border-focus"
          :class="item.active
            ? 'border-primary bg-primary text-primary-foreground shadow-sm'
            : 'border-transparent text-ink-muted hover:border-border hover:bg-surface-muted hover:text-ink'"
        >
          <component :is="moduleIconFor(item.icon)" class="size-4 shrink-0" aria-hidden="true" />
          <span>{{ item.label }}</span>
        </a>
        <button
          v-else
          type="button"
          disabled
          aria-disabled="true"
          :aria-label="`${item.label}: ${item.status || 'Sin implementación aún'}`"
          :title="item.status || 'Sin implementación aún'"
          class="inline-flex min-h-10 cursor-not-allowed items-center gap-2 rounded-lg border border-border-subtle bg-surface-muted/70 px-3 py-2 text-sm font-medium text-ink-subtle"
        >
          <component :is="moduleIconFor(item.icon)" class="size-4 shrink-0" aria-hidden="true" />
          <span>{{ item.label }}</span>
          <span class="hidden text-[10px] font-medium lg:inline">{{ item.status || 'Sin implementación aún' }}</span>
        </button>
      </li>
    </ul>
  </nav>
  </div>
</template>
