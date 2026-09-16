<script setup>
import { ArrowRightIcon } from '@heroicons/vue/20/solid'
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { createScrollTrigger, dialogTransitionHooks, prefersReducedMotion } from '../ui/gsapMotion.js'

defineProps({
  href: { type: String, required: true },
  label: { type: String, required: true },
  description: { type: String, default: '' },
})

const visible = ref(false)
let trigger = null
let media = null
let setupTrigger = null

const ctaTransition = dialogTransitionHooks()

const updateVisibility = () => {
  visible.value = window.scrollY > 240
}

onMounted(() => {
  updateVisibility()
  media = window.matchMedia?.('(prefers-reduced-motion: reduce)')
  setupTrigger = () => {
    trigger?.kill()
    trigger = null
    updateVisibility()
    if (prefersReducedMotion()) return
    trigger = createScrollTrigger({
      trigger: document.documentElement,
      start: 'top -240px',
      end: 'max',
      onEnter: () => { visible.value = true },
      onLeaveBack: () => { visible.value = false },
    })
  }
  media?.addEventListener?.('change', setupTrigger)
  setupTrigger()
})

onBeforeUnmount(() => {
  trigger?.kill()
  media?.removeEventListener?.('change', setupTrigger)
})
</script>

<template>
  <Transition
    @before-enter="ctaTransition.beforeEnter"
    @enter="ctaTransition.enter"
    @leave="ctaTransition.leave"
  >
    <a
      v-if="visible && href !== '#'"
      :href="href"
      class="ui-scroll-cta ui-interactive ui-glare fixed bottom-4 left-4 right-24 z-30 flex min-h-12 items-center justify-between gap-3 rounded-xl bg-primary px-4 py-2.5 text-primary-foreground shadow-lg shadow-brand-950/20 sm:hidden"
    >
      <span class="min-w-0">
        <span class="block truncate text-sm font-bold">{{ label }}</span>
        <span v-if="description" class="block truncate text-[0.6875rem] text-primary-foreground/75">{{ description }}</span>
      </span>
      <ArrowRightIcon class="size-5 shrink-0" aria-hidden="true" />
    </a>
  </Transition>
</template>
