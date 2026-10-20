import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import TripOperationsDetailPage from '../../src/pages/operations/TripOperationsDetailPage.vue'

const data = {
  backUrl: '/mantenimiento/viajes/operativos',
  links: { detail: '/mantenimiento/viajes/operativos/42' },
  csrf: { name: 'csrf_test_name', hash: 'abc123' },
  canOperate: true,
  canStart: true,
  canCancel: true,
  trip: {
    id: 42, numero: 7, estado: 'PLANIFICADO', estado_label: 'Planificado', sucursal: 'Argentina',
    fecha_salida_planificada: '2026-10-20 08:00:00', fecha_regreso_planificada: '2026-10-21 18:00:00',
    fecha_salida_real: null, km_salida: null, observaciones: 'Carga programada', motivo_cancelacion: null,
  },
  assignments: [
    { rol: 'TRACTOR', rol_label: 'Tractor / camión', label: 'AA111AA · T01', desde: '2026-10-20 08:00:00', hasta: '2026-10-21 18:00:00' },
    { rol: 'CHOFER', rol_label: 'Chofer', label: 'Pérez, Juan', desde: '2026-10-20 08:00:00', hasta: '2026-10-21 18:00:00' },
  ],
  stops: [
    { orden: 1, tipo: 'CARGA', tipo_label: 'Carga', ubicacion: 'Rosario', cliente: 'Cliente Sur', domicilio: 'Planta · Ruta 9', fecha_estimada: '2026-10-20 08:30:00', descripcion_carga: 'Soja', cantidad: 30, unidad: 'Tonelada tn', observaciones: null },
    { orden: 2, tipo: 'DESCARGA', tipo_label: 'Descarga', ubicacion: 'Córdoba', cliente: null, domicilio: null, fecha_estimada: null, descripcion_carga: null, cantidad: null, unidad: null, observaciones: null },
  ],
}

describe('detalle de viaje operativo', () => {
  it('muestra planificación, equipo, choferes y recorrido', () => {
    const wrapper = mount(TripOperationsDetailPage, { props: { data } })

    expect(wrapper.text()).toContain('Viaje #7')
    expect(wrapper.text()).toContain('Planificado')
    expect(wrapper.text()).toContain('Tractor / camión')
    expect(wrapper.text()).toContain('AA111AA · T01')
    expect(wrapper.text()).toContain('Pérez, Juan')
    expect(wrapper.text()).toContain('Rosario')
    expect(wrapper.text()).toContain('Córdoba')
    expect(wrapper.text()).toContain('Soja')
    wrapper.unmount()
  })

  it('ofrece iniciar y cancelar mientras el viaje está planificado', () => {
    const wrapper = mount(TripOperationsDetailPage, { props: { data } })

    const forms = wrapper.findAll('form')
    expect(forms.map((form) => form.attributes('action'))).toEqual([
      '/mantenimiento/viajes/operativos/42/iniciar',
      '/mantenimiento/viajes/operativos/42/cancelar',
    ])
    wrapper.unmount()
  })

  it('muestra el resumen y los formularios de combustible, gastos y caja', () => {
    const wrapper = mount(TripOperationsDetailPage, {
      props: {
        data: {
          ...data,
          canRegisterCosts: true,
          links: { ...data.links, fuel: '/mantenimiento/viajes/operativos/42/combustible', expense: '/mantenimiento/viajes/operativos/42/gastos', cash: '/mantenimiento/viajes/operativos/42/caja' },
          totals: { fuel_liters: 100, fuel_amount_ars: 50000, expense_amount_ars: 20000, direct_cost_ars: 70000, cash_delivered_ars: 100000, cash_returned_ars: 0, cash_expenses_ars: 20000, cash_balance_ars: 80000 },
          consumption: { km: 500, liters_per_100: 20, source: 'ODOMETRO_VIAJE' },
          fuel: [{ id: 1, fecha: '2026-10-20 10:00:00', litros: 100, importe: 50000, moneda: 'ARS', importe_ars: 50000, kilometraje: 145000, estacion: 'YPF', comprobante: 'A-1', observaciones: null }],
          expenses: [{ id: 1, fecha: '2026-10-20 11:00:00', importe: 20000, moneda: 'ARS', importe_ars: 20000, categoria: 'Peaje', medio_pago: 'Efectivo', pagado_de_caja: true, comprobante: 'T-1', observaciones: null }],
          cashMovements: [{ id: 1, tipo: 'ANTICIPO', fecha: '2026-10-20 07:00:00', importe: 100000, moneda: 'ARS', importe_ars: 100000, observaciones: null }],
          options: { expenseCategories: [{ id: 1, label: 'Peaje' }], paymentMethods: [{ id: 1, label: 'Efectivo' }], currencies: [{ code: 'ARS', label: 'Peso argentino' }] },
        },
      },
    })

    expect(wrapper.text()).toContain('20 L/100 km')
    expect(wrapper.text()).toContain('Pendiente de rendir')
    expect(wrapper.text()).toContain('YPF')
    expect(wrapper.text()).toContain('Peaje')
    expect(wrapper.text()).toContain('Anticipo')
    expect(wrapper.get('form[action="/mantenimiento/viajes/operativos/42/combustible"]').exists()).toBe(true)
    expect(wrapper.get('form[action="/mantenimiento/viajes/operativos/42/gastos"]').exists()).toBe(true)
    expect(wrapper.get('form[action="/mantenimiento/viajes/operativos/42/caja"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('oculta las acciones cuando el viaje ya avanzó', () => {
    const wrapper = mount(TripOperationsDetailPage, {
      props: { data: { ...data, canStart: false, canCancel: false, trip: { ...data.trip, estado: 'EN_CURSO', estado_label: 'En curso' } } },
    })

    expect(wrapper.findAll('form')).toHaveLength(0)
    expect(wrapper.text()).toContain('En curso')
    wrapper.unmount()
  })
})
