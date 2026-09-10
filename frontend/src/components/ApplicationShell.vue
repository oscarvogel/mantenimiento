<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue'
import AppHeader from './AppHeader.vue'
import AppSidebar from './AppSidebar.vue'
import LazyChatWidget from '../pages/operations/components/LazyChatWidget.vue'
import ScrollCta from './ScrollCta.vue'

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

const sidebarOpen = ref(false)
const header = ref(null)
const mobileDrawer = ref(null)
const focusableSelector = [
  'a[href]',
  'button:not([disabled])',
  'input:not([disabled])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
].join(',')

const focusFirstDrawerControl = () => {
  mobileDrawer.value?.querySelector(focusableSelector)?.focus()
}

const closeSidebar = () => {
  if (!sidebarOpen.value) return
  sidebarOpen.value = false
  nextTick(() => header.value?.focusMenuButton())
}

const openSidebar = () => {
  sidebarOpen.value = true
}

const handleEscape = (event) => {
  if (event.key === 'Escape') closeSidebar()
}

const handleDrawerKeydown = (event) => {
  if (event.key !== 'Tab' || !mobileDrawer.value) return

  const controls = [...mobileDrawer.value.querySelectorAll(focusableSelector)]
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

onBeforeUnmount(() => {
  document.body.classList.remove('overflow-hidden')
  document.removeEventListener('keydown', handleEscape)
})
</script>

<template>
  <a
    href="#main-content"
    class="fixed left-4 top-3 z-[60] -translate-y-24 rounded-lg bg-surface-raised px-4 py-2 text-sm font-semibold text-primary shadow-lg transition-transform focus:translate-y-0"
  >
    Ir al contenido principal
  </a>

  <div class="min-h-screen bg-surface-subtle">
    <div class="fixed inset-y-0 left-0 z-30 hidden w-[15rem] lg:block">
      <AppSidebar
        class="ui-vt-sidebar ui-sidebar-surface"
        :navigation="props.shell.navigation"
        :logout="props.shell.logout"
      />
    </div>

    <Transition
      enter-active-class="transition-opacity duration-200"
      enter-from-class="opacity-0"
      leave-active-class="transition-opacity duration-150"
      leave-to-class="opacity-0"
    >
      <button
        v-if="sidebarOpen"
        type="button"
        class="fixed inset-0 z-40 cursor-default bg-brand-950/60 lg:hidden"
        aria-label="Cerrar menú principal"
        @click="closeSidebar"
      ></button>
    </Transition>

    <Transition
      enter-active-class="transition-transform duration-200 ease-out"
      enter-from-class="-translate-x-full"
      leave-active-class="transition-transform duration-150 ease-in"
      leave-to-class="-translate-x-full"
    >
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
          :navigation="props.shell.navigation"
          :logout="props.shell.logout"
          @close="closeSidebar"
        />
      </div>
    </Transition>

    <div class="lg:pl-[15rem]">
      <AppHeader
        ref="header"
        class="ui-vt-header"
        :user="props.shell.user"
        :company="props.shell.company"
        :notifications="props.shell.notifications"
        :menu-open="sidebarOpen"
        @open-menu="openSidebar"
      />

      <main
        id="main-content"
        class="ui-view-enter ui-vt-content mx-auto w-full max-w-[96rem] px-4 py-6 sm:px-6 sm:py-8 lg:px-7 lg:py-8 xl:px-9"
        tabindex="-1"
      >
        <slot />
      </main>
    </div>
  </div>

  <ScrollCta
    v-if="props.scrollCta?.href"
    :href="props.scrollCta.href"
    :label="props.scrollCta.label"
    :description="props.scrollCta.description"
  />
  <LazyChatWidget />
</template>
