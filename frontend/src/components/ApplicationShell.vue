<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import AppHeader from './AppHeader.vue'
import AppSidebar from './AppSidebar.vue'
import MobileTabBar from './MobileTabBar.vue'
import RouteLines from './RouteLines.vue'
import LazyChatWidget from '../pages/operations/components/LazyChatWidget.vue'
import ScrollCta from './ScrollCta.vue'
import { withFieldShortcuts } from '../ui/fieldShortcuts.js'

const props = defineProps({
  shell: {
    type: Object,
    required: true,
  },
  scrollCta: {
    type: Object,
    default: null,
  },
})

const desktopNavStorageKey = 'maintenance.desktop-menu'
const getDesktopNavCollapsed = () => {
  try {
    return window.sessionStorage.getItem(desktopNavStorageKey) !== 'expanded'
  } catch {
    return true
  }
}

const sidebarOpen = ref(false)
const desktopNavCollapsed = ref(getDesktopNavCollapsed())
const header = ref(null)
const mobileDrawer = ref(null)
const assistantLauncher = ref(null)
let drawerTrigger = null
let desktopBreakpoint = null
const isPlatformShell = computed(() => props.shell.navigation.some((item) => item.key === 'platform-home'))
// Adds "Mi trabajo" and "Informar falla" from what the server already allowed.
const navigation = computed(() => (isPlatformShell.value
  ? props.shell.navigation
  : withFieldShortcuts(props.shell.navigation, props.shell.user, window.location.search)))
const focusableSelector = [
  'a[href]',
  'button:not([disabled])',
  'input:not([disabled])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
].join(',')

const drawerControls = () => [...(mobileDrawer.value?.querySelectorAll(focusableSelector) ?? [])]
  .filter((control) => control.getClientRects().length > 0)

const focusFirstDrawerControl = () => {
  if (!sidebarOpen.value) return
  const first = drawerControls()[0] ?? mobileDrawer.value
  first?.focus({ preventScroll: true })
}

const closeSidebar = () => {
  if (!sidebarOpen.value) return
  sidebarOpen.value = false
  const trigger = drawerTrigger
  drawerTrigger = null
  nextTick(() => {
    if (sidebarOpen.value || desktopBreakpoint?.matches) return
    if (trigger?.isConnected) trigger.focus({ preventScroll: true })
    else header.value?.focusMenuButton()
  })
}

const openSidebar = (trigger) => {
  if (sidebarOpen.value) return
  drawerTrigger = trigger ?? document.activeElement
  sidebarOpen.value = true
}

const setDesktopNavCollapsed = (collapsed) => {
  desktopNavCollapsed.value = collapsed
  try {
    window.sessionStorage.setItem(desktopNavStorageKey, collapsed ? 'collapsed' : 'expanded')
  } catch {
    // El menú sigue funcionando aunque el navegador bloquee el almacenamiento.
  }
}
const toggleDesktopNav = () => setDesktopNavCollapsed(!desktopNavCollapsed.value)

// Expandida, la barra se superpone al contenido: Escape la vuelve a colapsar.
const collapseDesktopNavOnEscape = (event) => {
  if (event.key === 'Escape') setDesktopNavCollapsed(true)
}
watch(
  desktopNavCollapsed,
  (collapsed) => {
    if (collapsed) document.removeEventListener('keydown', collapseDesktopNavOnEscape)
    else document.addEventListener('keydown', collapseDesktopNavOnEscape)
  },
  { immediate: true },
)

const handleEscape = (event) => {
  if (event.key === 'Escape') closeSidebar()
}

const handleDrawerKeydown = (event) => {
  if (event.key !== 'Tab' || !mobileDrawer.value) return

  const controls = drawerControls()
  if (!controls.length) {
    event.preventDefault()
    mobileDrawer.value.focus()
    return
  }

  const first = controls[0]
  const last = controls[controls.length - 1]
  if (event.shiftKey && (document.activeElement === first || !mobileDrawer.value.contains(document.activeElement))) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault()
    first.focus()
  }
}

watch(
  sidebarOpen,
  (open) => {
    document.body.classList.toggle('overflow-hidden', open)
    if (open) {
      document.addEventListener('keydown', handleEscape)
      nextTick(focusFirstDrawerControl)
    } else {
      document.removeEventListener('keydown', handleEscape)
    }
  },
  { immediate: true },
)

const closeDrawerOnDesktop = (event) => {
  if (event.matches) closeSidebar()
}

onMounted(() => {
  desktopBreakpoint = window.matchMedia?.('(min-width: 1024px)')
  desktopBreakpoint?.addEventListener?.('change', closeDrawerOnDesktop)
})

