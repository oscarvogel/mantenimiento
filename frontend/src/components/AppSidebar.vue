<script setup>
// The only navigation of the app (docs/DESIGN_TOKENS.md, "Navegación"): navy in both
// themes, the module switch and the way back to the portal on top, and the module's own
// items grouped by daily task.
import { computed, ref, watch } from 'vue'
import {
  ArrowLeftIcon,
  ArrowPathRoundedSquareIcon,
  ArrowUpTrayIcon,
  BeakerIcon,
  BellIcon,
  BuildingOffice2Icon,
  BuildingStorefrontIcon,
  CalendarDaysIcon,
  ChartBarSquareIcon,
  ChevronDoubleRightIcon,
  ChevronDownIcon,
  ClipboardDocumentCheckIcon,
  ClipboardDocumentListIcon,
  ExclamationTriangleIcon,
  HomeIcon,
  LinkIcon,
  MapIcon,
  TruckIcon,
  UsersIcon,
  WrenchScrewdriverIcon,
  XMarkIcon,
} from '@heroicons/vue/24/outline'
import BrandLogo from './BrandLogo.vue'
import { moduleIcons } from '../ui/moduleIcons.js'
import ModuleSwitcher from './ModuleSwitcher.vue'
import SidebarLink from './SidebarLink.vue'
import ThemeToggle from './ThemeToggle.vue'
import { groupNavigation } from '../ui/navigationGroups.js'

const props = defineProps({
  navigation: { type: Array, required: true },
  moduleNavigation: { type: Array, default: () => [] },
  mobile: { type: Boolean, default: false },
  collapsible: { type: Boolean, default: false },
  collapsed: { type: Boolean, default: false },
})

const emit = defineEmits(['close', 'toggle-width'])

const isPlatformNavigation = computed(() => props.navigation.some((item) => item.key === 'platform-home'))
const isManagerialNavigation = computed(() => props.navigation.some((item) => String(item.key).startsWith('managerial-')))
const isTripNavigation = computed(() => props.navigation.some((item) => ['trip-masters', 'trips-dashboard', 'trips-operations-dashboard', 'trips-operations', 'trips-profitability'].includes(item.key)))
const moduleTitle = computed(() => (isManagerialNavigation.value ? 'Gerencial' : (isTripNavigation.value ? 'Viajes' : 'Mantenimiento')))
const portalLink = computed(() => props.moduleNavigation.find((item) => item.key === 'platform-home' && item.href && !item.disabled))
// Íconos fijos de la barra contraída, en este orden. Quien no ve planes es perfil de campo
// (mismo criterio que el portal): sus accesos son Mi trabajo e Informar falla.
const officeRailKeys = ['dashboard', 'work-orders', 'work-requests', 'plans', 'equipment', 'quick-readings']
const fieldRailKeys = ['dashboard', 'my-work', 'report-failure', 'quick-readings', 'work-orders', 'equipment']
// Viajes sigue el orden del menú; Historial (solo lectura del ERP anterior) queda en el desplegado.
const tripRailKeys = ['trips-operations-dashboard', 'trips-operations', 'trips-fuel-pending', 'trips-commercial-closing', 'customer-accounts', 'trips-profitability']
const railLinks = computed(() => {
  const available = props.navigation.filter((item) => item.href && !item.disabled)
  if (isPlatformNavigation.value || isManagerialNavigation.value) return available

  // Siempre los mismos íconos en el mismo lugar: sumar la página actual los corría.
  const has = (key) => available.some((item) => item.key === key)
  const keys = isTripNavigation.value ? tripRailKeys : (has('plans') ? officeRailKeys : fieldRailKeys)
  const preferred = keys.map((key) => available.find((item) => item.key === key)).filter(Boolean)
  return preferred.length > 1 ? preferred : available.slice(0, 6)
})

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
  ...Object.fromEntries(Object.entries(moduleIcons).map(([name, icon]) => [`module-${name}`, icon])),
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
const showDemoEntry = computed(() => props.navigation.some((item) => item.key === 'superadmin'))

// Nombre del ícono de la barra contraída: un solo globo fijo, fuera del nav que se desplaza.
const railTip = ref(null)
const showRailTip = (event, label) => {
  const box = event.currentTarget.getBoundingClientRect()
  railTip.value = { label, top: box.top + box.height / 2 }
}
const hideRailTip = () => { railTip.value = null }

const openDemoCompany = () => {
  window.dispatchEvent(new CustomEvent('maintenance:open-demo-company'))
  emit('close')
}
</script>

