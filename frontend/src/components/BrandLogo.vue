<script setup>
import { computed } from 'vue'

const props = defineProps({
  size: {
    type: String,
    default: 'sidebar',
    validator: (value) => ['sidebar', 'platform', 'login', 'hero', 'small'].includes(value),
  },
})

const baseUrl = document.body?.dataset?.baseUrl ?? '/'
// El login va sobre la superficie clara: ahí el logo conserva su fondo azul marino, porque el
// blanco de la "C", la "V" y "VOGEL" se perdería. En la barra azul va sin fondo.
const onLightSurface = computed(() => props.size === 'login')
const logoUrl = computed(() => `${baseUrl}assets/brand/vogel-consultoria${onLightSurface.value ? '' : '-sin-fondo'}.png`)
const sizes = {
  sidebar: 'h-8 w-[3.25rem] shrink-0',
  platform: 'h-[4.5rem] w-[7.5rem] max-w-full',
  login: 'h-9 w-[3.75rem] shrink-0',
  hero: 'h-16 w-[6.75rem] sm:h-[4.5rem] sm:w-[7.5rem]',
  small: 'h-7 w-12 shrink-0',
}
</script>

<template>
  <span
    role="img"
    aria-label="Vogel Consultoría"
    class="block rounded-sm bg-no-repeat"
    :class="[sizes[size], { 'bg-secondary-900': onLightSurface }]"
    :style="{
      backgroundImage: `url('${logoUrl}')`,
      backgroundSize: '146% auto',
      backgroundPosition: 'center 47.5%',
    }"
  ></span>
</template>
