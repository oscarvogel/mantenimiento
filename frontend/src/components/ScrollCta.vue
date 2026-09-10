<script setup>
import { ArrowRightIcon } from '@heroicons/vue/20/solid'
import { onBeforeUnmount, onMounted, ref } from 'vue'

defineProps({
  href: { type: String, required: true },
  label: { type: String, required: true },
  description: { type: String, default: '' },
})

const visible = ref(false)

const updateVisibility = () => {
  visible.value = window.scrollY > 240
}

onMounted(() => {
  updateVisibility()
  window.addEventListener('scroll', updateVisibility, { passive: true })
})

onBeforeUnmount(() => {
  window.removeEventListener('scroll', updateVisibility)
})
</script>

<template>
  <Transition
    enter-active-class="transition duration-200 ease-out"
    enter-from-class="translate-y-3 opacity-0"
    leave-active-class="transition duration-150 ease-in"
    leave-to-class="translate-y-3 opacity-0"
  >
    <a
      v-if="visible && href !== '#'"
      :href="href"
      class="ui-scroll-cta ui-interactive fixed bottom-4 left-4 right-24 z-30 flex min-h-12 items-center justify-between gap-3 rounded-xl bg-primary px-4 py-2.5 text-primary-foreground shadow-lg shadow-brand-950/20 sm:hidden"
    >
      <span class="min-w-0">
        <span class="block truncate text-sm font-bold">{{ label }}</span>
        <span v-if="description" class="block truncate text-[0.6875rem] text-primary-foreground/75">{{ description }}</span>
      </span>
      <ArrowRightIcon class="size-5 shrink-0" aria-hidden="true" />
    </a>
  </Transition>
</template>

