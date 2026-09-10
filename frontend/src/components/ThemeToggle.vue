<script setup>
import { MoonIcon, SunIcon } from '@heroicons/vue/24/outline'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { getTheme, toggleTheme } from '../ui/theme.js'

const theme = ref(getTheme())
const isDark = computed(() => theme.value === 'dark')
const label = computed(() => isDark.value ? 'Activar modo claro' : 'Activar modo oscuro')

const syncTheme = (event) => {
  theme.value = event.detail?.theme ?? getTheme()
}

const changeTheme = () => {
  theme.value = toggleTheme()
}

onMounted(() => window.addEventListener('maintenance:theme-change', syncTheme))
onBeforeUnmount(() => window.removeEventListener('maintenance:theme-change', syncTheme))
</script>

<template>
  <button
    type="button"
    data-theme-toggle
    class="ui-theme-toggle ui-interactive"
    :aria-label="label"
    :title="label"
    :aria-pressed="isDark"
    @click="changeTheme"
  >
    <span class="ui-theme-toggle__track" aria-hidden="true">
      <SunIcon class="ui-theme-toggle__icon ui-theme-toggle__sun" :class="{ 'is-active': !isDark }" />
      <MoonIcon class="ui-theme-toggle__icon ui-theme-toggle__moon" :class="{ 'is-active': isDark }" />
      <span class="ui-theme-toggle__thumb" :class="{ 'is-dark': isDark }"></span>
    </span>
    <span class="sr-only">{{ label }}</span>
  </button>
</template>
