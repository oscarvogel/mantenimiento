import {
  animateIn,
  captureFlipState,
  createScrollTrigger,
  dialogTransitionHooks,
  gsapMotionDirective,
  panelTransitionHooks,
  prefersReducedMotion,
} from './gsapMotion.js'

describe('gsapMotion', () => {
  it('usa un fallback seguro cuando el navegador no expone matchMedia', () => {
    const root = document.createElement('section')
    const item = document.createElement('article')
    root.appendChild(item)
    document.body.appendChild(root)

    expect(prefersReducedMotion()).toBe(true)
    expect(captureFlipState([item])).toBeNull()
    expect(createScrollTrigger({})).toBeNull()

    const context = animateIn(root, { targets: 'article' })
    expect(context).not.toBeNull()
    context.revert()
  })

  it('limpia el contexto de la directiva al desmontar', () => {
    const element = document.createElement('div')
    document.body.appendChild(element)

    gsapMotionDirective.mounted(element, { value: { y: 8 } })
    expect(element.__gsapMotionContext).toBeDefined()

    gsapMotionDirective.beforeUnmount(element)
    expect(element.__gsapMotionContext).toBeUndefined()
    expect(element.__gsapMotionPlay).toBeUndefined()
  })

  it('finaliza inmediatamente los hooks de transición con movimiento reducido', () => {
    const element = document.createElement('div')
    const dialog = document.createElement('section')
    dialog.setAttribute('role', 'dialog')
    element.appendChild(dialog)

    const dialogHooks = dialogTransitionHooks({ panelSelector: '[role="dialog"]' })
    const panelHooks = panelTransitionHooks()
    let dialogDone = false
    let panelDone = false

    dialogHooks.beforeEnter(element)
    dialogHooks.enter(element, () => { dialogDone = true })
    panelHooks.enter(element, () => { panelDone = true })

    expect(dialogDone).toBe(true)
    expect(panelDone).toBe(true)
  })
})
