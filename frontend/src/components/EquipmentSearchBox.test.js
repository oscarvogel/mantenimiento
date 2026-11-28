import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import EquipmentSearchBox from './EquipmentSearchBox.vue'

const items = [
  { id: 1, label: 'TR-12 · AB123CD', detail: 'Casa Central · Tractor', href: '/mantenimiento/equipos/1' },
  { id: 2, label: 'TR-13', detail: 'Rosario · Tractor', href: '/mantenimiento/equipos/2' },
]

const mountBox = () => mount(EquipmentSearchBox, { attachTo: document.body, props: { url: '/mantenimiento/equipos/buscar' } })
const type = async (wrapper, value) => {
  await wrapper.get('input').setValue(value)
  await vi.advanceTimersByTimeAsync(250)
  await flushPromises()
}

describe('EquipmentSearchBox', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    global.fetch = vi.fn(async () => ({ ok: true, json: async () => ({ items }) }))
  })
  afterEach(() => {
    vi.useRealTimers()
    delete global.fetch
  })

  it('con menos de 2 caracteres no consulta', async () => {
    const wrapper = mountBox()
    await type(wrapper, 'T')

    expect(global.fetch).not.toHaveBeenCalled()
    expect(wrapper.get('input').attributes('aria-expanded')).toBe('false')
    wrapper.unmount()
  })

  it('consulta tras la pausa y muestra opciones accesibles', async () => {
    const wrapper = mountBox()
    await type(wrapper, 'TR 1')

    expect(global.fetch).toHaveBeenCalledOnce()
    expect(global.fetch.mock.calls[0][0]).toBe('/mantenimiento/equipos/buscar?q=TR%201')
    const input = wrapper.get('input[role="combobox"]')
    expect(input.attributes('aria-expanded')).toBe('true')
    const options = wrapper.findAll('[role="listbox"] [role="option"]')
    expect(options).toHaveLength(2)
    expect(options[0].text()).toContain('TR-12 · AB123CD')
    expect(options[0].text()).toContain('Casa Central · Tractor')
    wrapper.unmount()
  })

  it('las flechas mueven la opción activa y Enter navega a ella', async () => {
    const assign = vi.fn()
    const wrapper = mount(EquipmentSearchBox, { attachTo: document.body, props: { url: '/buscar', navigate: assign } })
    await type(wrapper, 'TR')
    const input = wrapper.get('input')

    // La primera opción ya está activa: Enter sin flechas va al primer resultado.
    expect(wrapper.get(`#${input.attributes('aria-activedescendant')}`).text()).toContain('TR-12')
    await input.trigger('keydown', { key: 'ArrowDown' })
    const active = input.attributes('aria-activedescendant')
    expect(wrapper.get(`#${active}`).text()).toContain('TR-13')

    await input.trigger('keydown', { key: 'Enter' })
    expect(assign).toHaveBeenCalledWith('/mantenimiento/equipos/2')
    wrapper.unmount()
  })

  it('sin resultados lo dice con lo que se buscó', async () => {
    global.fetch = vi.fn(async () => ({ ok: true, json: async () => ({ items: [] }) }))
    const wrapper = mountBox()
    await type(wrapper, 'ZZ')

    expect(wrapper.text()).toContain('No encontramos equipos con «ZZ»')
    wrapper.unmount()
  })

  it('un error de red se informa sin romper', async () => {
    global.fetch = vi.fn(async () => { throw new Error('offline') })
    const wrapper = mountBox()
    await type(wrapper, 'TR')

    expect(wrapper.text()).toContain('No pudimos buscar. Probá de nuevo.')
    wrapper.unmount()
  })
})
