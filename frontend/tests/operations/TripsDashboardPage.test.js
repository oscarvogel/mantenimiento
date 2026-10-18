import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import TripsDashboardPage from '../../src/pages/operations/TripsDashboardPage.vue'

const data = {
  filters: { from: '', to: '', branchId: null, domain: null, branchName: null },
  options: { branches: [{ id: 10, code: 'AR', name: 'Argentina' }], domains: ['AC 532 DD'] },
  summary: {
    trips: 1740, km: 8000000, km_trips: 1620, km_coverage: 93.1,
    liters: 4200000, fuel_trips: 1611, fuel_coverage: 92.6,
    liters_per_100: 52.5, missing_readings: 90, reversed: 8, outliers: 22,
  },
  monthly: [
    { month: '2022-01', trips: 12, km: 52000, liters: 24000 },
    { month: '2022-02', trips: 10, km: 48000, liters: 22000 },
  ],
  domains: [
    { domain: 'AC 532 DD', trips: 109, km: 520000, km_trips: 105, liters: 180000, fuel_trips: 100, liters_per_100: 34.6, coverage: 96.3 },
  ],
  quality: [
    { key: 'km', label: 'Viajes con km confiable', value: 1620, total: 1740, tone: 'success' },
    { key: 'missing', label: 'Sin lecturas de odometro', value: 90, total: 1740, tone: 'warning' },
  ],
}

describe('dashboard de viajes', () => {
  it('muestra indicadores, actividad por mes y tabla por dominio', () => {
    const wrapper = mount(TripsDashboardPage, { props: { data } })

    expect(wrapper.text()).toContain('1.740')
    expect(wrapper.text()).toContain('52,5')
    expect(wrapper.text()).toContain('93,1%')
    expect(wrapper.text()).toContain('AC 532 DD')
    expect(wrapper.text()).toContain('34,6')
    expect(wrapper.get('form').attributes('method')).toBe('get')
    expect(wrapper.text()).toContain('Viajes con km confiable')
    wrapper.unmount()
  })

  it('informa sin datos suficientes cuando no hay consumo', () => {
    const wrapper = mount(TripsDashboardPage, {
      props: { data: { ...data, summary: { ...data.summary, liters_per_100: null } } },
    })

    expect(wrapper.text()).toContain('Sin datos suficientes')
    wrapper.unmount()
  })

  it('muestra el estado vacío cuando no hay viajes', () => {
    const wrapper = mount(TripsDashboardPage, {
      props: { data: { ...data, summary: { ...data.summary, trips: 0 }, monthly: [], domains: [] } },
    })

    expect(wrapper.text()).toContain('No hay viajes para los filtros indicados')
    expect(wrapper.find('table').exists()).toBe(false)
    wrapper.unmount()
  })
})
