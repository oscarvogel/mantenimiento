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
})
