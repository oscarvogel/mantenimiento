import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ManagerialDashboard from '../src/components/ManagerialDashboard.vue'
import { normalizeDashboardPayload } from '../src/adapters/dashboardPayload.js'

const wrappers = []

afterEach(() => wrappers.splice(0).forEach((wrapper) => wrapper.unmount()))

describe('ManagerialDashboard', () => {
  it('shows missing data instead of a fabricated preventive compliance percentage', () => {
    const wrapper = mount(ManagerialDashboard, {
      props: {
        dashboard: normalizeDashboardPayload({
          view: 'managerial',
          user: { name: 'Admin Demo' },
          company: { name: 'TSA Demo Dashboard' },
          metrics: { preventiveCompliance: null },
          links: {},
        }),
        firstName: 'Admin',
      },
    })
    wrappers.push(wrapper)

    const complianceCard = wrapper.findAll('article').find((card) => card.text().includes('Cumplimiento'))

    expect(complianceCard).toBeDefined()
    expect(complianceCard.text()).toContain('Sin datos')
    expect(complianceCard.text()).not.toContain('100%')
  })

  it('shows monthly financial KPIs and top equipment costs', () => {
    const wrapper = mount(ManagerialDashboard, {
      props: {
        dashboard: normalizeDashboardPayload({
          view: 'managerial',
          user: { name: 'Admin Demo' },
          company: { name: 'TSA Demo Dashboard' },
          metrics: {},
          financial: {
            periodLabel: 'Sep 2026',
            currentMonthArs: 4850000,
            previousMonthArs: 4300000,
            variationPercentage: 12.8,
            preventiveArs: 2100000,
            correctiveArs: 2750000,
            averagePerEquipmentArs: 194000,
            equipmentWithCost: 25,
            history: [
              { month: '2026-08', label: 'Ago', totalArs: 4300000 },
              { month: '2026-09', label: 'Sep', totalArs: 4850000 },
            ],
            topEquipment: [
              { equipmentId: 4, equipmentCode: 'CAM-04', totalArs: 980000 },
            ],
          },
          links: { financialDetail: '/reportes', equipment: '/mantenimiento/equipos' },
        }),
        firstName: 'Admin',
      },
    })
    wrappers.push(wrapper)

    expect(wrapper.text()).toContain('Resumen financiero del mes')
    expect(wrapper.text()).toContain('$ 4.850.000')
    expect(wrapper.text()).toContain('+12,8% vs. mes anterior')
    expect(wrapper.text()).toContain('CAM-04')
    expect(wrapper.text()).toContain('$ 980.000')
  })

  it('shows reading-quality bars as percentages of the active fleet', () => {
    const wrapper = mount(ManagerialDashboard, {
      props: {
        dashboard: normalizeDashboardPayload({
          view: 'managerial',
          user: { name: 'Admin Demo' },
          company: { name: 'TSA Demo Dashboard' },
          metrics: {
            equipmentActive: 25,
            equipmentWithStaleReading: 9,
            equipmentWithoutReading: 8,
          },
          links: {},
        }),
        firstName: 'Admin',
      },
    })
    wrappers.push(wrapper)

    const updated = wrapper.get('[data-testid="reading-quality-updated"]')
    const stale = wrapper.get('[data-testid="reading-quality-stale"]')
    const missing = wrapper.get('[data-testid="reading-quality-missing"]')

    expect(updated.attributes('style')).toContain('height: 36px')
    expect(stale.attributes('style')).toContain('height: 40px')
    expect(missing.attributes('style')).toContain('height: 36px')
    expect(updated.element.parentElement.parentElement.textContent).toContain('32%')
    expect(stale.element.parentElement.parentElement.textContent).toContain('36%')
    expect(missing.element.parentElement.parentElement.textContent).toContain('32%')
    expect(wrapper.text()).toContain('Cobertura actual: 32%')
  })
})
