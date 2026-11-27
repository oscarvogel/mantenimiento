import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import MetricCard from './MetricCard.vue'

const props = { label: 'Preventivos vencidos', value: 3, tone: 'overdue', href: '/planes?estado=VENCIDO', linkLabel: 'Atender' }

describe('MetricCard', () => {
  it('destacada: tarjeta oscura con el texto claro, como la del inicio', () => {
    const card = mount(MetricCard, { props: { ...props, emphasis: true } })
    expect(card.classes()).toEqual(expect.arrayContaining(['bg-surface-inverse', 'text-ink-inverse', 'rounded-card']))
    expect(card.get('a').attributes('href')).toBe('/planes?estado=VENCIDO')
    expect(card.text()).toContain('Atender')
  })

  it('normal: tarjeta clara sin borde', () => {
    const card = mount(MetricCard, { props })
    expect(card.classes()).toEqual(expect.arrayContaining(['bg-surface-raised', 'shadow-card', 'rounded-card']))
    expect(card.classes()).not.toContain('border')
  })
})
