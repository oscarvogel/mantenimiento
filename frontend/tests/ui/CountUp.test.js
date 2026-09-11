import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import CountUp from '../../src/components/CountUp.vue'

afterEach(() => vi.unstubAllGlobals())

describe('CountUp', () => {
  it('formats the final value and keeps it static when reduced motion is preferred', () => {
    vi.stubGlobal('matchMedia', vi.fn().mockReturnValue({ matches: true }))

    const wrapper = mount(CountUp, {
      props: {
        value: 1234,
        suffix: '%',
      },
    })

    expect(wrapper.text()).toBe('1.234%')
  })
})
