import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ChatMessage from '../../src/pages/operations/components/ChatMessage.vue'

describe('ChatMessage', () => {
  it('renderiza enlaces Markdown sin duplicar el origen', () => {
    const href = 'https://vogelconsultoria.com.ar/mantenimiento/mantenimiento/equipos/37'
    const wrapper = mount(ChatMessage, {
      props: {
        message: { role: 'assistant', content: `Ver [detalle](${href})` },
      },
    })

    const link = wrapper.get('a')
    expect(link.attributes('href')).toBe(href)
    expect(link.text()).toBe('detalle')
    expect(wrapper.text()).toContain('Ver detalle')
    expect(wrapper.html()).not.toContain(`http://localhost${href}`)
  })

  it('convierte una URL relativa en un enlace seguro', () => {
    const wrapper = mount(ChatMessage, {
      props: {
        message: { role: 'assistant', content: 'Ver /mantenimiento/planes?estado=VENCIDO' },
      },
    })

    expect(wrapper.get('a').attributes('href')).toBe(`${window.location.origin}/mantenimiento/planes?estado=VENCIDO`)
  })

  it('corrige enlaces antiguos de planes cuando la app vive en subdirectorio', () => {
    window.history.pushState({}, '', '/mantenimiento/dashboard')
    const wrapper = mount(ChatMessage, {
      props: {
        message: { role: 'assistant', content: `[planes](${window.location.origin}/mantenimiento/planes?estado=VENCIDO)` },
      },
    })

    expect(wrapper.get('a').attributes('href')).toBe(`${window.location.origin}/mantenimiento/mantenimiento/planes?estado=VENCIDO`)
  })
})
