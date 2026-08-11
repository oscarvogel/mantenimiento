<script setup>
import { onBeforeUnmount, ref, watch } from 'vue'
import AppHeader from './AppHeader.vue'
import AppSidebar from './AppSidebar.vue'

defineProps({
  shell: {
    type: Object,
    required: true,
  },
})

const sidebarOpen = ref(false)

const closeSidebar = () => {
  sidebarOpen.value = false
}

const handleEscape = (event) => {
  if (event.key === 'Escape') closeSidebar()
}

watch(
  sidebarOpen,
  (open) => {
    document.body.classList.toggle('overflow-hidden', open)
    if (open) document.addEventListener('keydown', handleEscape)
    else document.removeEventListener('keydown', handleEscape)
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
    class="fixed left-4 top-3 z-[60] -translate-y-24 rounded-lg bg-white px-4 py-2 text-sm font-semibold text-primary shadow-lg transition-transform focus:translate-y-0"
  >
    Ir al contenido principal
  </a>

  <div class="min-h-screen bg-surface-subtle">
    <div class="fixed inset-y-0 left-0 z-30 hidden lg:block">
      <AppSidebar :navigation="shell.navigation" :logout="shell.logout" />
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
      <div v-if="sidebarOpen" class="fixed inset-y-0 left-0 z-50 shadow-sidebar lg:hidden">
        <AppSidebar
          mobile
          :navigation="shell.navigation"
          :logout="shell.logout"
          @close="closeSidebar"
        />
      </div>
    </Transition>

    <div class="lg:pl-[17rem]">
      <AppHeader :user="shell.user" :company="shell.company" :notifications="shell.notifications" @open-menu="sidebarOpen = true" />

      <main
        id="main-content"
        class="mx-auto w-full max-w-[96rem] px-4 py-6 sm:px-6 sm:py-8 lg:px-8 lg:py-10"
        tabindex="-1"
      >
        <slot />
      </main>
    </div>
  </div>
</template>
