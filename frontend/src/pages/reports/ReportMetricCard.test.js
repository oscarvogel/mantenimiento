import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ReportMetricCard from './ReportMetricCard.vue'

const metric = { available: true, value: 1200, displayValue: '$ 1.200', formatter: (value) => `$ ${value}` }

describe('ReportMetricCard', () => {
  it('la principal va en la tarjeta oscura; las demás, claras sin borde', () => {
    expect(mount(ReportMetricCard, { props: { label: 'Costo total', metric, emphasis: true } }).classes()).toContain('ui-card-emphasis')
    const card = mount(ReportMetricCard, { props: { label: 'Finalizadas', metric, tone: 'success' } })
    expect(card.classes()).toEqual(expect.arrayContaining(['bg-surface-raised', 'shadow-card', 'rounded-card']))
    expect(card.classes()).not.toContain('border')
  })

  it('sin datos dice que no alcanzan, no inventa un número', () => {
    expect(mount(ReportMetricCard, { props: { label: 'MTTR', metric: { available: false } } }).text()).toContain('Sin datos suficientes')
  })
})
