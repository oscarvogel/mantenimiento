import { afterEach, describe, expect, it } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import EquipmentCatalogsMasterPage from '../../src/pages/operations/EquipmentCatalogsMasterPage.vue'

const wrappers = []

const data = {
  csrf: { name: 'csrf_test_name', hash: 'secure-token' },
  routes: {
    index: '/mantenimiento/maestros/equipos',
    createBrand: '/mantenimiento/catalogos/marcas',
    createModel: '/mantenimiento/catalogos/modelos',
    expirationTypes: '/mantenimiento/maestros/vencimientos',
    services: '/mantenimiento/servicios',
    preventiveLibrary: '/mantenimiento/importaciones/biblioteca',
    providers: '/mantenimiento/proveedores',
    // Sucursales no se expone desde el centro: sigue en Administracion.
  },
  catalogs: {
    types: [
      { id: 1, name: 'Camión', active: true, controlsKm: true, controlsHours: false, updateUrl: '/mantenimiento/catalogos/tipos/1' },
      { id: 2, name: 'Máquina', active: true, controlsKm: false, controlsHours: true, updateUrl: '/mantenimiento/catalogos/tipos/2' },
    ],
    brands: [
      { id: 10, name: 'IVECO', active: true, updateUrl: '/mantenimiento/catalogos/marcas/10', inactivateUrl: '/mantenimiento/catalogos/marcas/10/inactivar' },
      { id: 11, name: 'SCANIA', active: true, updateUrl: '/mantenimiento/catalogos/marcas/11', inactivateUrl: '/mantenimiento/catalogos/marcas/11/inactivar' },
    ],
  },
  management: {
    brands: {
      total: 2,
      items: [
        { id: 10, name: 'IVECO', active: true, updateUrl: '/mantenimiento/catalogos/marcas/10', inactivateUrl: '/mantenimiento/catalogos/marcas/10/inactivar' },
        { id: 11, name: 'SCANIA', active: true, updateUrl: '/mantenimiento/catalogos/marcas/11', inactivateUrl: '/mantenimiento/catalogos/marcas/11/inactivar' },
      ],
      pagination: { page: 1, totalPages: 1, total: 2, perPage: 10, previousUrl: null, nextUrl: null },
    },
    models: {
      total: 2,
      items: [
        { id: 20, name: 'STRALIS', active: true, brandId: 10, typeId: 1, brandName: 'IVECO', typeName: 'Camión', updateUrl: '/mantenimiento/catalogos/modelos/20', inactivateUrl: '/mantenimiento/catalogos/modelos/20/inactivar' },
        { id: 21, name: 'R450', active: true, brandId: 11, typeId: 1, brandName: 'SCANIA', typeName: 'Camión', updateUrl: '/mantenimiento/catalogos/modelos/21', inactivateUrl: '/mantenimiento/catalogos/modelos/21/inactivar' },
      ],
      pagination: { page: 1, totalPages: 1, total: 2, perPage: 10, previousUrl: null, nextUrl: null },
    },
  },
}

const render = () => {
  const wrapper = mount(EquipmentCatalogsMasterPage, { props: { data }, attachTo: document.body })
  wrappers.push(wrapper)
  return wrapper
}

afterEach(() => wrappers.splice(0).forEach((wrapper) => wrapper.unmount()))

