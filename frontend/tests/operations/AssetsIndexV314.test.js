import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import AssetsIndexPage from '../../src/pages/operations/AssetsIndexPage.vue'
import { assetsData } from './fixtures.js'

const wrappers = []

const makeData = (overrides = {}) => {
  const data = JSON.parse(JSON.stringify(assetsData))
  data.catalogs.branches = [
    { id: 1, code: 'PR', name: 'Puerto Rico' },
    { id: 2, code: 'POS', name: 'Posadas' },
  ]
  data.equipment.items = [{
    ...data.equipment.items[0],
    controlsKm: true,
    controlsHours: true,
    currentKm: 12500,
    currentHours: '1250.5',
    ...overrides,
  }]
  return data
}

afterEach(() => wrappers.splice(0).forEach((wrapper) => wrapper.unmount()))

describe('V3.1.4 · listado de equipos', () => {
  it('usa un selector de sucursal con nombres humanos y conserva el valor filtrado', () => {
    const data = makeData()
    data.filters.branchId = 2
    const wrapper = mount(AssetsIndexPage, { props: { data } })
    wrappers.push(wrapper)

    const filter = wrapper.get('#asset-filter-branch')
    expect(filter.element.tagName).toBe('SELECT')
    expect(filter.attributes('name')).toBe('sucursal_id')
    expect(filter.element.value).toBe('2')
    expect(filter.find('option[value=""]').text()).toBe('Todas')
    expect(filter.text()).toContain('Puerto Rico')
    expect(filter.text()).toContain('Posadas')
  })

  it('muestra solo kilometraje cuando el tipo no controla horas', () => {
    const wrapper = mount(AssetsIndexPage, { props: { data: makeData({ controlsKm: true, controlsHours: false }) } })
    wrappers.push(wrapper)

    expect(wrapper.text()).toContain('12.500 km')
    expect(wrapper.text()).not.toContain('1.250,5 h')
  })

  it('muestra solo horómetro cuando el tipo no controla kilómetros', () => {
    const wrapper = mount(AssetsIndexPage, { props: { data: makeData({ controlsKm: false, controlsHours: true }) } })
    wrappers.push(wrapper)

    expect(wrapper.text()).not.toContain('12.500 km')
    expect(wrapper.text()).toContain('1.250,5 h')
  })

  it('muestra ambas métricas con formato es-AR cuando el tipo las controla', () => {
    const wrapper = mount(AssetsIndexPage, { props: { data: makeData({ controlsKm: true, controlsHours: true }) } })
    wrappers.push(wrapper)

    expect(wrapper.text()).toContain('12.500 km')
    expect(wrapper.text()).toContain('1.250,5 h')
  })

  it('muestra Sin datos para una métrica controlada sin lectura', () => {
    const wrapper = mount(AssetsIndexPage, { props: { data: makeData({ controlsKm: true, controlsHours: false, currentKm: null }) } })
    wrappers.push(wrapper)

    expect(wrapper.text()).toContain('Sin datos')
    expect(wrapper.text()).not.toContain(' h')
  })
})
