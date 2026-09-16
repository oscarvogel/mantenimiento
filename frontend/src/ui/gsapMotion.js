import { gsap } from 'gsap'
import { Flip } from 'gsap/Flip'
import { ScrollTrigger } from 'gsap/ScrollTrigger'

let flipRegistered = false
let scrollTriggerRegistered = false

const ensureFlip = () => {
  if (flipRegistered) return
  gsap.registerPlugin(Flip)
  flipRegistered = true
}

const ensureScrollTrigger = () => {
  if (scrollTriggerRegistered || typeof window === 'undefined' || typeof window.matchMedia !== 'function') return
  gsap.registerPlugin(ScrollTrigger)
  scrollTriggerRegistered = true
}

export const motionTokens = {
  fast: 0.18,
  standard: 0.24,
  panel: 0.28,
  ease: 'power2.out',
  emphasis: 'back.out(1.35)',
}

export const prefersReducedMotion = () => {
  if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return true
  return window.matchMedia('(prefers-reduced-motion: reduce)').matches
}

const numericOption = (value, fallback = 0) => {
  const number = Number(value)
  return Number.isFinite(number) ? number : fallback
}

const resolveTargets = (root, targets) => {
  if (!root) return []
  if (!targets) return [root]
  if (typeof targets === 'string') return [...root.querySelectorAll(targets)]
  const isNodeList = typeof NodeList !== 'undefined' && targets instanceof NodeList
  return Array.isArray(targets) || isNodeList ? [...targets] : [targets]
}

export function createMotionContext(root, setup) {
  if (!root || typeof setup !== 'function') return null
  ensureFlip()
  return gsap.context(setup, root)
}

export function animateIn(root, options = {}) {
  const targets = resolveTargets(root, options.targets)
  if (!targets.length) return null

  const context = createMotionContext(root, () => {
    if (prefersReducedMotion()) {
      gsap.set(targets, { clearProps: 'all' })
      return
    }

    gsap.fromTo(targets,
      { autoAlpha: 0, y: numericOption(options.y, 12) },
      {
        autoAlpha: 1,
        y: 0,
        duration: numericOption(options.duration, motionTokens.standard),
        delay: numericOption(options.delay),
        stagger: numericOption(options.stagger),
        ease: options.ease || motionTokens.ease,
        overwrite: 'auto',
      },
    )
  })

  return context
}

export function animatePulse(element, options = {}) {
  if (!element || prefersReducedMotion()) return null
  return gsap.fromTo(element,
    { scale: 0.82 },
    {
      scale: 1,
      duration: numericOption(options.duration, motionTokens.fast),
      ease: options.ease || motionTokens.emphasis,
      clearProps: 'transform',
      overwrite: 'auto',
    },
  )
}

export function captureFlipState(elements) {
  if (prefersReducedMotion() || !elements?.length) return null
  ensureFlip()
  return Flip.getState(elements)
}

export function animateFlip(state, elements, options = {}) {
  if (!state || prefersReducedMotion() || !elements?.length) return null
  ensureFlip()
  return Flip.from(state, {
    targets: elements,
    duration: numericOption(options.duration, motionTokens.standard),
    ease: options.ease || motionTokens.ease,
    stagger: numericOption(options.stagger),
    absolute: false,
    scale: false,
    prune: true,
    overwrite: 'auto',
  })
}

const transitionPanel = (element, selector) => {
  if (!selector) return element
  return element.matches?.(selector) ? element : element.querySelector(selector) || element
}

export function dialogTransitionHooks(options = {}) {
  const panelSelector = options.panelSelector || null

  return {
    beforeEnter(element) {
      if (prefersReducedMotion()) return
      const panel = transitionPanel(element, panelSelector)
      gsap.set(element, { autoAlpha: 0 })
      gsap.set(panel, { autoAlpha: 0, y: 12, scale: 0.98, transformOrigin: '50% 50%' })
    },

    enter(element, done) {
      if (prefersReducedMotion()) {
        done()
        return
      }
      const panel = transitionPanel(element, panelSelector)
      gsap.timeline({ onComplete: done })
        .to(element, { autoAlpha: 1, duration: motionTokens.fast, ease: 'power1.out' })
        .to(panel, { autoAlpha: 1, y: 0, scale: 1, duration: motionTokens.panel, ease: motionTokens.ease }, '<')
    },

    leave(element, done) {
      if (prefersReducedMotion()) {
        done()
        return
      }
      const panel = transitionPanel(element, panelSelector)
      gsap.timeline({ onComplete: done })
        .to(panel, { autoAlpha: 0, y: 8, scale: 0.985, duration: motionTokens.fast, ease: 'power1.in' })
        .to(element, { autoAlpha: 0, duration: motionTokens.fast, ease: 'power1.in' }, '<')
    },
  }
}

