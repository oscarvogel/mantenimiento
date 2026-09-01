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

  it('scales reading-quality bars proportionally without overflowing the chart', () => {
    const wrapper = mount(ManagerialDashboard, {
      props: {
        dashboard: normalizeDashboardPayload({
          view: 'managerial',
          user: { name: 'Admin Demo' },
          company: { name: 'TSA Demo Dashboard' },
          metrics: {
            equipmentActive: 25,
            equipmentWithStaleReading: 8,
            equipmentWithoutReading: 17,
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

    expect(updated.attributes('style')).toContain('height: 0px')
    expect(stale.attributes('style')).toContain('height: 53px')
    expect(missing.attributes('style')).toContain('height: 112px')
    expect(wrapper.text()).toContain('Cobertura actual: 0%')
  })
})
