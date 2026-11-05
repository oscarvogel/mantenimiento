import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import TripOperationsDashboardPage from '../../src/pages/operations/TripOperationsDashboardPage.vue'

const data = () => ({
  canOperate: true,
  canSeeEconomics: true,
  links: {
    index: '/mantenimiento/viajes/operativos/dashboard',
    list: '/mantenimiento/viajes/operativos',
    create: '/mantenimiento/viajes/operativos/nuevo',
    history: '/mantenimiento/viajes',
  },
  filters: { from: '2026-10-01', to: '2026-10-31', branchId: 10, status: null, search: null, branchName: 'Argentina' },
  options: { branches: [{ id: 10, code: 'AR', name: 'Argentina' }] },
  summary: {
    en_curso: 1,
    planificados: 1,
    finalizados: 1,
    cancelados: 1,
    active_total: 2,
    total: 3,
    km: 600,
    km_trips: 1,
    km_coverage: 100,
    liters: 400,
    fuel_trips: 2,
    liters_per_100: 50.5,
    fuel_cost_ars: 400000,
    expenses_ars: 150000,
    billing_ars: 1400000,
  },
  statusBreakdown: [
    { key: 'EN_CURSO', label: 'En curso', value: 1, tone: 'success' },
    { key: 'PLANIFICADO', label: 'Planificado', value: 1, tone: 'info' },
    { key: 'FINALIZADO', label: 'Finalizado', value: 1, tone: 'neutral' },
    { key: 'CANCELADO', label: 'Cancelado', value: 1, tone: 'danger' },
  ],
  monthly: [{ month: '2026-10', trips: 3, km: 600, liters: 400, billing_ars: 1400000 }],
  branches: [{ id: 10, code: 'AR', name: 'Argentina', trips: 3, active: 2, km: 600 }],
  currentTrips: [{
    id: 11,
    numero: 7,
    estado: 'EN_CURSO',
    estado_label: 'En curso',
    sucursal: 'Argentina',
    fecha_salida: '2026-10-01 08:00:00',
    fecha_salida_planificada: '2026-10-01 08:00:00',
    fecha_regreso_planificada: '2026-10-03 18:00:00',
    tractor: 'AA111AA · T01',
    choferes: ['Juan Pérez'],
    origen: 'Rosario',
    destino: 'Córdoba',
    paradas: 2,
    url: '/mantenimiento/viajes/operativos/11',
  }],
})

describe('tablero de viajes operativos', () => {
  it('muestra los viajes actuales con indicadores y gráficos del período', () => {
    const wrapper = mount(TripOperationsDashboardPage, { props: { data: data() } })

    expect(wrapper.text()).toContain('Tablero de viajes operativos')
    expect(wrapper.text()).toContain('En curso')
    expect(wrapper.text()).toContain('Planificados')
    expect(wrapper.text()).toContain('Km recorridos')
    expect(wrapper.text()).toContain('50,5 L/100 km')
    expect(wrapper.text()).toContain('1.400.000')
    expect(wrapper.text()).toContain('#7')
    expect(wrapper.text()).toContain('Rosario → Córdoba')
    expect(wrapper.text()).toContain('AA111AA · T01')
    expect(wrapper.text()).toContain('Juan Pérez')
    expect(wrapper.text()).toContain('Estado de los viajes')
    expect(wrapper.text()).toContain('Últimos 6 meses con actividad')
    expect(wrapper.text()).toContain('Viajes por sucursal')
    expect(wrapper.text()).toContain('Económico del período')
    expect(wrapper.get('form').attributes('method')).toBe('get')
    expect(wrapper.get('a[href="/mantenimiento/viajes/operativos/11"]').text()).toContain('Ver viaje')
    expect(wrapper.findAll('[data-current-trip]')).toHaveLength(1)
    expect(wrapper.findAll('[data-status-row]')).toHaveLength(4)
    expect(wrapper.findAll('[data-month-row]')).toHaveLength(1)
    expect(wrapper.findAll('[data-branch-row]')).toHaveLength(1)
    wrapper.unmount()
  })

  it('oculta el económico para usuarios sin permiso de gerencia', () => {
    const payload = data()
    payload.canSeeEconomics = false
    payload.summary = { ...payload.summary, billing_ars: null, fuel_cost_ars: null, expenses_ars: null }
    const wrapper = mount(TripOperationsDashboardPage, { props: { data: payload } })

    expect(wrapper.text()).toContain('Combustible')
    expect(wrapper.text()).toContain('Litros cargados en el período')
    expect(wrapper.text()).not.toContain('Económico del período')
    expect(wrapper.text()).not.toContain('1.400.000')
    expect(wrapper.text()).not.toContain('Resultado directo')
    wrapper.unmount()
  })

  it('informa sin datos suficientes cuando no hay consumo calculable', () => {
    const payload = data()
    payload.summary = { ...payload.summary, liters_per_100: null }
    const wrapper = mount(TripOperationsDashboardPage, { props: { data: payload } })

    expect(wrapper.text()).toContain('Sin datos suficientes')
    wrapper.unmount()
  })

  it('muestra el estado vacío y oculta el alta cuando no puede operar', () => {
    const payload = data()
    payload.canOperate = false
    payload.summary = {
      ...payload.summary,
      en_curso: 0,
      planificados: 0,
      finalizados: 0,
      cancelados: 0,
      active_total: 0,
      total: 0,
    }
    payload.currentTrips = []
    payload.monthly = []
    payload.branches = []
    payload.statusBreakdown = payload.statusBreakdown.map((item) => ({ ...item, value: 0 }))
    const wrapper = mount(TripOperationsDashboardPage, { props: { data: payload } })

    expect(wrapper.text()).toContain('Todavía no hay viajes operativos')
    expect(wrapper.find('table').exists()).toBe(false)
    expect(wrapper.find('a[href="/mantenimiento/viajes/operativos/nuevo"]').exists()).toBe(false)
    wrapper.unmount()
  })
})