export function panelTransitionHooks(options = {}) {
  const axis = options.axis === 'x' ? 'x' : 'y'
  const distance = numericOption(options.distance, axis === 'x' ? 14 : 10)
  return {
    beforeEnter(element) {
      if (prefersReducedMotion()) return
      gsap.set(element, { autoAlpha: 0, [axis]: distance })
    },
    enter(element, done) {
      if (prefersReducedMotion()) {
        done()
        return
      }
      gsap.to(element, {
        autoAlpha: 1,
        [axis]: 0,
        duration: numericOption(options.duration, motionTokens.standard),
        ease: motionTokens.ease,
        clearProps: 'transform,opacity',
        onComplete: done,
      })
    },
    leave(element, done) {
      if (prefersReducedMotion()) {
        done()
        return
      }
      gsap.to(element, {
        autoAlpha: 0,
        [axis]: distance,
        duration: numericOption(options.duration, motionTokens.fast),
        ease: 'power1.in',
        onComplete: done,
      })
    },
  }
}

export function openMotionDrawer(wrapper, options = {}) {
  if (!wrapper) return Promise.resolve()
  ensureFlip()
  const panel = wrapper.querySelector(options.panelSelector || '[role="dialog"]')
  const backdrop = wrapper.querySelector(options.backdropSelector || '[data-motion-backdrop]')
  wrapper.classList.remove('hidden')

  if (prefersReducedMotion() || !panel) {
    gsap.set([wrapper, panel, backdrop].filter(Boolean), { clearProps: 'all' })
    return Promise.resolve()
  }

  gsap.killTweensOf([wrapper, panel, backdrop].filter(Boolean))
  return new Promise((resolve) => {
    gsap.timeline({ onComplete: resolve })
      .fromTo(backdrop, { autoAlpha: 0 }, { autoAlpha: 1, duration: motionTokens.fast, ease: 'power1.out' }, 0)
      .fromTo(panel, { x: '100%' }, { x: 0, duration: motionTokens.panel, ease: motionTokens.ease }, 0)
  })
}

export function closeMotionDrawer(wrapper, options = {}) {
  if (!wrapper) return Promise.resolve()
  ensureFlip()
  const panel = wrapper.querySelector(options.panelSelector || '[role="dialog"]')
  const backdrop = wrapper.querySelector(options.backdropSelector || '[data-motion-backdrop]')

  if (prefersReducedMotion() || !panel) {
    wrapper.classList.add('hidden')
    return Promise.resolve()
  }

  gsap.killTweensOf([wrapper, panel, backdrop].filter(Boolean))
  return new Promise((resolve) => {
    gsap.timeline({
      onComplete: () => {
        wrapper.classList.add('hidden')
        gsap.set([wrapper, panel, backdrop].filter(Boolean), { clearProps: 'all' })
        resolve()
      },
    })
      .to(panel, { x: '100%', duration: motionTokens.fast, ease: 'power1.in' }, 0)
      .to(backdrop, { autoAlpha: 0, duration: motionTokens.fast, ease: 'power1.in' }, 0)
  })
}

export function createScrollTrigger(config) {
  if (prefersReducedMotion()) return null
  ensureScrollTrigger()
  if (!scrollTriggerRegistered) return null
  return ScrollTrigger.create(config)
}

export const gsapMotionDirective = {
  mounted(element, binding) {
    const options = typeof binding.value === 'object' && binding.value !== null ? binding.value : {}

    const play = () => {
      element.__gsapMotionContext?.revert()
      element.__gsapMotionContext = animateIn(element, options)
    }

    element.__gsapMotionPlay = play
    if (typeof window !== 'undefined' && typeof window.matchMedia === 'function' && typeof gsap.matchMedia === 'function') {
      const mediaContext = gsap.matchMedia()
      mediaContext.add('(prefers-reduced-motion: reduce)', play)
      mediaContext.add('(prefers-reduced-motion: no-preference)', play)
      element.__gsapMotionMediaContext = mediaContext
    } else {
      play()
    }
  },
  beforeUnmount(element) {
    element.__gsapMotionContext?.revert()
    element.__gsapMotionMediaContext?.revert()
    delete element.__gsapMotionContext
    delete element.__gsapMotionPlay
    delete element.__gsapMotionMediaContext
  },
}

export function installGsapMotion(app) {
  app.directive('motion', gsapMotionDirective)
}
