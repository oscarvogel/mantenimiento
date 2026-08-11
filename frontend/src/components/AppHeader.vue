<script setup>
import { Bars3Icon } from '@heroicons/vue/24/outline'
import AppNotificationBell from './AppNotificationBell.vue'

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
})

const emit = defineEmits(['open-menu'])
</script>

<template>
  <header class="sticky top-0 z-20 flex h-[4.75rem] items-center border-b border-border bg-surface-raised px-4 sm:px-6 lg:px-8">
    <button
      type="button"
      class="mr-3 rounded-lg p-2 text-ink-muted hover:bg-surface-muted hover:text-ink lg:hidden"
      aria-label="Abrir menú principal"
      @click="emit('open-menu')"
    >
      <Bars3Icon class="size-6" aria-hidden="true" />
    </button>

    <div class="min-w-0 flex-1">
      <p class="truncate text-sm font-semibold text-ink">{{ company.name }}</p>
      <p class="mt-0.5 truncate text-xs text-ink-muted">{{ company.scopeLabel }}</p>
    </div>

    <div class="flex items-center gap-1 sm:gap-3">
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
</template>
