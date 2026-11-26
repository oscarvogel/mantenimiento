<script setup>
// The only navigation of the app (docs/DESIGN_TOKENS.md, "Navegación"): navy in both
// themes, the module switch and the way back to the portal on top, and the module's own
// items grouped by daily task.
import { computed, ref, watch } from 'vue'
import {
  ArrowPathRoundedSquareIcon,
  ArrowRightStartOnRectangleIcon,
  ArrowUpTrayIcon,
  BeakerIcon,
  BellIcon,
  BuildingOffice2Icon,
  BuildingStorefrontIcon,
  CalendarDaysIcon,
  ChartBarSquareIcon,
  ChevronDownIcon,
  CircleStackIcon,
  ClipboardDocumentCheckIcon,
  ClipboardDocumentListIcon,
  Cog6ToothIcon,
  DocumentTextIcon,
  ExclamationTriangleIcon,
  HomeIcon,
  LinkIcon,
  MapIcon,
  SparklesIcon,
  TruckIcon,
  UsersIcon,
  WrenchScrewdriverIcon,
  XMarkIcon,
} from '@heroicons/vue/24/outline'
import BrandLogo from './BrandLogo.vue'
import ModuleSwitcher from './ModuleSwitcher.vue'
import SidebarLink from './SidebarLink.vue'
import { groupNavigation } from '../ui/navigationGroups.js'

const props = defineProps({
  navigation: { type: Array, required: true },
  moduleNavigation: { type: Array, default: () => [] },
  logout: { type: Object, default: null },
  mobile: { type: Boolean, default: false },
})

const emit = defineEmits(['close'])

const isPlatformNavigation = computed(() => props.navigation.some((item) => item.key === 'platform-home'))
const isManagerialNavigation = computed(() => props.navigation.some((item) => String(item.key).startsWith('managerial-')))
const isTripNavigation = computed(() => props.navigation.some((item) => ['trip-masters', 'trips-dashboard', 'trips-operations-dashboard', 'trips-operations', 'trips-profitability'].includes(item.key)))
const moduleTitle = computed(() => (isManagerialNavigation.value ? 'Gerencial' : (isTripNavigation.value ? 'Viajes' : 'Mantenimiento')))

// The portal and Gerencial are short flat lists; operational modules get sections.
const groups = computed(() => (
  isPlatformNavigation.value || isManagerialNavigation.value
    ? [{ title: '', items: props.navigation }]
    : groupNavigation(props.navigation)
))

const fleetConsumption = computed(() => props.navigation.find((item) => item.key === 'fleet-consumption'))
const fleetOpen = ref(fleetConsumption.value?.active === true)
watch(() => fleetConsumption.value?.active, (active) => {
  if (active) fleetOpen.value = true
})

// Collapsible sections start open only when the current page is inside them.
const openGroups = ref(new Set(
  groups.value.filter((group) => group.collapsible && group.items.some((item) => item.active)).map((group) => group.title),
))
const isOpen = (group) => !group.collapsible || openGroups.value.has(group.title)
const toggleGroup = (group) => {
  const next = new Set(openGroups.value)
  if (next.has(group.title)) next.delete(group.title)
  else next.add(group.title)
  openGroups.value = next
}
// "Configuración" → "nav-group-configuracion".
const groupId = (group) => `nav-group-${group.title.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z]+/g, '-')}`

