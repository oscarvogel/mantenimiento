import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ChatMessage from './ChatMessage.vue'

describe('ChatMessage', () => {
  it('renderiza un link markdown absoluto sin prefijar otra base URL', () => {
    const wrapper = mount(ChatMessage, {
      props: {
        message: {
          role: 'assistant',
          content: 'Ver detalle: [Equipo](http://192.168.0.195:8090/mantenimiento/equipos/98)',
        },
      },
    })

    const link = wrapper.get('a')
    expect(link.attributes('href')).toBe('http://192.168.0.195:8090/mantenimiento/equipos/98')
    expect(link.text()).toBe('Equipo')
    expect(link.attributes('rel')).toBe('noopener noreferrer')
    expect(wrapper.html()).not.toContain('vogelconsultoria.comhttp://')
  })

  it('convierte una ruta relativa histórica usando el origin una sola vez', () => {
    const wrapper = mount(ChatMessage, {
      props: {
        message: {
          role: 'assistant',
          content: 'Ver detalle: /mantenimiento/equipos/98',
        },
      },
    })

    expect(wrapper.get('a').attributes('href')).toContain('/mantenimiento/equipos/98')
    expect(wrapper.findAll('a')).toHaveLength(1)
  })

  it('no convierte protocolos inseguros en links', () => {
    const wrapper = mount(ChatMessage, {
      props: {
        message: {
          role: 'assistant',
          content: '[Abrir](javascript:alert(1))',
        },
      },
    })

    expect(wrapper.find('a').exists()).toBe(false)
    expect(wrapper.text()).toContain('javascript:alert(1)')
  })

  it('rechaza una URL con dos esquemas concatenados', () => {
    const broken = 'https://demo.stg.vogelconsultoria.comhttp://192.168.0.195:8090/mantenimiento/equipos/98'
    const wrapper = mount(ChatMessage, {
      props: {
        message: {
          role: 'assistant',
          content: `Ver detalle: ${broken}`,
        },
      },
    })

    expect(wrapper.find('a').exists()).toBe(false)
    expect(wrapper.text()).toContain(broken)
  })
})
