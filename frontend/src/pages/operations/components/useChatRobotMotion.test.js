import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, ref } from 'vue'
import { useChatRobotMotion } from './useChatRobotMotion.js'

const animateRobot = vi.fn()
enableAutoUnmount(afterEach)
let media, contexts, visibility
const Harness = defineComponent({
  props: { state: { default: 'idle' } },
  setup(props) { const root = ref(null); useChatRobotMotion(root, () => props.state, async () => ({ animateRobot })); return { root } },
  template: '<svg ref="root" />',
})

beforeEach(() => {
  contexts = []
  animateRobot.mockReset().mockImplementation(() => {
    const context = { revert: vi.fn() }; contexts.push(context); return context
  })
  media = Object.assign(new EventTarget(), { matches: false })
  vi.stubGlobal('matchMedia', () => media)
  vi.spyOn(document, 'hidden', 'get').mockReturnValue(false)
  vi.stubGlobal('IntersectionObserver', class {
    constructor(callback) { visibility = callback }
    observe() { visibility([{ isIntersecting: true }]) }
    disconnect() {}
  })
})
afterEach(() => { vi.restoreAllMocks(); vi.unstubAllGlobals() })

describe('ciclo de vida de animaciones del robot', () => {
  it('revierte el estado anterior y libera animaciones al desmontar', async () => {
    const wrapper = mount(Harness)
    await flushPromises()
    const previous = contexts.at(-1)
    await wrapper.setProps({ state: 'thinking' })
    expect(previous.revert).toHaveBeenCalledOnce()
    expect(animateRobot).toHaveBeenLastCalledWith(wrapper.element, 'thinking')
    const active = contexts.at(-1)
    wrapper.unmount()
    expect(active.revert).toHaveBeenCalledOnce()
  })

  it('respeta cambios de reduced-motion en vivo y retoma el estado más reciente', async () => {
    const wrapper = mount(Harness)
    await flushPromises()
    const active = contexts.at(-1)
    media.matches = true
    media.dispatchEvent(new Event('change'))
    expect(active.revert).toHaveBeenCalledOnce()
    animateRobot.mockClear()
    await wrapper.setProps({ state: 'error' })
    expect(animateRobot).not.toHaveBeenCalled()
    media.matches = false
    media.dispatchEvent(new Event('change'))
    expect(animateRobot).toHaveBeenLastCalledWith(wrapper.element, 'error')
  })

  it('suspende el trabajo fuera de pantalla y en pestañas ocultas', async () => {
    mount(Harness)
    await flushPromises()
    const active = contexts.at(-1)
    visibility([{ isIntersecting: false }])
    expect(active.revert).toHaveBeenCalledOnce()
    animateRobot.mockClear()
    vi.spyOn(document, 'hidden', 'get').mockReturnValue(true)
    visibility([{ isIntersecting: true }])
    expect(animateRobot).not.toHaveBeenCalled()
    vi.spyOn(document, 'hidden', 'get').mockReturnValue(false)
    document.dispatchEvent(new Event('visibilitychange'))
    expect(animateRobot).toHaveBeenCalledOnce()
  })

  it('una carga diferida no crea animaciones después del desmontaje', async () => {
    const wrapper = mount(Harness)
    wrapper.unmount()
    await flushPromises()
    expect(animateRobot).not.toHaveBeenCalled()
  })

  it('desmontar una instancia no revierte las animaciones de otra', async () => {
    const first = mount(Harness)
    const second = mount(Harness, { props: { state: 'loading' } })
    await flushPromises()
    await vi.waitFor(() => expect(animateRobot).toHaveBeenCalledTimes(2))
    const secondIndex = animateRobot.mock.calls.findIndex(([root]) => root === second.element)
    first.unmount()
    expect(contexts[secondIndex].revert).not.toHaveBeenCalled()
  })
})