onBeforeUnmount(() => {
  desktopBreakpoint?.removeEventListener?.('change', closeDrawerOnDesktop)
  document.body.classList.remove('overflow-hidden')
  document.removeEventListener('keydown', handleEscape)
  document.removeEventListener('keydown', collapseDesktopNavOnEscape)
})
</script>

<template>
  <a
    href="#main-content"
    class="fixed left-4 top-3 z-[60] -translate-y-24 rounded-lg bg-surface-raised px-4 py-2 text-sm font-semibold text-primary shadow-lg transition-transform focus:translate-y-0"
  >
    Ir al contenido principal
  </a>

  <!-- Marco Vogel desde sm: la barra navy y el marco son la misma superficie; en celular no hay marco. -->
  <div class="workspace-shell min-h-screen bg-surface-subtle sm:bg-frame">
    <button
      v-if="!desktopNavCollapsed"
      type="button"
      data-test="sidebar-scrim"
      class="fixed inset-0 z-[35] hidden cursor-default lg:block"
      tabindex="-1"
      aria-hidden="true"
      @click="setDesktopNavCollapsed(true)"
    ></button>
    <div
      class="workspace-sidebar fixed inset-y-0 left-0 z-40 hidden lg:block"
      :class="{ 'workspace-sidebar--expanded shadow-sidebar': !desktopNavCollapsed }"
    >
      <AppSidebar
        class="ui-vt-sidebar ui-sidebar-surface"
        :navigation="navigation"
        :module-navigation="props.shell.moduleNavigation ?? []"
        :logout="props.shell.logout"
        collapsible
        :collapsed="desktopNavCollapsed"
        @toggle-width="toggleDesktopNav"
      />
    </div>

    <Transition name="workspace-backdrop">
      <button
        v-if="sidebarOpen"
        type="button"
        class="fixed inset-0 z-40 cursor-default bg-secondary-900/60 lg:hidden"
        tabindex="-1"
        aria-hidden="true"
        @click="closeSidebar"
      ></button>
    </Transition>

    <Transition name="workspace-drawer" @before-leave="(drawer) => { drawer.inert = true }">
      <div
        v-if="sidebarOpen"
        id="mobile-main-menu"
        ref="mobileDrawer"
        class="fixed inset-y-0 left-0 z-50 w-[15rem] overflow-hidden shadow-sidebar lg:hidden"
        role="dialog"
        aria-modal="true"
        aria-label="Menú principal"
        tabindex="-1"
        @keydown="handleDrawerKeydown"
      >
        <AppSidebar
          mobile
          :navigation="navigation"
          :module-navigation="props.shell.moduleNavigation ?? []"
          :logout="props.shell.logout"
          @close="closeSidebar"
        />
      </div>
    </Transition>

    <div class="workspace-content sm:p-4 lg:pl-[var(--workspace-sidebar-width)]" :inert="sidebarOpen || undefined">
      <!-- overflow-clip recorta las esquinas sin crear un contenedor de scroll: el encabezado sigue siendo sticky. -->
      <div class="relative min-h-screen overflow-clip bg-surface-subtle sm:min-h-[calc(100vh-2rem)] sm:rounded-panel">
      <RouteLines />
      <AppHeader
        ref="header"
        class="ui-vt-header"
        :user="props.shell.user"
        :company="props.shell.company"
        :notifications="props.shell.notifications"
        :equipment-search-url="props.shell.equipmentSearchUrl ?? null"
        :logout="props.shell.logout"
        :menu-open="sidebarOpen"
        @open-menu="openSidebar"
      >
        <template v-if="!isPlatformShell" #assistant>
          <div id="operations-assistant-launcher" ref="assistantLauncher" class="size-11 shrink-0" />
        </template>
      </AppHeader>

      <main
        id="main-content"
        class="ui-view-enter ui-vt-content mx-auto w-full max-w-[96rem] px-4 py-6 sm:px-6 sm:py-8 lg:px-7 lg:py-8 xl:px-9"
        :class="{ 'pb-24 sm:pb-24 lg:pb-24': !isPlatformShell }"
        tabindex="-1"
      >
        <slot />
      </main>
      </div>
    </div>
  </div>

  <ScrollCta
    v-if="props.scrollCta?.href"
    :href="props.scrollCta.href"
    :label="props.scrollCta.label"
    :description="props.scrollCta.description"
  />
  <MobileTabBar v-if="!isPlatformShell" :navigation="navigation" :menu-open="sidebarOpen" :inert="sidebarOpen || undefined" @open-menu="openSidebar" />
  <LazyChatWidget v-if="assistantLauncher" :launcher-target="assistantLauncher" />
</template>
