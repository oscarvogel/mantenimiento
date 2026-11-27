import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ProvidersIndexPage from './ProvidersIndexPage.vue'

const data = (filters) => ({
  actions: { index: '/mantenimiento/proveedores', create: '/mantenimiento/proveedores' },
  metrics: { total: 5, active: 4, inactive: 1 },
  filters,
  permissions: { edit: false },
  providers: [],
  csrf: { name: 'csrf', hash: 'x' },
})

describe('ProvidersIndexPage', () => {
  it('las píldoras filtran activos e inactivos y conservan la búsqueda', () => {
    const nav = mount(ProvidersIndexPage, { props: { data: data({ q: 'hidra', status: 'inactivo' }) } }).get('nav[aria-label="Proveedores por estado"]')

    expect(nav.findAll('a').map((chip) => chip.text())).toEqual(['Activos 4', 'Inactivos 1'])
    expect(nav.get('a[aria-current="true"]').attributes('href')).toBe('/mantenimiento/proveedores?q=hidra&estado=inactivo')
    expect(nav.get('a').attributes('href')).toBe('/mantenimiento/proveedores?q=hidra')
  })
})
