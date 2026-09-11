<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'

const props = defineProps({
  value: { type: Number, required: true },
  duration: { type: Number, default: 700 },
  suffix: { type: String, default: '' },
  prefix: { type: String, default: '' },
  formatter: {
    type: Function,
    default: (value) => new Intl.NumberFormat('es-AR', { maximumFractionDigits: 0 }).format(value),
  },
})

const displayedValue = ref(Number.isFinite(props.value) ? props.value : 0)
let animationFrame = null
let animationToken = 0
let isActive = false

const numericValue = (value) => (Number.isFinite(Number(value)) ? Number(value) : 0)

const prefersReducedMotion = () => (
  typeof window !== 'undefined'
  && typeof window.matchMedia === 'function'
  && window.matchMedia('(prefers-reduced-motion: reduce)').matches
)

const cancelAnimation = () => {
  if (animationFrame !== null && typeof window !== 'undefined' && typeof window.cancelAnimationFrame === 'function') {
    window.cancelAnimationFrame(animationFrame)
  }
  animationFrame = null
  animationToken += 1
}

const animateTo = (target, from = displayedValue.value) => {
  cancelAnimation()

  if (
    prefersReducedMotion()
    || from === target
    || typeof window === 'undefined'
    || typeof window.requestAnimationFrame !== 'function'
  ) {
    displayedValue.value = target
    return
  }

  const token = animationToken
  const start = typeof performance !== 'undefined' && typeof performance.now === 'function'
    ? performance.now()
    : Date.now()

  const step = (timestamp) => {
    if (token !== animationToken) return

    const elapsed = timestamp - start
    const progress = Math.min(elapsed / Math.max(props.duration, 1), 1)
    const easedProgress = 1 - ((1 - progress) ** 3)
    displayedValue.value = from + ((target - from) * easedProgress)

    if (progress < 1) {
      animationFrame = window.requestAnimationFrame(step)
    } else {
      displayedValue.value = target
      animationFrame = null
    }
  }

  displayedValue.value = from
  animationFrame = window.requestAnimationFrame(step)
}

onMounted(() => {
  isActive = true
  if (prefersReducedMotion()) return

  // Dejamos que Vue pinte el valor final en el montaje y arrancamos la animación
  // en la siguiente microtarea para no introducir un salto de layout.
  const startAnimation = () => {
    if (isActive) animateTo(numericValue(props.value), 0)
  }
  if (typeof queueMicrotask === 'function') queueMicrotask(startAnimation)
  else Promise.resolve().then(startAnimation)
})

watch(() => props.value, (value) => {
  animateTo(numericValue(value))
})

onBeforeUnmount(() => {
  isActive = false
  cancelAnimation()
})
</script>

<template>
  {{ prefix }}{{ formatter(displayedValue) }}{{ suffix }}
</template>