<template>
  <!-- `platform-sidebar` carries the navy surface and its item styles (transitions.css). -->
  <aside class="ui-sidebar-surface platform-sidebar flex h-full w-full flex-col">
    <div v-if="collapsible" class="relative z-10 flex h-[6.5rem] shrink-0 items-center gap-2 border-b border-white/10 px-3">
      <div class="flex w-12 shrink-0 flex-col items-center gap-1">
        <BrandLogo size="small" />
        <button
          type="button"
          class="grid size-11 place-items-center rounded-lg text-secondary-100 hover:bg-white/10 hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent-300"
          :aria-label="collapsed ? 'Expandir menú lateral' : 'Contraer menú lateral'"
          :title="collapsed ? 'Expandir menú lateral' : 'Contraer menú lateral'"
          :aria-expanded="!collapsed"
          aria-controls="desktop-primary-navigation"
          @click="emit('toggle-width')"
        >
          <ChevronDoubleRightIcon class="workspace-sidebar-chevron size-5" :class="{ 'rotate-180': !collapsed }" aria-hidden="true" />
        </button>
      </div>
      <div v-if="!collapsed" class="workspace-sidebar-reveal min-w-0 flex-1">
        <span v-if="isPlatformNavigation" class="block text-sm font-semibold leading-snug text-white">Vogel Consultoría</span>
        <ModuleSwitcher v-else :modules="moduleNavigation" :fallback-title="moduleTitle" />
      </div>
    </div>

    <div
      v-else
      class="flex items-start justify-between gap-2 border-b border-white/10 px-5"
      :class="isPlatformNavigation ? 'platform-brand-row h-28' : 'min-h-[4.5rem] py-3'"
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

    <nav
      v-if="collapsed"
      id="desktop-primary-navigation"
      aria-label="Navegación principal"
      class="ui-sidebar-scroll flex-1 overflow-y-auto px-2 py-3"
      @scroll="hideRailTip"
      @keydown.escape="hideRailTip"
    >
      <ul class="space-y-1">
        <li
          v-if="!isPlatformNavigation && portalLink"
          @mouseenter="showRailTip($event, 'Volver al portal')"
          @mouseleave="hideRailTip"
          @focusin="showRailTip($event, 'Volver al portal')"
          @focusout="hideRailTip"
        >
          <SidebarLink :item="portalLink" label="Volver al portal" :icon="ArrowLeftIcon" compact />
        </li>
        <li
          v-for="item in railLinks"
          :key="item.key"
          @mouseenter="showRailTip($event, visibleLabel(item))"
          @mouseleave="hideRailTip"
          @focusin="showRailTip($event, visibleLabel(item))"
          @focusout="hideRailTip"
        >
          <SidebarLink
            :item="item"
            :label="visibleLabel(item)"
            :active="isActive(item)"
            :icon="iconFor(item)"
            :icon-url="customIconUrl(item)"
            compact
            @icon-error="markCustomIconFailed(item)"
          />
        </li>
      </ul>
    </nav>
    <nav v-else :id="collapsible ? 'desktop-primary-navigation' : undefined" aria-label="Navegación principal" class="ui-sidebar-scroll flex-1 overflow-y-auto px-3 py-3" :class="{ 'workspace-sidebar-reveal': collapsible }">
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
    <!-- Tema al pie, fijo: contraída solo el botón (su nombre en el globo); expandida o en el celular, con la palabra. -->
    <div
      data-test="sidebar-theme"
      class="flex shrink-0 items-center border-t border-white/10 py-3"
      :class="collapsed ? 'justify-center px-2' : 'justify-between gap-3 px-5'"
    >
      <span v-if="!collapsed" class="text-sm font-medium text-secondary-100">Tema</span>
      <ThemeToggle
        class="text-secondary-100 hover:bg-white/10 hover:text-white focus-visible:outline-accent-300"
        @mouseenter="collapsed && showRailTip($event, $event.currentTarget.getAttribute('aria-label'))"
        @mouseleave="hideRailTip"
        @focusin="collapsed && showRailTip($event, $event.currentTarget.getAttribute('aria-label'))"
        @focusout="hideRailTip"
        @click="hideRailTip"
      />
    </div>
    <span
      v-if="collapsed && railTip"
      data-test="rail-tooltip"
      class="pointer-events-none fixed z-50 -translate-y-1/2 whitespace-nowrap rounded-lg bg-secondary-900 px-3 py-1.5 text-xs font-semibold text-white shadow-card ring-1 ring-white/15"
      :style="{ top: `${railTip.top}px`, left: 'calc(var(--workspace-sidebar-width, 4.5rem) + 0.5rem)' }"
      aria-hidden="true"
    >{{ railTip.label }}</span>
  </aside>
</template>
