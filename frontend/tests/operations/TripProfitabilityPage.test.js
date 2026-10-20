import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import TripProfitabilityPage from '../../src/pages/operations/TripProfitabilityPage.vue'

const data = {
  links: { index: '/mantenimiento/viajes/rentabilidad', history: '/mantenimiento/viajes' },
  filters: { from: '', to: '', branchId: null, domain: null, quality: null, sort: 'margen_desc', branchName: null },
  options: {
    branches: [{ id: 10, code: 'AR', name: 'Argentina' }],
    domains: ['AC 532 DD'],
    qualities: [{ value: 'COMPLETO', label: 'Completo' }, { value: 'SIN_FACTURA', label: 'Sin factura vinculada' }],
  },
  summary: {
    trips_total: 2, trips_analyzable: 1, trips_complete: 1, trips_with_invoice: 1,
    revenue: 1000000, fuel: 300000, expenses: 50000, other: 100000, driver_cost: 70000,
    direct_cost: 520000, margin: 480000, margin_pct: 48, invoice_coverage: 50,
  },
  trips: [
    { id: 1, numero_viaje: 10, dominio: 'AC 532 DD', fecha_salida: '2026-10-20', revenue: 1000000, invoice_count: 1, fuel: 300000, expenses: 50000, other: 100000, driver_cost: 70000, direct_cost: 520000, margin: 480000, margin_pct: 48, analyzable: true, quality: 'COMPLETO', quality_label: 'Completo' },
    { id: 2, numero_viaje: 20, dominio: 'AC 532 DD', fecha_salida: '2026-10-23', revenue: 0, invoice_count: 0, fuel: 0, expenses: 0, other: 0, driver_cost: null, direct_cost: 0, margin: null, margin_pct: null, analyzable: false, quality: 'SIN_FACTURA', quality_label: 'Sin factura vinculada' },
  ],
  pagination: { page: 1, pages: 1, total: 2, perPage: 25 },
}

describe('rentabilidad de viajes', () => {
  it('muestra margen, costos y calidad del dato', () => {
    const wrapper = mount(TripProfitabilityPage, { props: { data } })

    expect(wrapper.text()).toContain('Rentabilidad de viajes')
    expect(wrapper.text()).toContain('1.000.000')
    expect(wrapper.text()).toContain('520.000')
    expect(wrapper.text()).toContain('480.000')
    expect(wrapper.text()).toContain('48%')
    expect(wrapper.text()).toContain('Completo')
    expect(wrapper.text()).toContain('Sin factura vinculada')
    expect(wrapper.text()).toContain('Sin datos')
    expect(wrapper.get('form').attributes('method')).toBe('get')
    expect(wrapper.get('a[href="/mantenimiento/viajes/1"]').text()).toContain('Ver viaje')
    wrapper.unmount()
  })

  it('muestra estado vacío sin viajes', () => {
    const wrapper = mount(TripProfitabilityPage, { props: { data: { ...data, trips: [], pagination: { page: 1, pages: 1, total: 0 } } } })

    expect(wrapper.text()).toContain('No hay viajes para los filtros indicados')
    wrapper.unmount()
  })
})
