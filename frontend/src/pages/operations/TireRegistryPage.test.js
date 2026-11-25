import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import TireRegistryPage from './TireRegistryPage.vue'

const tire = (id, number, extra = {}) => ({
  id,
  number,
  status: 'NUEVA',
  statusLabel: 'Nueva',
  size: '',
  year: null,
  brandName: null,
  modelName: null,
  coverTypeName: null,
  branchId: 1,
  branchName: 'Oberá',
  url: `/neumaticos/${id}`,
  ...extra,
})

const data = (overrides = {}) => ({
  csrf: { name: 'csrf_test', hash: 'abc' },
  canOperate: true,
  total: 2,
  limit: 300,
  filters: { q: '', estado: '', sucursal: null, marca: null },
  urls: { index: '/neumaticos', create: '/neumaticos', masters: '/neumaticos/maestros' },
  oldInput: {},
  items: [
    tire(1, 'TSA 1', { brandName: 'Michelin', modelName: 'X Multi D', size: '295/80 R22.5' }),
    tire(2, 'TSA 2', { status: 'EN_REPARACION', statusLabel: 'En reparación', branchName: 'Posadas' }),
  ],
  options: {
    branches: [{ id: 1, name: 'Oberá' }, { id: 2, name: 'Posadas' }],
    brands: [{ id: 3, name: 'Michelin' }, { id: 4, name: 'Bridgestone' }],
    models: [{ id: 5, name: 'X Multi D', brandId: 3 }, { id: 6, name: 'R168', brandId: 4 }],
    coverTypes: [{ id: 2, name: 'Lisa' }],
    statuses: [{ value: 'NUEVA', label: 'Nueva' }, { value: 'EN_REPARACION', label: 'En reparación' }],
    initialStatuses: [{ value: 'NUEVA', label: 'Nueva' }, { value: 'MEDIO_USO', label: '1/2 uso' }],
  },
  ...overrides,
})

const mountPage = (overrides = {}) => mount(TireRegistryPage, { props: { data: data(overrides) }, attachTo: document.body })
const dialog = () => document.querySelector('[role="dialog"]')

afterEach(() => {
  document.body.innerHTML = ''
  window.history.replaceState(null, '', '#')
})

describe('TireRegistryPage', () => {
  it('lista cada neumático con su ficha, descripción, sucursal y estado', () => {
    const wrapper = mountPage()

    const links = wrapper.findAll('ul a')
    expect(links.map((link) => link.attributes('href'))).toEqual(['/neumaticos/1', '/neumaticos/2'])
    expect(links[0].text()).toContain('Michelin · X Multi D · 295/80 R22.5')
    expect(links[1].text()).toContain('Sin marca ni medida cargadas')
    expect(links[1].text()).toContain('Posadas')
    expect(links[1].text()).toContain('En reparación')
    expect(wrapper.text()).toContain('2 neumáticos')
  })

  it('filtra por GET con los filtros que vienen del servidor', () => {
    const wrapper = mountPage({ filters: { q: 'tsa', estado: 'EN_REPARACION', sucursal: 2, marca: null } })

    const form = wrapper.find('form[method="get"]')
    expect(form.attributes('action')).toBe('/neumaticos')
    expect(form.find('input[name="q"]').element.value).toBe('tsa')
    expect(form.find('select[name="estado"]').element.value).toBe('EN_REPARACION')
    expect(form.find('select[name="sucursal"]').element.value).toBe('2')
    expect(wrapper.find('a[href="/neumaticos"]').text()).toBe('Limpiar')
  })

  it('distingue padrón vacío de búsqueda sin resultados', () => {
    expect(mountPage({ items: [], total: 0 }).text()).toContain('Todavía no hay neumáticos en el padrón.')
    expect(mountPage({ items: [], total: 0, filters: { q: 'xx', estado: '', sucursal: null, marca: null } }).text())
      .toContain('Ningún neumático coincide con los filtros.')
  })

  it('avisa cuando el padrón supera el límite que se muestra', () => {
    expect(mountPage({ total: 450 }).text()).toContain('Se muestran los primeros 2.')
  })

  it('el alta ofrece solo los modelos de la marca elegida', async () => {
    const wrapper = mountPage()

    await wrapper.findAll('button').find((button) => button.text().includes('Nuevo neumático')).trigger('click')
    expect(dialog()).not.toBeNull()
    const model = dialog().querySelector('#tire-create-model')
    expect(model.disabled).toBe(true)

    const brand = dialog().querySelector('#tire-create-brand')
    brand.value = '4'
    brand.dispatchEvent(new Event('change'))
    await wrapper.vm.$nextTick()

    expect([...dialog().querySelectorAll('#tire-create-model option')].map((option) => option.textContent)).toEqual(['Sin modelo', 'R168'])
    expect(dialog().querySelector('form').getAttribute('action')).toBe('/neumaticos')
    expect(dialog().querySelector('input[name="csrf_test"]').value).toBe('abc')
  })

  it('reabre el alta con lo cargado cuando el servidor la rechazó', () => {
    mountPage({ oldInput: { numero: 'TSA 9', sucursal_id: '2', medida: '275/80' } })

    expect(dialog()).not.toBeNull()
    expect(dialog().querySelector('#tire-create-number').value).toBe('TSA 9')
    expect(dialog().querySelector('#tire-create-branch').value).toBe('2')
    expect(dialog().querySelector('#tire-create-size').value).toBe('275/80')
  })

  it('con una sola sucursal la deja elegida', async () => {
    const wrapper = mountPage({ options: { ...data().options, branches: [{ id: 1, name: 'Oberá' }] } })

    await wrapper.findAll('button').find((button) => button.text().includes('Nuevo neumático')).trigger('click')

    expect(dialog().querySelector('#tire-create-branch').value).toBe('1')
  })

  it('quien solo consulta no ve el alta', () => {
    const wrapper = mountPage({ canOperate: false, oldInput: { numero: 'x' } })

    expect(wrapper.text()).not.toContain('Nuevo neumático')
    expect(dialog()).toBeNull()
  })

  it('sin marcas activas lo explica y lleva a Maestros', () => {
    const wrapper = mountPage({ options: { ...data().options, brands: [] } })

    expect(wrapper.text()).toContain('Todavía no hay marcas activas.')
    expect(wrapper.find('select[name="marca"]').exists()).toBe(false)
  })
})
