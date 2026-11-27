import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import FleetAvailabilityPanel from './FleetAvailabilityPanel.vue'

const fleet = {
  total: 118,
  counts: { operative: 104, inShop: 10, stopped: 4 },
  stopped: [
    { equipmentId: 1, label: 'TR-12 · AB123CD', branchName: 'Casa Central', orderNumber: 'OT-45', orderStatus: 'EN_PROCESO', reason: 'Motor', since: '2026-11-20 08:00:00', href: '/mantenimiento/equipos/1' },
  ],
  inShop: [
    { equipmentId: 2, label: 'AC-07', branchName: 'Rosario', orderNumber: 'OT-49', orderStatus: 'EN_ESPERA_REPUESTOS', reason: 'Frenos', since: '2026-11-24 08:00:00', href: '/mantenimiento/equipos/2' },
  ],
  branches: [{ id: 10, name: 'Casa Central' }, { id: 11, name: 'Rosario' }],
  branchId: null,
  allUrl: '/mantenimiento/equipos',
  formUrl: '/inicio',
}

describe('FleetAvailabilityPanel', () => {
  it('dona con tres tramos, total al centro y un texto accesible equivalente', () => {
    const wrapper = mount(FleetAvailabilityPanel, { props: { fleet } })

    const segments = wrapper.findAll('[data-test="donut-segment"]')
    expect(segments.map((segment) => segment.attributes('data-tone'))).toEqual(['operative', 'in-shop', 'stopped'])
    expect(wrapper.get('svg').attributes('aria-hidden')).toBe('true')
    expect(wrapper.get('[data-test="donut-total"]').text()).toContain('118')
    expect(wrapper.get('[data-test="donut-total"]').text()).toContain('Equipos activos')
    const legend = wrapper.get('[data-test="donut-legend"]').text()
    expect(legend).toContain('Operativos 104')
    expect(legend).toContain('En taller 10')
    expect(legend).toContain('Detenidos 4')
    expect(wrapper.get('[data-test="donut-summary"]').text()).toBe('118 equipos activos: 104 operativos, 10 en taller y 4 detenidos.')
  })

  it('con un solo tramo el anillo va entero, sin corte', () => {
    const wrapper = mount(FleetAvailabilityPanel, {
      props: { fleet: { ...fleet, total: 1, counts: { operative: 0, inShop: 1, stopped: 0 } } },
    })
    const [segment] = wrapper.findAll('[data-test="donut-segment"]')
    const circumference = 2 * Math.PI * 78
    expect(Number(segment.attributes('stroke-dasharray').split(' ')[0])).toBeCloseTo(circumference)
  })

  it('sin equipos activos muestra Sin datos y ningún tramo', () => {
    const wrapper = mount(FleetAvailabilityPanel, {
      props: { fleet: { ...fleet, total: 0, counts: { operative: 0, inShop: 0, stopped: 0 }, stopped: [], inShop: [] } },
    })

    expect(wrapper.findAll('[data-test="donut-segment"]')).toHaveLength(0)
    expect(wrapper.text()).toContain('Sin datos')
    expect(wrapper.text()).toContain('No hay equipos detenidos')
    expect(wrapper.text()).toContain('No hay equipos en taller')
  })

  it('listas Detenidos y En taller con conteo, OT, sucursal y enlace a la ficha', () => {
    const wrapper = mount(FleetAvailabilityPanel, { props: { fleet } })

    expect(wrapper.get('[data-test="stopped-heading"]').text()).toContain('Detenidos (4)')
    expect(wrapper.get('[data-test="in-shop-heading"]').text()).toContain('En taller (10)')
    const stopped = wrapper.get('[data-test="stopped-list"] a')
    expect(stopped.attributes('href')).toBe('/mantenimiento/equipos/1')
    expect(stopped.text()).toContain('TR-12 · AB123CD')
    expect(stopped.text()).toContain('Casa Central')
    expect(stopped.text()).toContain('OT-45')
    expect(wrapper.get('[data-test="in-shop-list"] a').text()).toContain('Frenos')
    expect(wrapper.findAll('a[href="/mantenimiento/equipos"]').length).toBeGreaterThan(0)
  })

  it('selector de sucursal que envía GET al inicio', () => {
    const wrapper = mount(FleetAvailabilityPanel, { props: { fleet: { ...fleet, branchId: 11 } } })

    const form = wrapper.get('form')
    expect(form.attributes('method')).toBe('get')
    expect(form.attributes('action')).toBe('/inicio')
    const select = form.get('select[name="sucursal"]')
    expect(select.findAll('option').map((option) => option.text())).toEqual(['Todas las sucursales', 'Casa Central', 'Rosario'])
    expect(select.element.value).toBe('11')
  })
})
