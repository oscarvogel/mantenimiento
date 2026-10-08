<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import {
  ArrowRightStartOnRectangleIcon,
  ArrowUpTrayIcon,
  ArrowPathRoundedSquareIcon,
  ChevronDownIcon,
  BeakerIcon,
  BellIcon,
  BuildingOffice2Icon,
  BuildingStorefrontIcon,
  CalendarDaysIcon,
  ChartBarSquareIcon,
  ClipboardDocumentCheckIcon,
  ClipboardDocumentListIcon,
  CircleStackIcon,
  Cog6ToothIcon,
  DocumentTextIcon,
  HomeIcon,
  MapIcon,
  SparklesIcon,
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

const primaryKeys = ['dashboard', 'equipment', 'reading-control', 'maintenance', 'plans', 'reports']
const isPlatformNavigation = computed(() => props.navigation.some((item) => item.key === 'platform-home'))

const primaryNavigation = computed(() => (
  isPlatformNavigation.value
    ? props.navigation
    : primaryKeys
      .map((key) => props.navigation.find((item) => item.key === key))
      .filter(Boolean)
))

const secondaryNavigation = computed(() => (
  isPlatformNavigation.value ? [] : props.navigation.filter((item) => !primaryKeys.includes(item.key))
))

const secondaryOpen = ref(secondaryNavigation.value.some((item) => item.active))

const toggleSecondary = () => {
  secondaryOpen.value = !secondaryOpen.value
}

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
  'clipboard-list': ClipboardDocumentListIcon,
  workshop: BuildingOffice2Icon,
  workshops: BuildingOffice2Icon,
  providers: BuildingOffice2Icon,
  reports: ChartBarSquareIcon,
  audit: ClipboardDocumentListIcon,
  notifications: BellIcon,
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

const iconFor = (name) => icons[name] ?? ClipboardDocumentCheckIcon
const visibleLabel = (item) => {
  if (item.key === 'plans') return 'Preventivos'
  if (item.key === 'services') return 'Servicios'
  return item.label
}
const customIconBaseUrl = document.body?.dataset?.baseUrl ?? ''
const currentTheme = ref(document.documentElement.dataset.theme ?? 'light')
const failedCustomIcons = ref(new Set())
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
const customIconKey = (item) => `${currentTheme.value}:${customIconName(item) ?? ''}`
const customIconUrl = (item) => {
  const name = customIconName(item)
  const variant = currentTheme.value === 'dark' ? 'dark' : 'light'
  return name && customIconBaseUrl && !failedCustomIcons.value.has(customIconKey(item))
    ? `${customIconBaseUrl}assets/brand/icons/${variant}/${name}.svg`
    : null
}
const markCustomIconFailed = (item) => {
  const next = new Set(failedCustomIcons.value)
  next.add(customIconKey(item))
  failedCustomIcons.value = next
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
  <aside
    class="ui-sidebar-surface flex h-full w-full flex-col bg-surface-raised text-ink"
    :class="{ 'platform-sidebar': isPlatformNavigation }"
  >
    <div
      class="flex items-center justify-between border-b border-border px-5"
      :class="isPlatformNavigation ? 'platform-brand-row h-28' : 'h-[4.5rem]'"
    >
      <div v-if="isPlatformNavigation" class="platform-brand" aria-label="Vogel Consultoría">
        <span class="platform-brand__mark" aria-hidden="true">V</span>
        <span class="platform-brand__name">VOGEL <small>CONSULTORÍA</small></span>
      </div>
      <BrandMark v-else />
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
      <ul class="space-y-1">
          <li v-for="item in primaryNavigation" :key="item.key">
            <span
              v-if="item.disabled"
              class="flex min-h-11 cursor-not-allowed items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-ink-subtle"
              :class="{ 'platform-nav-disabled': isPlatformNavigation }"
              aria-disabled="true"
              :aria-label="`${item.label}: ${item.status ?? item.badge ?? 'No disponible'}`"
            >
              <img
                v-if="customIconUrl(item)"
                :src="customIconUrl(item)"
                alt=""
                class="size-6 shrink-0"
                width="24"
                height="24"
                aria-hidden="true"
                @error="markCustomIconFailed(item)"
              />
              <component v-else :is="iconFor(item.icon)" class="size-5 shrink-0" aria-hidden="true" />
              <span class="min-w-0 flex-1 leading-5" :title="item.status ? `${item.label}: ${item.status}` : item.label">
                <span class="block">{{ visibleLabel(item) }}</span>
                <span v-if="isPlatformNavigation && item.status" class="platform-nav-status block text-[0.625rem] leading-4">{{ item.status }}</span>
              </span>
              <span v-if="item.badge" class="rounded-full bg-surface-muted px-2 py-0.5 text-[0.5rem] font-bold leading-3 text-ink-muted" :class="{ 'platform-nav-badge': isPlatformNavigation }">
                {{ item.badge }}
              </span>
            </span>
            <a
              v-else
              :href="item.href"
              class="ui-nav-item group flex min-h-11 items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium"
              :class="[
                isPlatformNavigation ? 'platform-nav-item' : '',
                item.active
                  ? (isPlatformNavigation ? 'platform-nav-active' : 'bg-primary-subtle text-primary shadow-inner')
                  : (isPlatformNavigation ? 'text-blue-100 hover:bg-white/10 hover:text-white' : 'text-ink-muted hover:bg-surface-muted hover:text-ink'),
              ]"
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
                @error="markCustomIconFailed(item)"
              />
              <component
                v-else
                :is="iconFor(item.icon)"
                class="size-5 shrink-0"
                :class="item.active ? 'text-primary' : 'text-ink-subtle group-hover:text-ink'"
                aria-hidden="true"
              />
              <span class="min-w-0 flex-1 leading-5" :title="item.status ? `${item.label}: ${item.status}` : item.label">
                <span class="block">{{ visibleLabel(item) }}</span>
                <span v-if="isPlatformNavigation && item.status" class="platform-nav-status block text-[0.625rem] leading-4">{{ item.status }}</span>
              </span>
              <span v-if="item.badge" class="rounded-full bg-primary px-2 py-0.5 text-[0.5rem] font-bold leading-3 text-primary-foreground" :class="{ 'platform-nav-badge': isPlatformNavigation }">
                {{ item.badge }}
              </span>
            </a>
          </li>
      </ul>

      <section v-if="secondaryNavigation.length > 0" class="mt-5 border-t border-border-subtle pt-4">
        <button
          type="button"
          class="ui-interactive flex min-h-11 w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left text-sm font-semibold text-ink-muted hover:bg-surface-muted hover:text-ink"
          :aria-expanded="secondaryOpen ? 'true' : 'false'"
          aria-controls="secondary-navigation"
          @click="toggleSecondary"
        >
          <ClipboardDocumentListIcon class="size-5 shrink-0 text-ink-subtle" aria-hidden="true" />
          <span class="min-w-0 flex-1">Más opciones</span>
          <ChevronDownIcon
            class="size-4 shrink-0 transition-transform"
            :class="secondaryOpen ? 'rotate-180' : ''"
            aria-hidden="true"
          />
        </button>

        <ul v-show="secondaryOpen" id="secondary-navigation" class="mt-1 space-y-1 pl-2">
          <li v-for="item in secondaryNavigation" :key="item.key">
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
                @error="markCustomIconFailed(item)"
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
                @error="markCustomIconFailed(item)"
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
          <li v-if="showDemoEntry">
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

    <div v-if="isPlatformNavigation" class="platform-sidebar-note mx-4 mb-4 rounded-xl border border-white/10 px-3 py-3">
      <p class="text-sm font-semibold text-white">Una plataforma para crecer</p>
      <p class="mt-1 text-xs leading-5 text-blue-200">Los módulos se habilitan a medida que están implementados.</p>
    </div>

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
            @error="markCustomIconFailed(logout)"
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
          @error="markCustomIconFailed(logout)"
        />
        <ArrowRightStartOnRectangleIcon v-else class="size-5" aria-hidden="true" />
        Cerrar sesión
      </a>
    </div>
  </aside>
</template>
