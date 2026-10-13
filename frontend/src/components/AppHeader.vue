<script setup>
import {
  Bars3Icon,
  BeakerIcon,
  ChevronDownIcon,
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
import { computed, ref } from 'vue'
import AppNotificationBell from './AppNotificationBell.vue'
import ThemeToggle from './ThemeToggle.vue'

const props = defineProps({
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
const primaryModuleKeys = new Set(['platform-home', 'module-wrench', 'module-truck', 'module-management'])
const primaryModules = computed(() => props.moduleNavigation.filter((item) => primaryModuleKeys.has(item.key)))
const additionalModules = computed(() => props.moduleNavigation.filter((item) => !primaryModuleKeys.has(item.key)))

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
    v-if="props.moduleNavigation.length"
    aria-label="Módulos de la plataforma"
    class="border-b border-border bg-surface-raised px-4 sm:px-6 lg:px-7 xl:px-9"
  >
    <ul class="mx-auto flex max-w-[96rem] flex-nowrap items-center gap-x-1 py-2.5 sm:gap-x-2">
      <li v-for="item in primaryModules" :key="item.key" class="shrink-0">
        <a
          v-if="!item.disabled && item.href"
          :href="item.href"
          :aria-label="item.label"
          :title="item.label"
          :aria-current="item.active ? 'page' : undefined"
          class="ui-interactive inline-flex min-h-10 items-center gap-2 rounded-lg border px-3 py-2 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-border-focus"
          :class="item.active
            ? 'border-primary bg-primary text-primary-foreground shadow-sm'
            : 'border-transparent text-ink-muted hover:border-border hover:bg-surface-muted hover:text-ink'"
        >
          <component :is="moduleIconFor(item.icon)" class="size-4 shrink-0" aria-hidden="true" />
          <span class="hidden sm:inline">{{ item.label }}</span>
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
          <span class="hidden sm:inline">{{ item.label }}</span>
          <span class="hidden text-[10px] font-medium lg:inline">{{ item.status || 'Sin implementación aún' }}</span>
        </button>
      </li>
      <li v-if="additionalModules.length" class="shrink-0">
        <details class="group relative">
          <summary
            class="ui-interactive inline-flex min-h-10 cursor-pointer list-none items-center gap-2 rounded-lg border border-transparent px-3 py-2 text-sm font-semibold text-ink-muted transition-colors hover:border-border hover:bg-surface-muted hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-border-focus [&::-webkit-details-marker]:hidden"
          >
            <Squares2X2Icon class="size-4 shrink-0" aria-hidden="true" />
            <span>Módulos</span>
            <ChevronDownIcon class="size-4 shrink-0 transition-transform group-open:rotate-180" aria-hidden="true" />
          </summary>
          <ul class="absolute left-0 top-full z-30 mt-2 w-72 max-w-[calc(100vw-2rem)] overflow-hidden rounded-xl border border-border bg-surface-raised p-1.5 shadow-xl">
            <li v-for="item in additionalModules" :key="item.key">
              <a
                v-if="!item.disabled && item.href"
                :href="item.href"
                :aria-current="item.active ? 'page' : undefined"
                class="ui-interactive flex min-h-11 items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-border-focus"
                :class="item.active ? 'bg-primary-subtle text-primary' : 'text-ink-muted hover:bg-surface-muted hover:text-ink'"
              >
                <component :is="moduleIconFor(item.icon)" class="size-4 shrink-0" aria-hidden="true" />
                <span class="min-w-0 flex-1">{{ item.label }}</span>
                <span v-if="item.active" class="text-xs font-semibold">Actual</span>
              </a>
              <div
                v-else
                class="flex min-h-11 items-center gap-3 rounded-lg px-3 py-2 text-sm text-ink-subtle"
                :aria-label="`${item.label}: ${item.status || 'Sin implementación aún'}`"
                :title="item.status || 'Sin implementación aún'"
              >
                <component :is="moduleIconFor(item.icon)" class="size-4 shrink-0" aria-hidden="true" />
                <span class="min-w-0 flex-1">{{ item.label }}</span>
                <span class="text-xs">{{ item.status || 'Sin implementación aún' }}</span>
              </div>
            </li>
          </ul>
        </details>
      </li>
    </ul>
  </nav>
  </div>
</template>
