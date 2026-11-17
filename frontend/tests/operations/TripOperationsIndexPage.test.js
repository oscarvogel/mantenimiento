import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import TripOperationsIndexPage from '../../src/pages/operations/TripOperationsIndexPage.vue'
import AutocompleteSelect from '../../src/pages/operations/components/AutocompleteSelect.vue'

const data = {
  canOperate: true,
  links: { index: '/mantenimiento/viajes/operativos', create: '/mantenimiento/viajes/operativos/nuevo' },
  filters: { from: '', to: '', branchId: null, status: null, search: null, plate: null, order: 'desc', branchName: null },
  options: {
    branches: [{ id: 10, code: 'AR', name: 'Argentina' }],
    equipment: [{ value: 'AA111AA', label: 'AA111AA', hint: 'T01' }],
    statuses: [
      { value: 'PLANIFICADO', label: 'Planificado' },
      { value: 'EN_CURSO', label: 'En curso' },
      { value: 'PENDIENTE_CIERRE', label: 'Recorrido cerrado, falta cerrar el viaje' },
    ],
  },
  trips: [{
    id: 42, numero: 7, estado: 'PLANIFICADO', estado_label: 'Planificado', sucursal_id: 10,
    fecha_salida_planificada: '2026-10-20 08:00:00', tractor: 'AA111AA · T01', acoplado: 'AC222AC · A01',
    choferes: ['Pérez, Juan'], origen: 'Rosario', destino: 'Córdoba', paradas: 2, tramos: 2,
    tramos_cerrados: 0, recorrido_cerrado: false, fecha_fin_ultimo: null,
  }],
  pagination: { page: 1, perPage: 20, total: 1, pages: 1 },
}

