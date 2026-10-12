import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import App from './App.vue'
import { normalizeDashboardPayload } from './adapters/dashboardPayload.js'

const dashboard = normalizeDashboardPayload({
  mode: 'tenant',
  user: { name: 'María Responsable', roles: ['Responsable de mantenimiento'] },
  company: { name: 'Empresa Demo', branches: [{ id: 1, name: 'Central' }] },
  navigation: [{ key: 'dashboard', label: 'Dashboard', href: '/dashboard', active: true }],
  moduleNavigation: [
    { key: 'platform-home', label: 'Inicio', href: '/inicio' },
    { key: 'module-maintenance', label: 'Mantenimiento', href: '/dashboard', active: true, status: 'Operativo' },
    { key: 'module-trips', label: 'Viajes', disabled: true, status: 'Sin implementación aún' },
  ],
  charts: {
    preventiveByState: [
      { status: 'AL_DIA', label: 'Al día', count: 4 },
      { status: 'PROXIMO', label: 'Próximos', count: 2 },
      { status: 'VENCIDO', label: 'Vencidos', count: 1 },
      { status: 'SIN_DATOS', label: 'Sin datos', count: 0 },
    ],
    openOrdersByState: [
      { status: 'EN_PROCESO', label: 'En proceso', count: 2 },
      { status: 'ESPERA_REPUESTOS', label: 'En espera de repuestos', count: 1 },
    ],
  },
  metrics: {},
  links: {},
})

describe('operational maintenance dashboard', () => {
  it('shows real preventive and open-order state charts for maintenance staff', () => {
    const wrapper = mount(App, {
      props: { dashboard },
      global: {
        stubs: {
          ApplicationShell: { template: '<div><slot /></div>' },
          MetricCard: true,
        },
      },
    })

    expect(wrapper.text()).toContain('Estado del mantenimiento preventivo')
    expect(wrapper.text()).toContain('Órdenes abiertas por estado')
    expect(wrapper.text()).toContain('Al día')
    expect(wrapper.text()).toContain('En espera de repuestos')
    expect(wrapper.text()).toContain('4')
  })
})
