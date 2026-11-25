import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import TireMastersPage from './TireMastersPage.vue'

const item = (id, name, extra = {}) => ({
  id,
  name,
  active: true,
  updateUrl: `/neumaticos/maestros/x/${id}`,
  statusUrl: `/neumaticos/maestros/x/${id}/estado`,
  ...extra,
})

const data = (overrides = {}) => ({
  csrf: { name: 'csrf_test', hash: 'abc' },
  flash: {},
  canEdit: true,
  urls: { index: '/neumaticos/maestros', create: '/neumaticos/maestros', portal: '/inicio' },
  branches: [{ id: 1, name: 'Oberá' }, { id: 2, name: 'Posadas' }],
  catalogs: {
    marcas: { label: 'Marcas', items: [item(3, 'Michelin'), item(4, 'Xbri', { active: false })] },
    modelos: { label: 'Modelos', items: [item(5, 'X Multi D', { brandId: 3, brandName: 'Michelin' })] },
    'tipos-cubierta': { label: 'Tipos de cubierta', items: [] },
    depositos: { label: 'Depósitos', items: [item(8, 'Central', { branchId: 1, branchName: 'Oberá', location: 'Ruta 14' })] },
  },
  ...overrides,
})

const mountPage = (overrides = {}) => mount(TireMastersPage, {
  props: { data: data(overrides) },
  attachTo: document.body,
})

const tab = (wrapper, label) => wrapper.findAll('[role="tab"]').find((button) => button.text().startsWith(label))

afterEach(() => {
  document.body.innerHTML = ''
  window.history.replaceState(null, '', '#')
})

describe('TireMastersPage', () => {
  it('muestra las cuatro pestañas con su cantidad y arranca en Marcas', () => {
    const wrapper = mountPage()

    expect(wrapper.findAll('[role="tab"]').map((button) => button.text())).toEqual(['Marcas 2', 'Modelos 1', 'Tipos de cubierta 0', 'Depósitos 1'])
    expect(wrapper.text()).toContain('Michelin')
    expect(wrapper.text()).toContain('Inactivo')
  })

  it('crea en el maestro elegido con su nombre de campo', async () => {
    const wrapper = mountPage()

    await tab(wrapper, 'Tipos de cubierta').trigger('click')

    const form = wrapper.find('form[action="/neumaticos/maestros"]')
    expect(form.find('input[name="catalogo"]').element.value).toBe('tipos-cubierta')
    expect(form.find('input[name="descripcion"]').exists()).toBe(true)
    expect(form.find('input[name="csrf_test"]').element.value).toBe('abc')
    expect(wrapper.text()).toContain('Todavía no hay tipos de cubierta cargados.')
  })

  it('el alta de modelo pide una marca activa y no ofrece las inactivas', async () => {
    const wrapper = mountPage()

    await tab(wrapper, 'Modelos').trigger('click')

    const options = wrapper.findAll('#tire-master-brand option').map((option) => option.text())
    expect(options).toEqual(['Elegí una marca', 'Michelin'])
    expect(wrapper.text()).toContain('Michelin')
  })

  it('sin marcas activas explica por qué no se puede cargar un modelo', async () => {
    const wrapper = mountPage({
      catalogs: { ...data().catalogs, marcas: { label: 'Marcas', items: [item(4, 'Xbri', { active: false })] } },
    })

    await tab(wrapper, 'Modelos').trigger('click')

    expect(wrapper.text()).toContain('Para cargar un modelo primero tiene que haber una marca activa.')
    expect(wrapper.find('#tire-master-brand').exists()).toBe(false)
  })

  it('el depósito muestra sucursal y ubicación y pide la sucursal al crearlo', async () => {
    const wrapper = mountPage()

    await tab(wrapper, 'Depósitos').trigger('click')

    expect(wrapper.text()).toContain('Oberá · Ruta 14')
    expect(wrapper.findAll('#tire-master-branch option').map((option) => option.text())).toEqual(['Elegí una sucursal', 'Oberá', 'Posadas'])
  })

  it('filtra por texto y por estado', async () => {
    const wrapper = mountPage()

    await wrapper.find('input[type="search"]').setValue('xbr')
    expect(wrapper.text()).toContain('Xbri')
    expect(wrapper.text()).not.toContain('Michelin')

    await wrapper.find('input[type="search"]').setValue('')
    await wrapper.findAll('select').at(-1).setValue('active')
    expect(wrapper.text()).toContain('Michelin')
    expect(wrapper.text()).not.toContain('Xbri')
  })

  it('inactivar y activar mandan el estado contrario', () => {
    const wrapper = mountPage()

    const values = wrapper.findAll('form[action$="/estado"] input[name="activo"]').map((input) => input.element.value)
    expect(values).toEqual(['0', '1'])
  })

  it('editar abre el modal con los datos del registro', async () => {
    const wrapper = mountPage()

    await wrapper.findAll('button').find((button) => button.text() === 'Editar').trigger('click')

    const dialog = document.querySelector('[role="dialog"]')
    expect(dialog).not.toBeNull()
    expect(dialog.querySelector('form').getAttribute('action')).toBe('/neumaticos/maestros/x/3')
    expect(dialog.querySelector('#tire-master-edit-name').value).toBe('Michelin')
  })

  it('quien solo consulta no ve formularios ni acciones', () => {
    const wrapper = mountPage({ canEdit: false })

    expect(wrapper.find('form').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('Editar')
    expect(wrapper.text()).toContain('Michelin')
  })

  it('muestra el mensaje de error del servidor', () => {
    const wrapper = mountPage({ flash: { error: 'Ya existe la marca "Michelin" en la empresa.' } })

    expect(wrapper.find('[role="alert"]').text()).toBe('Ya existe la marca "Michelin" en la empresa.')
  })

  it('abre la pestaña que indica el hash de la URL', () => {
    window.history.replaceState(null, '', '#depositos')

    const wrapper = mountPage()

    expect(wrapper.find('[role="tab"][aria-selected="true"]').text()).toContain('Depósitos')
  })
})
