import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import TripDetailPage from '../../src/pages/operations/TripDetailPage.vue'

describe('detalle de viaje', () => {
  it('muestra datos generales y registros importados relacionados en solo lectura', () => {
    const wrapper = mount(TripDetailPage, {
      props: {
        data: {
          backUrl: '/mantenimiento/viajes?desde=2026-09-01&pagina=2',
          trip: { numero_viaje: 42, dominio_origen: 'AC 532 DD', fecha_salida: '2026-09-01', km_salida: 1000, km_regreso: 1500, legajo_chofer_origen: 'CHO-8', sucursal_id: 3 },
          segments: [{ item: 1, descripcion_origen: 'Posadas', descripcion_destino: 'Iguazú', fecha_salida: '2026-09-01', importe: '120.50' }],
          fuel: [{ item: 1, fecha: '2026-09-02', litros: '75.00', importe: '90.00' }],
          expenses: [{ item: 1, codigo_concepto: 'PEAJE', importe: '10.00' }],
          accounts: [], accountSegments: [], otherEarnings: [],
        },
      },
    })

    expect(wrapper.text()).toContain('Viaje 42')
    expect(wrapper.text()).toContain('AC 532 DD')
    expect(wrapper.text()).toContain('01/09/2026')
    expect(wrapper.text()).toContain('Posadas')
    expect(wrapper.text()).toContain('Iguazú')
    expect(wrapper.text()).toContain('75.00')
    expect(wrapper.text()).toContain('Gastos')
    expect(wrapper.get('a[href="/mantenimiento/viajes?desde=2026-09-01&pagina=2"]').text()).toContain('Volver al dashboard')
    expect(wrapper.find('input').exists()).toBe(false)
    wrapper.unmount()
  })
})