describe('EquipmentCatalogsMasterPage', () => {
  it('funciona como centro de maestros y evita formularios inline masivos', () => {
    const wrapper = render()

    expect(wrapper.text()).toContain('Maestros')
    expect(wrapper.find('a[href="/mantenimiento/maestros/vencimientos"]').exists()).toBe(true)
    expect(wrapper.find('a[href="/mantenimiento/servicios"]').exists()).toBe(true)
    expect(wrapper.find('a[href="/mantenimiento/importaciones/biblioteca"]').exists()).toBe(true)
    expect(wrapper.find('a[href="/mantenimiento/proveedores"]').exists()).toBe(true)
    expect(wrapper.findAll('form[action^="/mantenimiento/catalogos/marcas/"]')).toHaveLength(0)
  })

  it('no ofrece Sucursales dentro del centro de Maestros', () => {
    const wrapper = render()

    expect(wrapper.text()).not.toContain('Sucursales')
    expect(wrapper.find('a[href="/administracion/sucursales"]').exists()).toBe(false)
    expect(wrapper.html()).not.toContain('Sucursales')
  })

  it('expone tabs de Tipos, Marcas y Modelos con semantica de tablist', async () => {
    const wrapper = render()

    const tabs = wrapper.findAll('[role="tab"]')
    expect(tabs.map((tab) => tab.text().split('\n')[0].trim().split(' ')[0])).toEqual([
      'Tipos',
      'Marcas',
      'Modelos',
    ])
    expect(tabs[0].attributes('aria-selected')).toBe('true')
    expect(wrapper.find('[role="tablist"]').exists()).toBe(true)

    await tabs[2].trigger('click')
    expect(wrapper.find('[role="tab"][aria-selected="true"]').text()).toContain('Modelos')
  })

  it('filtra por texto y por estado', async () => {
    const wrapper = render()

    const search = wrapper.find('input[placeholder^="Buscar"]')
    expect(wrapper.findAll('table tbody tr')).toHaveLength(2)

    await search.setValue('máquina')
    expect(wrapper.findAll('table tbody tr')).toHaveLength(1)
    expect(wrapper.find('table').text()).toContain('Máquina')

    await search.setValue('')
    const status = wrapper.find('select')
    await status.setValue('active')
    expect(wrapper.findAll('table tbody tr')).toHaveLength(2)

    data.catalogs.types.push({ id: 3, name: 'Utilitario', active: false, controlsKm: false, controlsHours: false, updateUrl: '/mantenimiento/catalogos/tipos/3' })
    await flushPromises()
    await status.setValue('inactive')
    expect(wrapper.findAll('table tbody tr')).toHaveLength(1)
    expect(wrapper.find('table').text()).toContain('Utilitario')
    data.catalogs.types.pop()
  })

  it('filtra modelos por marca y por tipo', async () => {
    const wrapper = render()
    await wrapper.findAll('[role="tab"]').find((tab) => tab.text().startsWith('Modelos')).trigger('click')

    const brandFilter = wrapper.get('select[aria-label="Filtrar por marca"]')
    const typeFilter = wrapper.get('select[aria-label="Filtrar por tipo"]')
    expect(brandFilter.exists()).toBe(true)
    expect(typeFilter.exists()).toBe(true)

    expect(wrapper.findAll('table tbody tr')).toHaveLength(2)

    await brandFilter.setValue('11')
    expect(wrapper.findAll('table tbody tr')).toHaveLength(1)
    expect(wrapper.find('table').text()).toContain('R450')

    await brandFilter.setValue('')
    await typeFilter.setValue('2')
    expect(wrapper.findAll('table tbody tr')).toHaveLength(0)
    expect(wrapper.text()).toContain('No encontramos modelos')
  })

  it('muestra tipos de equipo y permite configurar km/horas desde modal', async () => {
    const wrapper = render()
    expect(wrapper.text()).toContain('Camión')

    const edit = wrapper.findAll('button').find((button) => button.text().includes('Editar control'))
    await edit.trigger('click')
    await flushPromises()

    const modal = document.body.querySelector('[role="dialog"]')
    expect(modal).not.toBeNull()
    const form = modal.querySelector('form[action="/mantenimiento/catalogos/tipos/1"]')
    expect(form).not.toBeNull()
    expect(form.querySelector('input[name="controla_km"]').checked).toBe(true)
    expect(form.querySelector('input[name="controla_horas"]').checked).toBe(false)
  })

  it('usa tablas compactas para marcas y modelos y abre edición en modal', async () => {
    const wrapper = render()

    await wrapper.findAll('[role="tab"]').find((tab) => tab.text().startsWith('Marcas')).trigger('click')
    expect(wrapper.find('table').text()).toContain('IVECO')
    await wrapper.findAll('button').find((button) => button.text() === 'Editar').trigger('click')
    await flushPromises()
    expect(document.body.querySelector('form[action="/mantenimiento/catalogos/marcas/10"]')).not.toBeNull()

    document.body.querySelector('button[aria-label="Cerrar"]').click()
    await flushPromises()

    await wrapper.findAll('[role="tab"]').find((tab) => tab.text().startsWith('Modelos')).trigger('click')
    expect(wrapper.find('table').text()).toContain('STRALIS')
    expect(wrapper.find('table').text()).toContain('IVECO')
    expect(wrapper.find('table').text()).toContain('Camión')
  })
})
