const prefersReducedMotion = () => (
  typeof window !== 'undefined'
  && typeof window.matchMedia === 'function'
  && window.matchMedia('(prefers-reduced-motion: reduce)').matches
)

const revealDelay = (binding) => {
  const value = typeof binding.value === 'object' ? binding.value?.delay : binding.value
  const delay = Number(value)

  if (!Number.isFinite(delay)) return 0
  return Math.max(0, Math.min(delay, 600))
}

export const scrollRevealDirective = {
  mounted(element, binding) {
    element.classList.add('ui-scroll-reveal')
    element.style.setProperty('--ui-reveal-delay', `${revealDelay(binding)}ms`)

    if (prefersReducedMotion() || typeof IntersectionObserver === 'undefined') {
      element.classList.add('is-visible')
      return
    }

    const observer = new IntersectionObserver(([entry]) => {
      if (!entry?.isIntersecting) return
      element.classList.add('is-visible')
      observer.disconnect()
      delete element.__scrollRevealObserver
    }, { threshold: 0.12, rootMargin: '0px 0px -8% 0px' })

    element.__scrollRevealObserver = observer
    observer.observe(element)
  },

  beforeUnmount(element) {
    element.__scrollRevealObserver?.disconnect()
    delete element.__scrollRevealObserver
  },
}

export function installScrollReveal(app) {
  app.directive('reveal', scrollRevealDirective)
}

