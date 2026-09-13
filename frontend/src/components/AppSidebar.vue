<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import {
  ArrowRightStartOnRectangleIcon,
  ArrowUpTrayIcon,
  ArrowPathRoundedSquareIcon,
  BeakerIcon,
  BellIcon,
  BuildingOffice2Icon,
  BuildingStorefrontIcon,
  CalendarDaysIcon,
  ChartBarSquareIcon,
  ClipboardDocumentCheckIcon,
  ClipboardDocumentListIcon,
  HomeIcon,
  TruckIcon,
  UsersIcon,
  WrenchScrewdriverIcon,
  XMarkIcon,
} from '@heroicons/vue/24/outline'
import BrandMark from './BrandMark.vue'

const props = defineProps({
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

const navigationGroups = computed(() => {
  const definitions = [
    { key: 'operation', label: 'Operación', items: ['dashboard', 'equipment', 'quick-readings', 'plans', 'maintenance'] },
    { key: 'management', label: 'Gestión', items: ['employees', 'expirations', 'notifications', 'imports', 'preventive-library', 'reports'] },
    { key: 'masters', label: 'Maestros', items: ['masters-expirations'] },
    { key: 'administration', label: 'Administración', items: ['superadmin', 'chatbot-audit', 'branches', 'users'] },
  ]
  const knownKeys = new Set(definitions.flatMap((group) => group.items))
  const groups = definitions
    .map((group) => ({ ...group, items: props.navigation.filter((item) => group.items.includes(item.key)) }))
    .filter((group) => group.items.length > 0)
  const remaining = props.navigation.filter((item) => !knownKeys.has(item.key))

  if (remaining.length > 0) groups.push({ key: 'other', label: 'Más', items: remaining })

  return groups
})

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
  readings: ArrowPathRoundedSquareIcon,
  workshop: BuildingOffice2Icon,
  workshops: BuildingOffice2Icon,
  reports: ChartBarSquareIcon,
  audit: ClipboardDocumentListIcon,
  notifications: BellIcon,
}

const iconFor = (name) => icons[name] ?? ClipboardDocumentCheckIcon
const visibleLabel = (item) => item.key === 'services' ? 'Servicios' : item.label
const customIconBaseUrl = document.body?.dataset?.baseUrl ?? ''
const currentTheme = ref(document.documentElement.dataset.theme ?? 'light')
const customIconNames = {
  dashboard: 'inicio',
  equipment: 'equipos',
  'quick-readings': 'lecturas',
  plans: 'planes',
  maintenance: 'mantenimiento',
  employees: 'empleados',
  notifications: 'notificaciones',
  imports: 'importaciones',
  'preventive-library': 'biblioteca-preventiva',
  reports: 'reportes',
  superadmin: 'superadmin',
  'chatbot-audit': 'auditoria-ia',
  branches: 'sucursales',
  users: 'usuarios',
  'work-orders': 'ordenes-trabajo',
  services: 'servicios-mantenimiento',
  logout: 'cerrar-sesion',
}
const customIconName = (item) => customIconNames[item.key] ?? customIconNames[item.icon] ?? null
const customIconUrl = (item) => {
  const name = customIconName(item)
  const variant = currentTheme.value === 'dark' ? 'dark' : 'light'
  return name && customIconBaseUrl ? `${customIconBaseUrl}assets/brand/icons/${variant}/${name}.svg` : null
}
const logoutIconUrl = computed(() => customIconUrl({ key: 'logout', icon: 'logout' }))
const showDemoEntry = computed(() => props.navigation.some((item) => item.key === 'superadmin'))

const syncTheme = (event) => {
  currentTheme.value = event.detail?.theme ?? document.documentElement.dataset.theme ?? 'light'
}

onMounted(() => window.addEventListener('maintenance:theme-change', syncTheme))
onBeforeUnmount(() => window.removeEventListener('maintenance:theme-change', syncTheme))

const openDemoCompany = () => {
  window.dispatchEvent(new CustomEvent('maintenance:open-demo-company'))
  emit('close')
}
</script>

<template>
  <aside class="ui-sidebar-surface flex h-full w-full flex-col bg-surface-raised text-ink">
    <div class="flex h-[4.5rem] items-center justify-between border-b border-border px-5">
      <BrandMark />
      <button
        v-if="mobile"
        type="button"
        class="ui-interactive rounded-md p-2 text-ink-muted hover:bg-surface-muted hover:text-ink lg:hidden"
        aria-label="Cerrar menú principal"
        @click="emit('close')"
      >
        <XMarkIcon class="size-6" aria-hidden="true" />
      </button>
    </div>

    <nav aria-label="Navegación principal" class="ui-sidebar-scroll flex-1 overflow-y-auto px-3 py-5">
      <section v-for="group in navigationGroups" :key="group.key" class="mb-5 last:mb-0">
        <h2 class="mb-2 px-3 text-[0.625rem] font-semibold uppercase tracking-[0.14em] text-primary">
          {{ group.label }}
        </h2>
        <ul class="space-y-1">
          <li v-for="item in group.items" :key="item.key">
            <span
              v-if="item.disabled"
              class="flex min-h-11 cursor-not-allowed items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-ink-subtle"
              aria-disabled="true"
            >
              <img
                v-if="customIconUrl(item)"
                :src="customIconUrl(item)"
                alt=""
                class="size-6 shrink-0"
                width="24"
                height="24"
                aria-hidden="true"
              />
              <component v-else :is="iconFor(item.icon)" class="size-5 shrink-0" aria-hidden="true" />
              <span class="min-w-0 flex-1 leading-5" :title="item.label">{{ visibleLabel(item) }}</span>
              <span v-if="item.badge" class="rounded-full bg-surface-muted px-2 py-0.5 text-[0.6875rem] font-bold text-ink-muted">
                {{ item.badge }}
              </span>
            </span>
            <a
              v-else
              :href="item.href"
              class="ui-nav-item group flex min-h-11 items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium"
              :class="item.active ? 'bg-primary-subtle text-primary shadow-inner' : 'text-ink-muted hover:bg-surface-muted hover:text-ink'"
              :aria-current="item.active ? 'page' : undefined"
              @click="emit('close')"
            >
              <img
                v-if="customIconUrl(item)"
                :src="customIconUrl(item)"
                alt=""
                class="size-6 shrink-0"
                width="24"
                height="24"
                aria-hidden="true"
              />
              <component
                v-else
                :is="iconFor(item.icon)"
                class="size-5 shrink-0"
                :class="item.active ? 'text-primary' : 'text-ink-subtle group-hover:text-ink'"
                aria-hidden="true"
              />
              <span class="min-w-0 flex-1 leading-5" :title="item.label">{{ visibleLabel(item) }}</span>
              <span v-if="item.badge" class="rounded-full bg-primary px-2 py-0.5 text-[0.6875rem] font-bold text-primary-foreground">
                {{ item.badge }}
              </span>
            </a>
          </li>
          <li v-if="group.key === 'administration' && showDemoEntry">
            <button
              type="button"
              class="ui-interactive group flex min-h-11 w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left text-sm font-medium text-ink-muted hover:bg-surface-muted hover:text-ink"
              @click="openDemoCompany"
            >
              <BeakerIcon class="size-5 shrink-0 text-ink-subtle group-hover:text-ink" aria-hidden="true" />
              <span class="min-w-0 flex-1 truncate">Empresa demo</span>
            </button>
          </li>
        </ul>
      </section>
    </nav>

    <div v-if="logout" class="border-t border-border p-4">
      <form v-if="logout.method === 'post'" :action="logout.href" method="post">
        <input v-if="logout.csrfName" type="hidden" :name="logout.csrfName" :value="logout.csrfValue" />
        <button
          type="submit"
          class="ui-interactive flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-ink-muted hover:bg-surface-muted hover:text-ink"
        >
          <img
            v-if="logoutIconUrl"
            :src="logoutIconUrl"
            alt=""
            class="size-6 shrink-0"
            width="24"
            height="24"
            aria-hidden="true"
          />
          <ArrowRightStartOnRectangleIcon v-else class="size-5" aria-hidden="true" />
          Cerrar sesión
        </button>
      </form>
      <a
        v-else
        :href="logout.href"
        class="ui-interactive flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-ink-muted hover:bg-surface-muted hover:text-ink"
      >
        <img
          v-if="logoutIconUrl"
          :src="logoutIconUrl"
          alt=""
          class="size-6 shrink-0"
          width="24"
          height="24"
          aria-hidden="true"
        />
        <ArrowRightStartOnRectangleIcon v-else class="size-5" aria-hidden="true" />
        Cerrar sesión
      </a>
    </div>
  </aside>
</template>
