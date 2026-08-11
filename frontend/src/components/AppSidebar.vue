<script setup>
import {
  ArrowRightStartOnRectangleIcon,
  ArrowUpTrayIcon,
  BuildingOffice2Icon,
  BuildingStorefrontIcon,
  CalendarDaysIcon,
  ChartBarSquareIcon,
  ClipboardDocumentCheckIcon,
  HomeIcon,
  TruckIcon,
  UsersIcon,
  WrenchScrewdriverIcon,
  XMarkIcon,
} from '@heroicons/vue/24/outline'
import BrandMark from './BrandMark.vue'

defineProps({
  navigation: {
    type: Array,
    required: true,
  },
  logout: {
    type: Object,
    default: null,
  },
  mobile: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['close'])

const icons = {
  dashboard: HomeIcon,
  truck: TruckIcon,
  equipment: TruckIcon,
  wrench: WrenchScrewdriverIcon,
  maintenance: WrenchScrewdriverIcon,
  calendar: CalendarDaysIcon,
  services: ClipboardDocumentCheckIcon,
  building: BuildingOffice2Icon,
  upload: ArrowUpTrayIcon,
  branches: BuildingStorefrontIcon,
  users: UsersIcon,
  chart: ChartBarSquareIcon,
  workshop: BuildingOffice2Icon,
  workshops: BuildingOffice2Icon,
  reports: ChartBarSquareIcon,
}

const iconFor = (name) => icons[name] ?? ClipboardDocumentCheckIcon
</script>

<template>
  <aside class="flex h-full w-[17rem] flex-col bg-surface-inverse text-ink-inverse">
    <div class="flex h-[4.75rem] items-center justify-between border-b border-brand-800 px-6">
      <BrandMark />
      <button
        v-if="mobile"
        type="button"
        class="rounded-md p-2 text-brand-200 hover:bg-brand-900 hover:text-white lg:hidden"
        aria-label="Cerrar menú principal"
        @click="emit('close')"
      >
        <XMarkIcon class="size-6" aria-hidden="true" />
      </button>
    </div>

    <nav aria-label="Navegación principal" class="flex-1 overflow-y-auto px-4 py-6">
      <p class="mb-3 px-3 text-[0.6875rem] font-semibold uppercase tracking-[0.16em] text-brand-300">
        Operación
      </p>
      <ul class="space-y-1">
        <li v-for="item in navigation" :key="item.key">
          <span
            v-if="item.disabled"
            class="flex min-h-11 cursor-not-allowed items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-brand-400"
            aria-disabled="true"
          >
            <component :is="iconFor(item.icon)" class="size-5 shrink-0" aria-hidden="true" />
            <span class="min-w-0 flex-1 truncate">{{ item.label }}</span>
            <span v-if="item.badge" class="rounded-full bg-brand-800 px-2 py-0.5 text-[0.6875rem] font-bold text-brand-200">
              {{ item.badge }}
            </span>
          </span>
          <a
            v-else
            :href="item.href"
            class="group flex min-h-11 items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors"
            :class="
              item.active
                ? 'bg-brand-800 text-white shadow-inner'
                : 'text-brand-100 hover:bg-brand-900 hover:text-white'
            "
            :aria-current="item.active ? 'page' : undefined"
            @click="emit('close')"
          >
            <component
              :is="iconFor(item.icon)"
              class="size-5 shrink-0"
              :class="item.active ? 'text-accent' : 'text-brand-300 group-hover:text-brand-100'"
              aria-hidden="true"
            />
            <span class="min-w-0 flex-1 truncate">{{ item.label }}</span>
            <span v-if="item.badge" class="rounded-full bg-brand-700 px-2 py-0.5 text-[0.6875rem] font-bold text-white">
              {{ item.badge }}
            </span>
          </a>
        </li>
      </ul>
    </nav>

    <div v-if="logout" class="border-t border-brand-800 p-4">
      <form v-if="logout.method === 'post'" :action="logout.href" method="post">
        <input v-if="logout.csrfName" type="hidden" :name="logout.csrfName" :value="logout.csrfValue" />
        <button
          type="submit"
          class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-brand-200 transition-colors hover:bg-brand-900 hover:text-white"
        >
          <ArrowRightStartOnRectangleIcon class="size-5" aria-hidden="true" />
          Cerrar sesión
        </button>
      </form>
      <a
        v-else
        :href="logout.href"
        class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-brand-200 transition-colors hover:bg-brand-900 hover:text-white"
      >
        <ArrowRightStartOnRectangleIcon class="size-5" aria-hidden="true" />
        Cerrar sesión
      </a>
    </div>
  </aside>
</template>
