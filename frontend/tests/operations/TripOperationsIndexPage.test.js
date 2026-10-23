import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import TripOperationsIndexPage from '../../src/pages/operations/TripOperationsIndexPage.vue'

const data = {
  canOperate: true,
  links: { index: '/mantenimiento/viajes/operativos', create: '/mantenimiento/viajes/operativos/nuevo' },
  filters: { from: '', to: '', branchId: null, status: null, search: null, branchName: null },
  options: {
    branches: [{ id: 10, code: 'AR', name: 'Argentina' }],
    statuses: [
      { value: 'PLANIFICADO', label: 'Planificado' },
      { value: 'EN_CURSO', label: 'En curso' },
    ],
  },
  trips: [{
    id: 42, numero: 7, estado: 'PLANIFICADO', estado_label: 'Planificado', sucursal_id: 10,
    fecha_salida_planificada: '2026-10-20 08:00:00', tractor: 'AA111AA · T01', acoplado: 'AC222AC · A01',
    choferes: ['Pérez, Juan'], origen: 'Rosario', destino: 'Córdoba', paradas: 2,
  }],
  pagination: { page: 1, perPage: 20, total: 1, pages: 1 },
}

describe('viajes operativos', () => {
  it('muestra el listado con recorrido, equipo y choferes', () => {
    const wrapper = mount(TripOperationsIndexPage, { props: { data } })

    expect(wrapper.text()).toContain('Viajes operativos')
    expect(wrapper.text()).toContain('#7')
    expect(wrapper.text()).toContain('Rosario → Córdoba')
    expect(wrapper.text()).toContain('AA111AA · T01')
    expect(wrapper.text()).toContain('Pérez, Juan')
    expect(wrapper.get('form').attributes('method')).toBe('get')
    expect(wrapper.get('a[href="/mantenimiento/viajes/operativos/42"]').text()).toContain('Ver viaje')
    expect(wrapper.get('a[href="/mantenimiento/viajes/operativos/nuevo"]').text()).toContain('Nuevo viaje')
    wrapper.unmount()
  })

  it('oculta el alta cuando el usuario no puede operar', () => {
    const wrapper = mount(TripOperationsIndexPage, { props: { data: { ...data, canOperate: false } } })

    expect(wrapper.find('a[href="/mantenimiento/viajes/operativos/nuevo"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('muestra estado vacío cuando no hay viajes', () => {
    const wrapper = mount(TripOperationsIndexPage, { props: { data: { ...data, trips: [], pagination: { page: 1, perPage: 20, total: 0, pages: 1 } } } })

    expect(wrapper.text()).toContain('Todavía no hay viajes operativos para mostrar')
    wrapper.unmount()
  })
})