const icons = {
  dashboard: HomeIcon,
  truck: TruckIcon,
  equipment: TruckIcon,
  'fleet-telemetry': MapIcon,
  map: MapIcon,
  wrench: WrenchScrewdriverIcon,
  maintenance: WrenchScrewdriverIcon,
  calendar: CalendarDaysIcon,
  services: ClipboardDocumentCheckIcon,
  building: BuildingOffice2Icon,
  upload: ArrowUpTrayIcon,
  branches: BuildingStorefrontIcon,
  integrations: LinkIcon,
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
  'my-work': ClipboardDocumentCheckIcon,
  'report-failure': ExclamationTriangleIcon,
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
const iconFor = (item) => icons[item.icon] ?? ClipboardDocumentCheckIcon
const visibleLabel = (item) => {
  if (item.key === 'plans') return 'Preventivos'
  if (item.key === 'services') return 'Servicios'
  return item.label
}
const isActive = (item) => item.active === true || (item.key === 'fleet-telemetry' && fleetConsumption.value?.active === true)

// Brand icons: the sidebar is navy in both themes, so always the variant drawn for dark.
const customIconBaseUrl = document.body?.dataset?.baseUrl ?? ''
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
const customIconUrl = (item) => {
  const name = customIconNames[item.key] ?? customIconNames[item.icon] ?? null
  return name && customIconBaseUrl && !failedCustomIcons.value.has(name)
    ? `${customIconBaseUrl}assets/brand/icons/dark/${name}.svg`
    : null
}
const markCustomIconFailed = (item) => {
  const name = customIconNames[item.key] ?? customIconNames[item.icon]
  failedCustomIcons.value = new Set([...failedCustomIcons.value, name])
}
const logoutIconUrl = computed(() => customIconUrl({ key: 'logout' }))
const showDemoEntry = computed(() => props.navigation.some((item) => item.key === 'superadmin'))

const openDemoCompany = () => {
  window.dispatchEvent(new CustomEvent('maintenance:open-demo-company'))
  emit('close')
}
</script>

<template>
  <!-- `platform-sidebar` carries the navy surface and its item styles (transitions.css). -->
  <aside class="ui-sidebar-surface platform-sidebar flex h-full w-full flex-col">
    <div
      class="flex items-start justify-between gap-2 border-b border-white/10 px-5"
      :class="isPlatformNavigation ? 'platform-brand-row h-28 items-center' : 'min-h-[4.5rem] py-3'"
    >
      <BrandLogo v-if="isPlatformNavigation" size="platform" />
      <ModuleSwitcher v-else :modules="moduleNavigation" :fallback-title="moduleTitle" />
      <button
        v-if="mobile"
        type="button"
        class="ui-interactive grid size-11 shrink-0 place-items-center rounded-lg text-secondary-200 hover:bg-white/10 hover:text-white lg:hidden"
        aria-label="Cerrar menú principal"
        @click="emit('close')"
      >
        <XMarkIcon class="size-6" aria-hidden="true" />
      </button>
    </div>

    <nav aria-label="Navegación principal" class="ui-sidebar-scroll flex-1 overflow-y-auto px-3 py-4">
      <section v-for="group in groups" :key="group.title || 'all'" class="mb-3 last:mb-0">
        <button
          v-if="group.collapsible"
          type="button"
          class="ui-interactive flex min-h-9 w-full items-center justify-between rounded-md px-3 text-left text-xs font-bold uppercase tracking-wide text-secondary-200 hover:bg-white/10 hover:text-white"
          :aria-expanded="isOpen(group) ? 'true' : 'false'"
          :aria-controls="groupId(group)"
          @click="toggleGroup(group)"
        >
          {{ group.title }}
          <ChevronDownIcon class="size-4 shrink-0 transition-transform" :class="isOpen(group) ? 'rotate-180' : ''" aria-hidden="true" />
        </button>
        <h2 v-else-if="group.title" class="px-3 pb-1 pt-1 text-xs font-bold uppercase tracking-wide text-secondary-200">{{ group.title }}</h2>

        <ul v-show="isOpen(group)" :id="groupId(group)" class="space-y-1">
          <li v-for="item in group.items" :key="item.key">
            <div class="relative">
              <SidebarLink
                :item="item"
                :label="visibleLabel(item)"
                :active="isActive(item)"
                :icon="iconFor(item)"
                :icon-url="customIconUrl(item)"
                :show-status="isPlatformNavigation"
                :class="item.key === 'fleet-telemetry' && fleetConsumption ? 'pr-12' : ''"
                @navigate="emit('close')"
                @icon-error="markCustomIconFailed(item)"
              />
              <button
                v-if="item.key === 'fleet-telemetry' && fleetConsumption"
                type="button"
                class="ui-interactive absolute right-1 top-1/2 grid size-9 -translate-y-1/2 place-items-center rounded-md text-secondary-200 hover:bg-white/10 hover:text-white"
                :aria-expanded="fleetOpen ? 'true' : 'false'"
                aria-controls="fleet-consumption-navigation"
                :aria-label="fleetOpen ? 'Ocultar opciones de Telemetría de flota' : 'Mostrar opciones de Telemetría de flota'"
                @click="fleetOpen = !fleetOpen"
              >
                <ChevronDownIcon class="size-4 shrink-0 transition-transform" :class="fleetOpen ? 'rotate-180' : ''" aria-hidden="true" />
              </button>
            </div>
            <ul
              v-if="item.key === 'fleet-telemetry' && fleetConsumption"
              v-show="fleetOpen"
              id="fleet-consumption-navigation"
              class="ml-5 mt-1 space-y-1 border-l border-white/15 pl-3"
            >
              <li>
                <SidebarLink
                  :item="fleetConsumption"
                  :label="fleetConsumption.label"
                  :active="fleetConsumption.active === true"
                  :icon="ChartBarSquareIcon"
                  nested
                  @navigate="emit('close')"
                />
              </li>
            </ul>
          </li>
          <li v-if="group.title === 'Administración global' && showDemoEntry">
            <button
              type="button"
              class="ui-interactive group flex min-h-11 w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left text-sm font-medium text-secondary-100 hover:bg-white/10 hover:text-white"
              @click="openDemoCompany"
            >
              <BeakerIcon class="size-5 shrink-0 text-secondary-300 group-hover:text-white" aria-hidden="true" />
              <span class="min-w-0 flex-1 truncate">Empresa demo</span>
            </button>
          </li>
        </ul>
      </section>
    </nav>

    <div v-if="isPlatformNavigation" class="platform-sidebar-note mx-4 mb-4 rounded-xl border border-white/10 px-3 py-3">
      <p class="text-sm font-semibold text-white">Una plataforma para crecer</p>
      <p class="mt-1 text-xs leading-5 text-secondary-200">Los módulos se habilitan a medida que están implementados.</p>
    </div>

    <div v-if="logout" class="border-t border-white/10 p-4">
      <component
        :is="logout.method === 'post' ? 'form' : 'div'"
        :action="logout.method === 'post' ? logout.href : undefined"
        :method="logout.method === 'post' ? 'post' : undefined"
      >
        <input v-if="logout.method === 'post' && logout.csrfName" type="hidden" :name="logout.csrfName" :value="logout.csrfValue" />
        <component
          :is="logout.method === 'post' ? 'button' : 'a'"
          :type="logout.method === 'post' ? 'submit' : undefined"
          :href="logout.method === 'post' ? undefined : logout.href"
          class="ui-interactive flex min-h-11 w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-secondary-100 hover:bg-white/10 hover:text-white"
        >
          <img v-if="logoutIconUrl" :src="logoutIconUrl" alt="" class="size-6 shrink-0" width="24" height="24" aria-hidden="true" @error="markCustomIconFailed({ key: 'logout' })" />
          <ArrowRightStartOnRectangleIcon v-else class="size-5" aria-hidden="true" />
          Cerrar sesión
        </component>
      </component>
    </div>
  </aside>
</template>
