import { describe, expect, it } from 'vitest'
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
