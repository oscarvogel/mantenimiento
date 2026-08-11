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
  quality: {
    completedOrders: 2,
    validDowntimeSamples: 1,
    invalidDowntimeSamples: 1,
    correctiveMttrSamples: 0,
    limitations: ['MTTR solo incluye correctivos válidos.'],
  },
  orders: {
    items: [{ id: 1, number: 'OT-001', equipmentCode: 'SCANIA-01', branchName: 'Central', openedAt: '2026-08-01 08:00:00', completedAt: '2026-08-02 10:00:00', status: 'FINALIZADA', origin: 'CORRECTIVO', priority: 'MEDIA', cost: '150000.50' }],
    pagination: { page: 1, perPage: 10, total: 1, totalPages: 1 },
  },
}

const mountPage = (overrides = {}) => {
  const wrapper = mount(ReportsPage, {
    props: {
      data: { ...data, ...overrides },
      urls: { index: '/reportes', export: '/reportes/exportar?desde=2026-08-01' },
    },
  })
  wrappers.push(wrapper)
  return wrapper
}

afterEach(() => wrappers.splice(0).forEach((wrapper) => wrapper.unmount()))

describe('ReportsPage', () => {
  it('renders scoped metrics, semantic bars and server filters', () => {
    const wrapper = mountPage()

    expect(wrapper.get('h1').text()).toContain('Reportes')
    expect(wrapper.text()).toContain('$ 150.000,50')
    expect(wrapper.text()).toContain('Sin datos suficientes')
    expect(wrapper.findAll('[role="meter"]')).toHaveLength(4)
    expect(wrapper.get('form').attributes('action')).toBe('/reportes')
    expect(wrapper.get('select[name="sucursal_id"]').element.value).toBe('9')
    expect(wrapper.get('select[name="per_page"]').element.value).toBe('10')
    expect(wrapper.findAll('select[name="per_page"] option').map((option) => option.text())).toEqual(['5', '10', '25'])
    expect(wrapper.get('a[href^="/reportes/exportar"]').text()).toContain('Exportar')
  })

  it('shows a truthful empty state when no orders exist', () => {
    const wrapper = mountPage({
      statusDistribution: [],
      costsByEquipment: [],
      evolution: [],
      orders: { items: [], pagination: { page: 1, perPage: 10, total: 0, totalPages: 1 } },
    })

    expect(wrapper.text()).toContain('No hay órdenes con apertura dentro del período')
    expect(wrapper.findAll('[role="status"]').length).toBeGreaterThanOrEqual(4)
  })
})
