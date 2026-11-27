<script>
// Botón "Classic" de theme-toggles (Alfie Jones, MIT, v4.10.1 — toggles.dev): el sol se recorta en luna.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { getTheme, toggleTheme } from '../ui/theme.js'

// Cada instancia necesita su propio recorte: dos ids iguales en la página mezclan los dibujos.
// Contador del módulo y no useId(), que repite ids entre apps de Vue distintas.
let instances = 0
</script>

<script setup>
const theme = ref(getTheme())
const isDark = computed(() => theme.value === 'dark')
const label = computed(() => isDark.value ? 'Activar modo claro' : 'Activar modo oscuro')
const cutoutId = `theme-toggle-cutout-${++instances}`

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
    class="theme-toggle ui-interactive"
    :class="{ 'theme-toggle--toggled': isDark }"
    :aria-label="label"
    :title="label"
    :aria-pressed="isDark"
    @click="changeTheme"
  >
    <svg xmlns="http://www.w3.org/2000/svg" aria-hidden="true" width="1em" height="1em" fill="currentColor" stroke-linecap="round" class="theme-toggle__classic" viewBox="0 0 32 32">
      <clipPath :id="cutoutId">
        <path d="M0-5h30a1 1 0 0 0 9 13v24H0Z" />
      </clipPath>
      <g :clip-path="`url(#${cutoutId})`">
        <circle cx="16" cy="16" r="9.34" />
        <g stroke="currentColor" stroke-width="1.5">
          <path d="M16 5.5v-4" />
          <path d="M16 30.5v-4" />
          <path d="M1.5 16h4" />
          <path d="M26.5 16h4" />
          <path d="m23.4 8.6 2.8-2.8" />
          <path d="m5.7 26.3 2.9-2.9" />
          <path d="m5.8 5.8 2.8 2.8" />
          <path d="m23.4 23.4 2.9 2.9" />
        </g>
      </g>
    </svg>
  </button>
</template>
