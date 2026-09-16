import { gsap } from 'gsap'

// Selectors are scoped to one SVG. Reverting the context also restores the neutral pose.
export function animateRobot(svg, state) {
  return gsap.context(() => {
    const part = (name) => `[data-part="${name}"]`
    const eyes = `${part('eye-left')}, ${part('eye-right')}`
    gsap.set(eyes, { transformOrigin: '50% 50%' })
    gsap.set(part('antenna-left'), { svgOrigin: '28 76' })
    gsap.set(part('antenna-right'), { svgOrigin: '212 76' })
    gsap.set(part('head'), { svgOrigin: '120 150' })
    const timeline = gsap.timeline({ defaults: { ease: 'sine.inOut' } })
    if (svg.dataset.variant === 'full') {
      gsap.set(part('body'), { svgOrigin: '120 406' })
      gsap.set(part('arm-left'), { svgOrigin: '25 224' })
      gsap.set(part('arm-right'), { svgOrigin: '214 224' })
      gsap.set(part('hand-left'), { svgOrigin: '-10 339' })
      // The clipboard and gripping hand share a pivot so they cannot drift apart.
      gsap.set(part('clipboard-assembly'), { svgOrigin: '252 343' })
      const gesture = gsap.timeline({ defaults: { ease: 'sine.inOut' } })
      if (state === 'idle') {
        gesture.repeat(-1).yoyo(true)
          .to(part('body'), { scaleY: 1.005, duration: 2.7 })
      } else if (state === 'thinking') {
        gesture.repeat(-1).repeatDelay(0.8)
          .to(part('clipboard-assembly'), { rotation: -3, duration: 0.65 })
          .to(part('arm-left'), { rotation: 3, duration: 0.65 }, '<')
          .to(part('clipboard-assembly'), { rotation: 0, duration: 0.65 }, '+=0.35')
          .to(part('arm-left'), { rotation: 0, duration: 0.65 }, '<')
      } else if (state === 'loading') {
        gesture.repeat(-1).yoyo(true)
          .to(part('chest-lights'), { opacity: 0.55, duration: 0.9 })
      } else if (state === 'success') {
        gesture.to(part('arm-left'), { rotation: 8, duration: 0.3 })
          .to(part('hand-left'), { rotation: 8, duration: 0.25 }, '<')
          .to(part('hand-left'), { rotation: 0, duration: 0.3 }, '+=0.3')
          .to(part('arm-left'), { rotation: 0, duration: 0.35 }, '<')
      } else if (state === 'error') {
        gesture.to(part('clipboard-assembly'), { rotation: 3, duration: 0.2 })
          .to(part('clipboard-assembly'), { rotation: 0, duration: 0.3 })
      } else if (state === 'offline') {
        gsap.set(part('chest-lights'), { opacity: 0.5 })
      }
    }

    if (state === 'idle') {
      // A quiet blink every few seconds; the silhouette stays still.
      timeline.delay(2.5).repeat(-1).repeatDelay(4)
        .to(eyes, { scaleY: 0.15, duration: 0.1 })
        .to(eyes, { scaleY: 1, duration: 0.16 })
    } else if (state === 'thinking') {
      timeline.repeat(-1).repeatDelay(0.7)
        .to(part('eyes'), { x: 3, y: -2, duration: 0.45 })
        .to(part('antenna-left'), { rotation: -5, duration: 0.45 }, '<')
        .to(part('antenna-right'), { rotation: 5, duration: 0.45 }, '<')
        .to(part('eyes'), { x: -3, y: 0, duration: 0.6 }, '+=0.25')
        .to(`${part('antenna-left')}, ${part('antenna-right')}`, { rotation: 0, duration: 0.45 }, '<')
        .to(part('eyes'), { x: 0, duration: 0.35 })
    } else if (state === 'loading') {
      timeline.repeat(-1).repeatDelay(0.25)
        .to(part('status-dot'), { y: -3, duration: 0.2, stagger: 0.12 })
        .to(part('status-dot'), { y: 0, duration: 0.2, stagger: 0.12 })
    } else if (state === 'success') {
      timeline.to(part('head'), { rotation: -3, duration: 0.18 })
        .to(part('head'), { rotation: 2, duration: 0.18 })
        .to(part('head'), { rotation: 0, duration: 0.22 })
        .fromTo(part('status-symbol'), { scale: 0.7 }, {
          scale: 1, transformOrigin: '50% 50%', duration: 0.4, ease: 'back.out(1.5)',
        }, 0)
    } else if (state === 'error') {
      timeline.to(part('head'), { rotation: -2.5, duration: 0.12 })
        .to(part('head'), { rotation: 2.5, duration: 0.16 })
        .to(part('head'), { rotation: 0, duration: 0.12 })
    } else if (state === 'offline') {
      // Offline is intentionally still; shape and expression communicate the state.
      gsap.set(part('eyes'), { opacity: 0.65 })
    }
  }, svg)
}
