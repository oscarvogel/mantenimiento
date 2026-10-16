import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ClientDashboard from '../../../src/profiles/cliente_piloto/pages/ClientDashboard.vue'

const dashboard = {
  available: true,
  user: { name: 'Ana Pérez' },
  company: { name: 'Empresa demo' },
  navigation: [],
  notifications: { enabled: false, summaryUrl: '#', centerUrl: '#' },
  homeUrl: '/inicio',
  moduleNavigation: [],
  logout: null,
  metrics: {
    preventiveCompliance: 87,
    maintenanceOverdue: 2,
    maintenanceDueSoon: 4,
    openOrders: 3,
    maintenanceMissingData: 1,
    equipmentWithoutReading: 5,
    equipmentWithStaleReading: 2,
  },
  metricAvailability: {
    preventiveCompliance: true,
    maintenanceOverdue: true,
    maintenanceDueSoon: true,
    openOrders: true,
    maintenanceMissingData: true,
    equipmentWithoutReading: true,
    equipmentWithStaleReading: true,
  },
  links: {
    maintenanceOverdue: '/mantenimiento/planes?estado=VENCIDO',
    maintenanceDueSoon: '/mantenimiento/planes?estado=PROXIMO',
    orders: '/mantenimiento/ordenes',
    quickReadings: '/mantenimiento/lecturas/rapidas',
  },
}

const mountDashboard = (value) => mount(ClientDashboard, {
  props: { dashboard: value },
  global: { stubs: { ApplicationShell: { template: '<div data-testid="client-shell"><slot /></div>' } } },
})

describe('cliente_piloto maintenance dashboard', () => {
  it('shows the approved provisional KPI order and server-provided actions', () => {
    const wrapper = mountDashboard(dashboard)

    expect(wrapper.findAll('.client-pilot-profile__metric-card').map((item) => item.attributes('data-kpi')))
      .toEqual(['maintenanceOverdue', 'maintenanceDueSoon', 'preventiveCompliance', 'openOrders'])
    expect(wrapper.findAll('.client-pilot-profile__attention-card').map((item) => item.attributes('data-kpi')))
      .toEqual(['maintenanceMissingData', 'equipmentWithoutReading', 'equipmentWithStaleReading'])
    expect(wrapper.text()).toContain('87%')
    expect(wrapper.get('a[href="/mantenimiento/planes?estado=VENCIDO"]').text()).toContain('Revisar vencidos')
    expect(wrapper.get('a[href="/mantenimiento/ordenes"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Prototipo de revisión')
  })

  it('uses an honest unavailable state when a KPI was not sent by the backend', () => {
    const wrapper = mountDashboard({
      ...dashboard,
      metricAvailability: { ...dashboard.metricAvailability, maintenanceOverdue: false },
    })
    const overdue = wrapper.get('[data-kpi="maintenanceOverdue"]')

    expect(overdue.text()).toContain('Sin datos suficientes')
    expect(overdue.text()).not.toContain('2')
  })
})
