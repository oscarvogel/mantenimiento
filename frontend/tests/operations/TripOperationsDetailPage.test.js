import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import TripOperationsDetailPage from '../../src/pages/operations/TripOperationsDetailPage.vue'

if (typeof HTMLDialogElement !== 'undefined' && !HTMLDialogElement.prototype.showModal) {
  HTMLDialogElement.prototype.showModal = vi.fn(function () { this.open = true })
}

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

  it('muestra salida real y regreso estimado cuando se inicia al cargar', () => {
    const wrapper = mount(TripOperationsDetailPage, {
      props: {
        data: {
          ...data,
          trip: {
            ...data.trip,
            estado: 'EN_CURSO',
            estado_label: 'En curso',
            fecha_salida_real: '2026-10-20 08:15:00',
            km_salida: 145300,
          },
        },
      },
    })

    expect(wrapper.text()).toContain('Salida real')
    expect(wrapper.text()).toContain('20/10/2026 08:15')
    expect(wrapper.text()).toContain('Regreso estimado')
    expect(wrapper.text()).not.toContain('Salida planificada')
    wrapper.unmount()
  })

  it('ofrece descargar el resumen PDF cuando el viaje terminó', () => {
    const wrapper = mount(TripOperationsDetailPage, {
      props: {
        data: {
          ...data,
          links: { ...data.links, summaryPdf: '/mantenimiento/viajes/operativos/42/resumen.pdf' },
          trip: { ...data.trip, estado: 'FINALIZADO', estado_label: 'Finalizado' },
        },
      },
    })

    expect(wrapper.get('a[href="/mantenimiento/viajes/operativos/42/resumen.pdf"]').text()).toContain('Descargar resumen PDF')
    wrapper.unmount()
  })

  it('no ofrece descargar el resumen PDF antes del cierre', () => {
    const wrapper = mount(TripOperationsDetailPage, {
      props: {
        data: {
          ...data,
          links: { ...data.links, summaryPdf: '/mantenimiento/viajes/operativos/42/resumen.pdf' },
        },
      },
    })

    expect(wrapper.find('a[href="/mantenimiento/viajes/operativos/42/resumen.pdf"]').exists()).toBe(false)
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

  it('muestra el resumen y abre el formulario de carga desde el acceso superior', async () => {
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
    expect(wrapper.text()).toContain('+ Combustible')
    expect(wrapper.text()).toContain('+ Gasto')
    expect(wrapper.text()).toContain('+ Movimiento de caja')
    const fuelButton = wrapper.findAll('button').find((button) => button.text().includes('+ Combustible'))
    await fuelButton.trigger('click')
    await nextTick()
    expect(wrapper.get('form[action="/mantenimiento/viajes/operativos/42/combustible"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('muestra tramos, comisión, margen y cierre del viaje', async () => {
    const wrapper = mount(TripOperationsDetailPage, {
      props: {
        data: {
          ...data,
          canStart: false,
          canCancel: false,
          canAddLegs: true,
          canClose: true,
          canRegisterCosts: true,
          links: {
            ...data.links,
            legs: '/mantenimiento/viajes/operativos/42/tramos',
            finish: '/mantenimiento/viajes/operativos/42/cerrar',
            fuel: '/mantenimiento/viajes/operativos/42/combustible',
            expense: '/mantenimiento/viajes/operativos/42/gastos',
            cash: '/mantenimiento/viajes/operativos/42/caja',
          },
          trip: { ...data.trip, estado: 'EN_CURSO', estado_label: 'En curso', km_salida: 145300 },
          legs: [{
            id: 1, orden: 1, cliente: null, origen: 'Rosario', destino: 'Córdoba', km: 320,
            flete_facturacion_ars: 1000000, flete_liquidacion_ars: 800000,
            commission_ars: 112000, assigned_costs_ars: 250000, margin_ars: 638000,
          }],
          totals: {
            has_legs: true, invoice_ars: 1000000, commission_ars: 112000,
            fuel_amount_ars: 200000, fuel_liters: 300, expense_amount_ars: 50000,
            assigned_costs_ars: 250000, unassigned_costs_ars: 0,
            margin_ars: 638000, margin_pct: 63.8, driver_percentage: 14,
            drivers_count: 1, multiple_drivers: false,
          },
          options: { expenseCategories: [], paymentMethods: [], currencies: [{ code: 'ARS', label: 'Peso argentino' }] },
        },
      },
    })

    expect(wrapper.text()).toContain('Rosario → Córdoba')
    expect(wrapper.text()).toContain('ARS 1.000.000,00')
    expect(wrapper.text()).toContain('ARS 638.000,00')
    expect(wrapper.get('form[action="/mantenimiento/viajes/operativos/42/tramos"]').exists()).toBe(true)
    expect(wrapper.get('form[action="/mantenimiento/viajes/operativos/42/cerrar"]').exists()).toBe(true)
    const fuelButton = wrapper.findAll('button').find((button) => button.text().includes('+ Combustible'))
    await fuelButton.trigger('click')
    await nextTick()
    expect(wrapper.find('select[name="tramo_id"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('muestra la liquidación del chofer y permite cargar otros haberes', () => {
    const wrapper = mount(TripOperationsDetailPage, {
      props: {
        data: {
          ...data,
          canStart: false,
          canCancel: false,
          canRegisterCosts: true,
          assignments: [
            { rol: 'TRACTOR', rol_label: 'Tractor / camión', label: 'AA111AA · T01', desde: '2026-10-20 08:00:00', hasta: null },
            { rol: 'CHOFER', rol_label: 'Chofer', label: 'Pérez, Juan', empleado_id: 11, desde: '2026-10-20 08:00:00', hasta: null },
          ],
          links: {
            ...data.links,
            earnings: '/mantenimiento/viajes/operativos/42/haberes',
          },
          totals: { has_legs: true, driver_percentage: 14, drivers_count: 1, multiple_drivers: false },
          settlement: {
            driver: 'Pérez, Juan', commission_ars: 112000, other_earnings_ars: 25000,
            driver_expenses_ars: 50000, cash_expenses_ars: 20000, advances_ars: 130000,
            returns_ars: 5000, account_balance_ars: -55000, total_ars: 82000,
          },
          otherEarnings: [{
            id: 1, empleado_id: 11, empleado: 'Pérez, Juan', fecha: '2026-10-20',
            concepto: 'Espera en descarga', importe_ars: 25000,
          }],
          options: { expenseCategories: [], paymentMethods: [], currencies: [{ code: 'ARS', label: 'Peso argentino' }] },
        },
      },
    })

    expect(wrapper.text()).toContain('Liquidación del chofer')
    expect(wrapper.text()).toContain('Total a liquidar')
    expect(wrapper.text()).toContain('ARS 82.000,00')
    expect(wrapper.text()).toContain('Espera en descarga')
    expect(wrapper.text()).toContain('20/10/2026')
    expect(wrapper.get('form[action="/mantenimiento/viajes/operativos/42/haberes"]').exists()).toBe(true)
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

  it('abre el formulario breve de gasto y explica el efecto de quién pagó', async () => {
    const wrapper = mount(TripOperationsDetailPage, {
      props: {
        data: {
          ...data,
          canRegisterCosts: true,
          links: { ...data.links, expense: '/mantenimiento/viajes/operativos/42/gastos' },
          options: { expenseCategories: [{ id: 1, label: 'Peaje' }], paymentMethods: [], currencies: [{ code: 'ARS', label: 'Peso argentino' }] },
        },
      },
    })

    const expenseButton = wrapper.findAll('button').find((button) => button.text().includes('+ Gasto'))
    await expenseButton.trigger('click')
    await nextTick()

    expect(wrapper.get('form[action="/mantenimiento/viajes/operativos/42/gastos"]').exists()).toBe(true)
    expect(wrapper.get('input[name="importe"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Agregar moneda, medio de pago, comprobante o tramo')
    expect(wrapper.text()).toContain('Es costo del viaje.')

    await wrapper.get('select[name="pagado_por"]').setValue('CHOFER')
    await nextTick()
    expect(wrapper.text()).toContain('Es costo del viaje y queda como gasto a reintegrar al chofer.')

    await wrapper.get('select[name="pagado_por"]').setValue('CAJA')
    await nextTick()
    expect(wrapper.text()).toContain('Es costo del viaje pagado con la caja entregada.')
    wrapper.unmount()
  })

  it('ofrece guardar y cargar otro con campos repetibles y limpia los importes', async () => {
    const wrapper = mount(TripOperationsDetailPage, {
      props: {
        data: {
          ...data,
          canRegisterCosts: true,
          links: { ...data.links, fuel: '/mantenimiento/viajes/operativos/42/combustible' },
          options: { expenseCategories: [], paymentMethods: [], currencies: [{ code: 'ARS', label: 'Peso argentino' }] },
        },
      },
    })

    const fuelButton = wrapper.findAll('button').find((button) => button.text().includes('+ Combustible'))
    await fuelButton.trigger('click')
    await nextTick()

    const saveAndNext = wrapper.findAll('button').find((button) => button.text() === 'Guardar y cargar otro')
    expect(saveAndNext.attributes('name')).toBe('accion')
    expect(saveAndNext.attributes('value')).toBe('otro')
    expect(wrapper.get('input[name="repetir[]"][value="fecha"]').element.checked).toBe(true)
    expect(wrapper.get('input[name="repetir[]"][value="moneda"]').element.checked).toBe(true)
    expect(wrapper.get('input[name="repetir[]"][value="estacion"]').element.checked).toBe(false)
    wrapper.unmount()
  })

  it('reabre el formulario con los valores repetidos y los importes vacíos', async () => {
    const wrapper = mount(TripOperationsDetailPage, {
      props: {
        data: {
          ...data,
          canRegisterCosts: true,
          links: { ...data.links, fuel: '/mantenimiento/viajes/operativos/42/combustible' },
          entryForm: { type: 'fuel', values: {}, repeat: { fecha: '2026-11-03T10:30', moneda: 'ARS', cotizacion: '1', tramo_id: '1' } },
          options: { expenseCategories: [], paymentMethods: [], currencies: [{ code: 'ARS', label: 'Peso argentino' }] },
        },
      },
    })

    await nextTick()
    expect(wrapper.get('input[name="fecha"]').element.value).toBe('2026-11-03T10:30')
    expect(wrapper.get('input[name="litros"]').element.value).toBe('')
    expect(wrapper.get('input[name="importe"]').element.value).toBe('')
    expect(wrapper.get('input[name="repetir[]"][value="fecha"]').element.checked).toBe(true)
    expect(wrapper.get('input[name="repetir[]"][value="moneda"]').element.checked).toBe(true)
    expect(wrapper.get('input[name="repetir[]"][value="tramo_id"]').element.checked).toBe(true)
    expect(wrapper.get('input[name="repetir[]"][value="estacion"]').element.checked).toBe(false)
    wrapper.unmount()
  })

  it('reabre el formulario con lo escrito cuando hubo un error de validación', async () => {
    const wrapper = mount(TripOperationsDetailPage, {
      props: {
        data: {
          ...data,
          canRegisterCosts: true,
          links: { ...data.links, expense: '/mantenimiento/viajes/operativos/42/gastos' },
          entryForm: { type: 'expense', values: { fecha: '2026-11-03T10:30', importe: '1234', pagado_por: 'CHOFER' } },
          options: { expenseCategories: [{ id: 1, label: 'Peaje' }], paymentMethods: [], currencies: [{ code: 'ARS', label: 'Peso argentino' }] },
        },
      },
    })

    await nextTick()
    expect(wrapper.get('input[name="importe"]').element.value).toBe('1234')
    expect(wrapper.get('select[name="pagado_por"]').element.value).toBe('CHOFER')
    expect(wrapper.text()).toContain('Es costo del viaje y queda como gasto a reintegrar al chofer.')
    wrapper.unmount()
  })

  it('reabre la edición con lo escrito cuando hubo un error de validación', async () => {
    const wrapper = mount(TripOperationsDetailPage, {
      props: {
        data: {
          ...data,
          canRegisterCosts: true,
          links: { ...data.links, fuel: '/mantenimiento/viajes/operativos/42/combustible' },
          fuel: [{ id: 5, fecha: '2026-11-03 09:00:00', litros: 80, importe: 40000, moneda: 'ARS', importe_ars: 40000, estacion: 'YPF' }],
          entryForm: { type: 'fuel', editing_id: 5, values: { fecha: '2026-11-03T10:30', litros: '120', importe: '90000' } },
          options: { expenseCategories: [], paymentMethods: [], currencies: [{ code: 'ARS', label: 'Peso argentino' }] },
        },
      },
    })

    await nextTick()
    expect(wrapper.get('input[name="litros"]').element.value).toBe('120')
    expect(wrapper.get('input[name="importe"]').element.value).toBe('90000')
    expect(wrapper.text()).toContain('Editar carga de combustible')
    wrapper.unmount()
  })

  it('filtra los movimientos y muestra tarjetas móviles con detalle', async () => {
    const wrapper = mount(TripOperationsDetailPage, {
      props: {
        data: {
          ...data,
          canRegisterCosts: true,
          links: { ...data.links, fuel: '/mantenimiento/viajes/operativos/42/combustible', expense: '/mantenimiento/viajes/operativos/42/gastos', cash: '/mantenimiento/viajes/operativos/42/caja' },
          fuel: [{ id: 1, fecha: '2026-10-20 10:00:00', litros: 100, importe: 50000, moneda: 'ARS', importe_ars: 50000, estacion: 'YPF' }],
          expenses: [{ id: 1, fecha: '2026-10-20 11:00:00', importe: 20000, moneda: 'ARS', importe_ars: 20000, categoria: 'Peaje', pagado_por: 'EMPRESA' }],
          cashMovements: [{ id: 1, tipo: 'ANTICIPO', fecha: '2026-10-20 07:00:00', importe: 100000, moneda: 'ARS', importe_ars: 100000 }],
          options: { expenseCategories: [], paymentMethods: [], currencies: [{ code: 'ARS', label: 'Peso argentino' }] },
        },
      },
    })

    expect(wrapper.find('ul.md\\:hidden').exists()).toBe(true)
    expect(wrapper.findAll('summary').filter((summary) => summary.text() === 'Ver detalle').length).toBeGreaterThan(0)

    const expenseFilter = wrapper.findAll('button').find((button) => button.text().startsWith('Gastos'))
    await expenseFilter.trigger('click')
    await nextTick()

    expect(wrapper.text()).toContain('Peaje')
    expect(wrapper.text()).not.toContain('YPF')
    wrapper.unmount()
  })
})
