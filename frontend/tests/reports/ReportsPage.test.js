import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ReportsPage from '../../src/pages/reports/ReportsPage.vue'

const wrappers = []
const data = {
  branches: [{ id: 9, codigo: 'CEN', nombre: 'Central' }],
  filters: { branchId: 9, from: '2026-08-01', to: '2026-08-08', perPage: 10 },
  metrics: {
    totalCost: { available: true, value: '150000.50', sampleSize: 2 },
    openOrders: { available: true, value: 1, sampleSize: 1 },
    completedOrders: { available: true, value: 2, sampleSize: 2 },
    downtimeHours: { available: true, value: '3.0', sampleSize: 1 },
    mttrHours: { available: false, value: '0.0', sampleSize: 0 },
  },
  statusDistribution: [{ status: 'FINALIZADA', count: 2 }, { status: 'EN_PROCESO', count: 1 }],
  costsByEquipment: [{ equipmentId: 10, equipmentCode: 'SCANIA-01', cost: '150000.50', orders: 2 }],
  evolution: [{ period: '2026-08-02', orders: 2, cost: '150000.50' }],
  evolutionGranularity: 'day',
  quality: { completedOrders: 2, validDowntimeSamples: 1, invalidDowntimeSamples: 1, correctiveMttrSamples: 0, limitations: ['MTTR solo incluye correctivos válidos.'] },
  orders: {
    items: [{ id: 1, number: 'OT-001', equipmentCode: 'SCANIA-01', branchName: 'Central', openedAt: '2026-08-01 08:00:00', completedAt: '2026-08-02 10:00:00', status: 'FINALIZADA', origin: 'CORRECTIVO', priority: 'MEDIA', cost: '150000.50' }],
    pagination: { page: 1, perPage: 10, total: 1, totalPages: 1 },
  },
}

const catalog = [
  { key: 'maintenance', label: 'Reportes de mantenimiento', description: 'Órdenes abiertas actualmente y del período, con exportación CSV.', url: '/reportes', state: 'available' },
  { key: 'trips-profitability', label: 'Rentabilidad de viajes', description: 'Ingresos y márgenes del historial aceptado.', url: '/viajes/rentabilidad', state: 'available' },
  { key: 'combined-cost', label: 'Costo por equipo y período', description: 'Combina combustible, gastos y mantenimiento.', url: null, state: 'not_implemented' },
]
const mountPage = (overrides = {}) => {
  const wrapper = mount(ReportsPage, {
    props: {
      data: { ...data, ...overrides },
      urls: { index: '/reportes', export: '/reportes/exportar?desde=2026-08-01' },
      catalog,
      portalUrl: '/inicio',
    },
  })
  wrappers.push(wrapper)
  return wrapper
}
const openTab = async (wrapper, label) => wrapper.findAll('[role="tab"]').find((tab) => tab.text() === label).trigger('click')

afterEach(() => wrappers.splice(0).forEach((wrapper) => wrapper.unmount()))

describe('ReportsPage', () => {
  it('renders scoped metrics, compact server filters and report tabs', async () => {
    const wrapper = mountPage()

    expect(wrapper.get('h1').text()).toContain('Reportes')
    expect(wrapper.text()).toContain('$ 150.000,50')
    expect(wrapper.text()).toContain('Sin datos suficientes')
    expect(wrapper.findAll('[role="tab"]').map((tab) => tab.text())).toEqual(['Resumen', 'Costos', 'Calidad', 'Órdenes'])
    expect(wrapper.get('details').attributes('open')).toBeUndefined()
    expect(wrapper.findAll('[role="meter"]')).toHaveLength(2)
    expect(wrapper.get('form').attributes('action')).toBe('/reportes')
    expect(wrapper.get('select[name="sucursal_id"]').element.value).toBe('9')
    expect(wrapper.get('select[name="per_page"]').element.value).toBe('10')
    expect(wrapper.findAll('select[name="per_page"] option').map((option) => option.text())).toEqual(['5', '10', '25'])
    expect(wrapper.get('a[href^="/reportes/exportar"]').text()).toContain('Exportar')
    expect(wrapper.text()).toContain('Abiertas actualmente')
    expect(wrapper.text()).toContain('Abiertas en el período')
    expect(wrapper.text()).toContain('Finalizadas en el período')
    expect(wrapper.text()).toContain('no descuenta esperas')

    await openTab(wrapper, 'Costos')
    expect(wrapper.findAll('[role="meter"]')).toHaveLength(2)
    expect(wrapper.text()).toContain('Evolución de costos')
  })

  it('offers only consultable reports and returns to the portal', () => {
    const wrapper = mountPage()

    expect(wrapper.get('a[href="/inicio"]').text()).toContain('Volver al inicio')
    expect(wrapper.get('a[href="/reportes"]').text()).toContain('Reportes de mantenimiento')
    expect(wrapper.get('a[href="/viajes/rentabilidad"]').text()).toContain('Rentabilidad de viajes')
    expect(wrapper.text()).toContain('Costo por equipo y período')
    expect(wrapper.text()).toContain('Pendiente')
    expect(wrapper.find('a[href="/automatizaciones"]').exists()).toBe(false)
  })

  it('shows a restricted catalog without inventing report links', () => {
    const wrapper = mount(ReportsPage, {
      props: {
        data,
        urls: { index: '/reportes', export: '/reportes/exportar?desde=2026-08-01' },
        catalog: [{ key: 'maintenance', label: 'Reportes de mantenimiento', description: 'Requiere permiso.', url: null, state: 'restricted' }],
        portalUrl: '/inicio',
      },
    })
    wrappers.push(wrapper)

    expect(wrapper.text()).toContain('Requiere permiso')
    expect(wrapper.findAll('a[href^="/reportes"]').length).toBeGreaterThan(0)
    expect(wrapper.find('a[href="/viajes/rentabilidad"]').exists()).toBe(false)
  })

  it('shows a truthful empty state when no orders exist', async () => {
    const wrapper = mountPage({
      statusDistribution: [], costsByEquipment: [], evolution: [],
      orders: { items: [], pagination: { page: 1, perPage: 10, total: 0, totalPages: 1 } },
    })

    await openTab(wrapper, 'Órdenes')
    expect(wrapper.text()).toContain('No hay órdenes con apertura dentro del período')
    expect(wrapper.findAll('[role="status"]')).toHaveLength(1)
  })
})
