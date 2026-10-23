import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import TripInvoiceReviewPage from '../../src/pages/admin/TripInvoiceReviewPage.vue'

const data = {
  import: { id: 9, company: 'TSA', estado: 'CONFIRMADO' },
  status: null,
  statuses: [
    { value: '', label: 'Todas' },
    { value: 'SIN_VINCULO', label: 'Sin vínculo' },
  ],
  csrf: { name: 'csrf_test_name', hash: 'abc123' },
  actions: {
    review: '/superadmin/viajes/importaciones/9/facturas',
    search: '/superadmin/viajes/importaciones/9/facturas/buscar',
    import: '/superadmin/viajes/importaciones/9',
    superadmin: '/superadmin',
  },
  items: [
    {
      id: 7, clave_legacy: 900, fecha: '2026-10-20', tipo: 'FAC-A', total: 1000, total_ars: 1000,
      moneda: 'PES', unit: 'AC532DD', origin: 'ROSARIO', destination: 'CORDOBA', remitos: [], link: null,
      candidates: [{ trip_id: 42, trip_number: 10, trip_domain: 'AC 532 DD', trip_date: '2026-10-20', score: 100, confidence: 'ALTA' }],
    },
    {
      id: 8, clave_legacy: 901, fecha: '2026-10-21', tipo: 'FAC-A', total: 2000, total_ars: 2000,
      moneda: 'PES', unit: 'AC532DD', origin: 'ROSARIO', destination: 'CORDOBA', remitos: [], candidates: [],
      link: { estado: 'CONFIRMADO', confianza: 'MANUAL', trip_id: 43, trip_number: 11, trip_domain: 'AC 532 DD', trip_date: '2026-10-21' },
    },
  ],
  pagination: { page: 1, pages: 1, total: 2, perPage: 20 },
}

describe('revisión de facturas de viajes', () => {
  it('muestra candidatos, vínculo actual y formularios CSRF', () => {
    const wrapper = mount(TripInvoiceReviewPage, { props: { data } })

    expect(wrapper.text()).toContain('Factura #900')
    expect(wrapper.text()).toContain('Viaje #10')
    expect(wrapper.get('form[action="/superadmin/viajes/importaciones/9/facturas/7/viaje"] input[name="viaje_id"]').attributes('value')).toBe('42')
    expect(wrapper.text()).toContain('Vincular')
    expect(wrapper.text()).toContain('Factura #901')
    expect(wrapper.text()).toContain('Viaje #11')
    expect(wrapper.text()).toContain('Quitar vínculo')
    expect(wrapper.get('form[action="/superadmin/viajes/importaciones/9/facturas/7/viaje"] input[name="csrf_test_name"]').attributes('value')).toBe('abc123')
    expect(wrapper.get('form[action="/superadmin/viajes/importaciones/9/facturas/8/desvincular"]').exists()).toBe(true)
    expect(wrapper.get('form').attributes('method')).toBe('get')
    wrapper.unmount()
  })
})
