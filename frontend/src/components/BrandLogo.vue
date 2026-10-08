<script setup>
defineProps({
  size: {
    type: String,
    default: 'sidebar',
    validator: (value) => ['sidebar', 'platform', 'login', 'hero', 'small'].includes(value),
  },
})

const baseUrl = document.body?.dataset?.baseUrl ?? '/'
const logoUrl = `${baseUrl}assets/brand/vogel-consultoria.png`
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
    class="block rounded-sm bg-brand-950 bg-no-repeat"
    :class="sizes[size]"
    :style="{
      backgroundImage: `url('${logoUrl}')`,
      backgroundSize: '146% auto',
      backgroundPosition: 'center 47.5%',
    }"
  ></span>
</template>