describe('viajes operativos', () => {
  it('muestra el listado con recorrido, equipo y choferes', () => {
    const wrapper = mount(TripOperationsIndexPage, { props: { data } })

    expect(wrapper.text()).toContain('Viajes operativos')
    expect(wrapper.text()).toContain('#7')
    expect(wrapper.text()).toContain('Rosario → Córdoba')
    expect(wrapper.text()).toContain('2 tramos · 2 paradas')
    expect(wrapper.text()).toContain('AA111AA · T01')
    expect(wrapper.text()).toContain('Pérez, Juan')
    expect(wrapper.text()).toContain('20/10/2026')
    expect(wrapper.text()).not.toContain('20/10/2026 08:00')
    expect(wrapper.get('form').attributes('method')).toBe('get')
    expect(wrapper.get('a[href="/mantenimiento/viajes/operativos/42"]').text()).toContain('Ver viaje')
    expect(wrapper.get('a[href="/mantenimiento/viajes/operativos/nuevo"]').text()).toContain('Nuevo viaje')
    wrapper.unmount()
  })

  it('informa que el viaje no tiene recorrido cuando no hay tramos ni paradas', () => {
    const payload = { ...data, trips: [{ ...data.trips[0], origen: null, destino: null, paradas: 0, tramos: 0 }] }
    const wrapper = mount(TripOperationsIndexPage, { props: { data: payload } })

    expect(wrapper.text()).toContain('Sin recorrido')
    expect(wrapper.text()).toContain('Sin tramos')
    expect(wrapper.text()).not.toContain('Sin origen → Sin destino')
    wrapper.unmount()
  })

  it('oculta el alta cuando el usuario no puede operar', () => {
    const wrapper = mount(TripOperationsIndexPage, { props: { data: { ...data, canOperate: false } } })

    expect(wrapper.find('a[href="/mantenimiento/viajes/operativos/nuevo"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('filtra por patente y deja elegir el orden', () => {
    const wrapper = mount(TripOperationsIndexPage, { props: { data } })

    const plate = wrapper.findAllComponents(AutocompleteSelect).find((component) => component.props('name') === 'patente')
    expect(plate).toBeTruthy()
    expect(plate.props('options')).toEqual([{ value: 'AA111AA', label: 'AA111AA', hint: 'T01' }])

    const order = wrapper.get('select[name="orden"]')
    expect(order.findAll('option').map((option) => option.attributes('value'))).toEqual(['desc', 'asc'])
    expect(wrapper.text()).toContain('Más recientes primero')
    expect(wrapper.text()).toContain('Más antiguos primero')
    expect(wrapper.text()).toContain('Recorrido cerrado, falta cerrar el viaje')
    wrapper.unmount()
  })

  it('avisa que el recorrido termino y que falta cerrar el viaje', () => {
    const payload = {
      ...data,
      trips: [{
        ...data.trips[0],
        estado: 'EN_CURSO',
        estado_label: 'En curso',
        tramos: 2,
        tramos_cerrados: 2,
        recorrido_cerrado: true,
        fecha_fin_ultimo: '2026-11-11',
      }],
    }
    const wrapper = mount(TripOperationsIndexPage, { props: { data: payload } })

    expect(wrapper.text()).toContain('Recorrido cerrado')
    expect(wrapper.text()).toContain('Falta cerrar el viaje')
    wrapper.unmount()
  })

  it('muestra estado vacío cuando no hay viajes', () => {
    const wrapper = mount(TripOperationsIndexPage, { props: { data: { ...data, trips: [], pagination: { page: 1, perPage: 20, total: 0, pages: 1 } } } })

    expect(wrapper.text()).toContain('Todavía no hay viajes operativos para mostrar')
    wrapper.unmount()
  })
})

const trip = (id) => ({
  id, numero: id, estado: 'FINALIZADO', estado_label: 'Finalizado', fecha_salida_real: '2026-11-01 08:00:00',
  tractor: 'AA111AA', choferes: ['Pérez, Juan'], paradas: 2, tramos: [],
})

const pagedData = (pagination, extra = {}) => ({
  filters: { order: 'desc' },
  options: { branches: [], statuses: [], equipment: [] },
  links: { index: '/viajes/operativos', dashboard: '/viajes/operativos/dashboard', create: '/viajes/operativos/nuevo' },
  canOperate: true,
  hasActiveFilters: false,
  trips: [trip(1), trip(2)],
  pagination: { perPageOptions: [10, 25, 50, 100], ...pagination },
  ...extra,
})

const linkByText = (wrapper, text) => wrapper.findAll('nav a').find((link) => link.text() === text)
const disabledByText = (wrapper, text) => wrapper.findAll('nav span[aria-disabled="true"]').find((item) => item.text() === text)

beforeEach(() => {
  window.history.replaceState({}, '', '/viajes/operativos?estado=FINALIZADO&orden=asc&pagina=2')
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('paginación de viajes operativos (#532)', () => {
  it('muestra el rango visible, el total y la página actual', () => {
    const wrapper = mount(TripOperationsIndexPage, {
      props: { data: pagedData({ page: 2, perPage: 25, total: 346, pages: 14, from: 26, to: 50 }) },
    })

    expect(wrapper.text()).toContain('Mostrando 26–50 de 346 viajes')
    expect(wrapper.text()).toContain('Página 2 de 14')
  })

  it('arma primera, anterior, siguiente y última conservando filtros y orden', () => {
    const wrapper = mount(TripOperationsIndexPage, {
      props: { data: pagedData({ page: 2, perPage: 25, total: 346, pages: 14, from: 26, to: 50 }) },
    })

    expect(linkByText(wrapper, 'Primera').attributes('href')).toBe('/viajes/operativos?estado=FINALIZADO&orden=asc')
    expect(linkByText(wrapper, 'Anterior').attributes('href')).toBe('/viajes/operativos?estado=FINALIZADO&orden=asc')
    expect(linkByText(wrapper, 'Siguiente').attributes('href')).toBe('/viajes/operativos?estado=FINALIZADO&orden=asc&pagina=3')
    expect(linkByText(wrapper, 'Última').attributes('href')).toBe('/viajes/operativos?estado=FINALIZADO&orden=asc&pagina=14')
  })

  it('deshabilita primera y anterior en la primera página, y siguiente y última en la última', () => {
    const first = mount(TripOperationsIndexPage, {
      props: { data: pagedData({ page: 1, perPage: 25, total: 26, pages: 2, from: 1, to: 25 }) },
    })
    expect(disabledByText(first, 'Primera')).toBeTruthy()
    expect(disabledByText(first, 'Anterior')).toBeTruthy()
    expect(linkByText(first, 'Siguiente')).toBeTruthy()

    const last = mount(TripOperationsIndexPage, {
      props: { data: pagedData({ page: 2, perPage: 25, total: 26, pages: 2, from: 26, to: 26 }) },
    })
    expect(disabledByText(last, 'Siguiente')).toBeTruthy()
    expect(disabledByText(last, 'Última')).toBeTruthy()
    expect(linkByText(last, 'Anterior')).toBeTruthy()
  })

  it('con una sola página muestra el total y el tamaño, sin botones de navegación', () => {
    const wrapper = mount(TripOperationsIndexPage, {
      props: { data: pagedData({ page: 1, perPage: 25, total: 2, pages: 1, from: 1, to: 2 }) },
    })

    expect(wrapper.text()).toContain('Mostrando 1–2 de 2 viajes')
    expect(wrapper.find('select[aria-label="Viajes por página"]').exists()).toBe(true)
    expect(wrapper.find('select[aria-label="Ir a la página"]').exists()).toBe(false)
    expect(linkByText(wrapper, 'Siguiente')).toBeUndefined()
  })

  it('al cambiar el tamaño vuelve a la primera página y conserva los filtros', async () => {
    const assign = vi.fn()
    vi.stubGlobal('location', { ...window.location, search: window.location.search, assign })
    const wrapper = mount(TripOperationsIndexPage, {
      props: { data: pagedData({ page: 2, perPage: 25, total: 346, pages: 14, from: 26, to: 50 }) },
    })

    await wrapper.find('select[aria-label="Viajes por página"]').setValue('50')

    expect(assign).toHaveBeenCalledWith('/viajes/operativos?estado=FINALIZADO&orden=asc&por_pagina=50')
  })

  it('permite elegir una página concreta', async () => {
    const assign = vi.fn()
    vi.stubGlobal('location', { ...window.location, search: window.location.search, assign })
    const wrapper = mount(TripOperationsIndexPage, {
      props: { data: pagedData({ page: 2, perPage: 25, total: 346, pages: 14, from: 26, to: 50 }) },
    })

    await wrapper.find('select[aria-label="Ir a la página"]').setValue('7')

    expect(assign).toHaveBeenCalledWith('/viajes/operativos?estado=FINALIZADO&orden=asc&pagina=7')
  })

  it('aplicar filtros conserva el tamaño elegido y no arrastra la página', () => {
    const wrapper = mount(TripOperationsIndexPage, {
      props: { data: pagedData({ page: 3, perPage: 50, total: 346, pages: 7, from: 101, to: 150 }) },
    })

    const form = wrapper.find('form[method="get"]')
    expect(form.find('input[type="hidden"][name="por_pagina"]').attributes('value')).toBe('50')
    expect(form.find('[name="pagina"]').exists()).toBe(false)
  })

  it('distingue la falta de viajes de la falta de resultados con filtros', () => {
    const empty = { page: 1, perPage: 25, total: 0, pages: 1, from: 0, to: 0 }

    const filtered = mount(TripOperationsIndexPage, { props: { data: pagedData(empty, { trips: [], hasActiveFilters: true }) } })
    expect(filtered.text()).toContain('Ningún viaje cumple estos filtros')
    expect(filtered.text()).not.toContain('Todavía no hay viajes operativos')

    const none = mount(TripOperationsIndexPage, { props: { data: pagedData(empty, { trips: [], hasActiveFilters: false }) } })
    expect(none.text()).toContain('Todavía no hay viajes operativos para mostrar')
  })
})
