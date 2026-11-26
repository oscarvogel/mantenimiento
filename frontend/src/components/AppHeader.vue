<script setup>
import { Bars3Icon, Squares2X2Icon } from '@heroicons/vue/24/outline'
import { computed, ref } from 'vue'
import AccountMenu from './AccountMenu.vue'
import AppNotificationBell from './AppNotificationBell.vue'

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
  menuOpen: {
    type: Boolean,
    default: false,
  },
  // Solo llega si el servidor habilita la búsqueda de equipos para este usuario.
  equipmentSearchUrl: {
    type: String,
    default: null,
  },
  logout: {
    type: Object,
    default: null,
  },
})

const firstName = computed(() => String(props.user.name ?? '').trim().split(/\s+/)[0] ?? '')
const companyLine = computed(() => [props.company.name, props.company.scopeLabel].filter(Boolean).join(' · '))

const emit = defineEmits(['open-menu'])
const menuButton = ref(null)

defineExpose({
  focusMenuButton: () => menuButton.value?.focus(),
})
</script>

<template>
  <div class="sticky top-0 z-20 bg-surface-subtle">
  <header class="flex flex-wrap items-center gap-x-4 gap-y-3 px-4 py-4 sm:px-6 lg:px-7 xl:px-9">
    <button
      ref="menuButton"
      type="button"
      class="ui-interactive rounded-lg p-2 text-ink-muted hover:bg-surface-muted hover:text-ink lg:hidden"
      :aria-label="menuOpen ? 'Cerrar menú principal' : 'Abrir menú principal'"
      @click="emit('open-menu', $event.currentTarget)"
      aria-controls="mobile-main-menu"
      :aria-expanded="menuOpen"
    >
      <Bars3Icon class="size-6" aria-hidden="true" />
    </button>

    <div class="flex min-w-0 flex-1 items-center gap-3 md:flex-none">
      <span data-test="user-avatar" class="flex size-12 shrink-0 items-center justify-center rounded-full bg-accent-400 text-base font-bold text-secondary-900" aria-hidden="true">{{ user.initials }}</span>
      <div class="min-w-0">
        <p class="truncate text-lg font-semibold text-ink">Hola, {{ firstName }}</p>
        <p class="truncate text-xs text-ink-muted">{{ companyLine }}</p>
      </div>
    </div>

    <div v-if="equipmentSearchUrl" class="order-last w-full md:order-none md:mx-auto md:w-auto md:max-w-md md:flex-1">
      <slot name="search" />
    </div>

    <div class="ml-auto flex items-center gap-2 sm:gap-3">
      <a
        v-if="homeUrl"
        :href="homeUrl"
        class="ui-interactive flex size-11 shrink-0 items-center justify-center rounded-full bg-surface-raised text-ink-muted shadow-card hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-border-focus"
        aria-label="Ir al Centro de mandos"
        title="Centro de mandos"
      >
        <Squares2X2Icon class="size-5" aria-hidden="true" />
      </a>
      <AppNotificationBell v-if="notifications.enabled" v-bind="notifications" />
      <slot name="assistant" />
      <AccountMenu :user="user" :logout="logout" />
    </div>
  </header>
  </div>
</template>
