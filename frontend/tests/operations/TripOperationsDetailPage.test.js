import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import TripOperationsDetailPage from '../../src/pages/operations/TripOperationsDetailPage.vue'
import AutocompleteSelect from '../../src/pages/operations/components/AutocompleteSelect.vue'

if (typeof HTMLDialogElement !== 'undefined' && !HTMLDialogElement.prototype.showModal) {
  HTMLDialogElement.prototype.showModal = vi.fn(function () { this.open = true })
}

const data = {
  backUrl: '/mantenimiento/viajes/operativos',
  links: { detail: '/mantenimiento/viajes/operativos/42', percentage: '/mantenimiento/viajes/operativos/42/porcentaje' },
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

afterEach(() => {
  vi.unstubAllGlobals()
})

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
    expect(wrapper.text()).toContain('20/10/2026')
    expect(wrapper.text()).not.toContain('20/10/2026 08:15')
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
    expect(wrapper.get('input[name="fecha_salida_real"]').attributes('type')).toBe('date')
    expect(wrapper.text()).toContain('Fecha de salida')
    expect(wrapper.text()).not.toContain('Fecha y hora de salida')
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
    expect(wrapper.text()).toContain('Cargar combustible')
    expect(wrapper.text()).toContain('Cargar gasto')
    expect(wrapper.text()).toContain('Caja del chofer')
    const fuelButton = wrapper.findAll('button').find((button) => button.text().includes('Cargar combustible'))
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
    expect(wrapper.get('input[name="fecha_regreso_real"]').attributes('type')).toBe('date')
    expect(wrapper.text()).toContain('Fecha de regreso')
    expect(wrapper.text()).not.toContain('Fecha y hora de regreso')
    const fuelButton = wrapper.findAll('button').find((button) => button.text().includes('Cargar combustible'))
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
            concepto: 'Espera en descarga', concepto_id: null, cantidad: 1,
            importe: 25000, importe_unitario: null, moneda: 'ARS', cotizacion: 1,
            importe_ars: 25000, observaciones: null,
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
    expect(wrapper.findAll('button').some((button) => button.text().includes('Cargar haber'))).toBe(true)
    expect(wrapper.find('form[action="/mantenimiento/viajes/operativos/42/haberes/1/eliminar"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('muestra el porcentaje vigente del maestro y permite fijarlo en el viaje', () => {
    const wrapper = mount(TripOperationsDetailPage, {
      props: {
        data: {
          ...data,
          canStart: false,
          canCancel: false,
          canRegisterCosts: true,
          assignments: [
            { rol: 'TRACTOR', rol_label: 'Tractor / camión', label: 'AA111AA · T01', desde: '2026-10-20 08:00:00', hasta: null },
            { rol: 'CHOFER', rol_label: 'Chofer', label: 'MATLUK MARCELO', empleado_id: 11, porcentaje_liquidacion: null, master_percentage: 14, desde: '2026-10-20 08:00:00', hasta: null },
          ],
          totals: { has_legs: true, driver_percentage: 14, driver_percentage_source: 'MAESTRO', drivers_count: 1, multiple_drivers: false },
          options: { expenseCategories: [], paymentMethods: [], currencies: [{ code: 'ARS', label: 'Peso argentino' }] },
        },
      },
    })

    expect(wrapper.text()).toContain('del maestro')
    const form = wrapper.get('form[action="/mantenimiento/viajes/operativos/42/porcentaje"]')
    expect(form.get('input[name="empleado_id"]').element.value).toBe('11')
    expect(form.get('input[name="porcentaje"]').element.value).toBe('14')
    wrapper.unmount()
  })

  it('permite editar un haber desde la liquidación', async () => {    const wrapper = mount(TripOperationsDetailPage, {
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
          links: { ...data.links, earnings: '/mantenimiento/viajes/operativos/42/haberes' },
          totals: { has_legs: true, driver_percentage: 14, drivers_count: 1, multiple_drivers: false },
          settlement: {
            driver: 'Pérez, Juan', commission_ars: 112000, other_earnings_ars: 25000,
            driver_expenses_ars: 0, cash_expenses_ars: 0, advances_ars: 0,
            returns_ars: 0, account_balance_ars: 0, total_ars: 137000,
          },
          otherEarnings: [{
            id: 1, empleado_id: 11, empleado: 'Pérez, Juan', fecha: '2026-10-20',
            concepto: 'Espera en descarga', concepto_id: null, cantidad: 1,
            importe: 25000, importe_unitario: null, moneda: 'ARS', cotizacion: 1,
            importe_ars: 25000, observaciones: null,
          }],
          options: { expenseCategories: [], paymentMethods: [], currencies: [{ code: 'ARS', label: 'Peso argentino' }] },
        },
      },
    })

    const editButton = wrapper.findAll('button').find((button) => button.text() === 'Editar')
    await editButton.trigger('click')
    await nextTick()

    const form = wrapper.get('form[action="/mantenimiento/viajes/operativos/42/haberes/1"]')
    expect(form.get('input[name="empleado_id"]').element.value).toBe('11')
    expect(form.get('input[name="concepto"]').element.value).toBe('Espera en descarga')
    expect(form.get('input[name="importe"]').element.value).toBe('25000')
    expect(form.get('button[type="submit"]').text()).toBe('Guardar cambios')
    wrapper.unmount()
  })

  it('permite cargar otros haberes desde el acceso superior aunque la liquidación no se pueda calcular', async () => {
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
          links: { ...data.links, earnings: '/mantenimiento/viajes/operativos/42/haberes' },
          totals: { has_legs: true, driver_percentage: null, drivers_count: 1, multiple_drivers: false },
          options: {
            expenseCategories: [],
            paymentMethods: [],
            currencies: [{ code: 'ARS', label: 'Peso argentino' }],
            earningConcepts: [
              { id: 5, label: 'Estadía Sider', categoria: 'HABER', unit: 'DIA', amount: 50000, currency: 'ARS', editable: true },
            ],
          },
        },
      },
    })

    expect(wrapper.text()).toContain('El chofer no tiene un porcentaje de liquidación definido.')
    const button = wrapper.findAll('button').find((item) => item.text().includes('Cargar haber'))
    await button.trigger('click')
    await nextTick()

    const form = wrapper.get('form[action="/mantenimiento/viajes/operativos/42/haberes"]')
    expect(form.exists()).toBe(true)
    expect(form.get('select[name="concepto_id"]').text()).toContain('Estadía Sider')
    wrapper.unmount()
  })

  it('calcula el importe del gasto desde un concepto con cantidad fraccionaria', async () => {
    const wrapper = mount(TripOperationsDetailPage, {
      props: {
        data: {
          ...data,
          canStart: false,
          canCancel: false,
          canRegisterCosts: true,
          links: { ...data.links, expense: '/mantenimiento/viajes/operativos/42/gastos' },
          options: {
            expenseCategories: [],
            paymentMethods: [],
            currencies: [{ code: 'ARS', label: 'Peso argentino' }],
            expenseConcepts: [
              { id: 10, label: 'Estadía Sider', categoria: 'GASTO', unit: 'DIA', amount: 50000, currency: 'ARS', editable: false },
              { id: 11, label: 'Peaje', categoria: 'GASTO', unit: 'UNIDAD', amount: 1000, currency: 'ARS', editable: true },
            ],
          },
        },
      },
    })

    const expenseButton = wrapper.findAll('button').find((button) => button.text().includes('Cargar gasto'))
    await expenseButton.trigger('click')
    await nextTick()

    const form = wrapper.get('form[action="/mantenimiento/viajes/operativos/42/gastos"]')
    await form.get('select[name="concepto_id"]').setValue('10')
    await form.get('input[name="cantidad"]').setValue('1.5')
    await form.get('select[name="concepto_id"]').trigger('change')

    expect(form.get('input[name="importe"]').element.value).toBe('75000.00')
    expect(form.get('input[name="importe"]').element.readOnly).toBe(true)
    expect(form.get('select[name="concepto_id"] option[value="10"]').text()).toContain('Estadía Sider')
    expect(form.get('select[name="concepto_id"] option[value="10"]').text()).toContain('ARS 50.000,00 / día')

    await form.get('select[name="concepto_id"]').setValue('11')
    await form.get('select[name="concepto_id"]').trigger('change')
    expect(form.get('input[name="importe"]').element.readOnly).toBe(false)
    wrapper.unmount()
  })

  it('muestra el concepto, la cantidad y el unitario en la liquidación', () => {
    const wrapper = mount(TripOperationsDetailPage, {
      props: {
        data: {
          ...data,
          canStart: false,
          canCancel: false,
          canRegisterCosts: false,
          totals: { has_legs: true, driver_percentage: 14, drivers_count: 1, multiple_drivers: false },
          settlement: {
            driver: 'Pérez, Juan', commission_ars: 112000, other_earnings_ars: 75000,
            driver_expenses_ars: 0, cash_expenses_ars: 0, advances_ars: 0,
            returns_ars: 0, account_balance_ars: 0, total_ars: 187000,
          },
          otherEarnings: [{
            id: 1, empleado_id: 11, empleado: 'Pérez, Juan', fecha: '2026-10-20',
            concepto: 'Estadía Sider', concepto_catalogo: 'Estadía Sider',
            cantidad: 1.5, importe_unitario: 50000, moneda: 'ARS', importe_ars: 75000,
          }],
          options: { expenseCategories: [], paymentMethods: [], currencies: [{ code: 'ARS', label: 'Peso argentino' }] },
        },
      },
    })

    expect(wrapper.text()).toContain('Estadía Sider')
    expect(wrapper.text()).toContain('1,5 × ARS 50.000,00')
    expect(wrapper.text()).toContain('ARS 75.000,00')
    wrapper.unmount()
  })

  it('muestra el diario de bordo y calcula los días al cargar una etapa', async () => {
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
          links: { ...data.links, logbook: '/mantenimiento/viajes/operativos/42/diario-bordo' },
          logbook: [{
            id: 5, tipo: 'FRONTERA', tipo_label: 'Frontera', orden: 1, ubicacion: 'Frontera Brasil',
            empleado: 'Pérez, Juan', concepto_id: 9, concepto: 'Estadía Sider',
            llegada: '2026-10-17 00:00:00', salida: '2026-10-23 00:00:00', horas_libres: 48,
            dias_permanencia: 6, dias_calculados: 4, dias_ajustados: null, dias_liquidados: 4,
            motivo_ajuste: null, importe_unitario: 50000, moneda: 'ARS', haber_id: 2, importe_ars: 200000,
          }],
          options: {
            expenseCategories: [], paymentMethods: [], currencies: [{ code: 'ARS', label: 'Peso argentino' }],
            earningConcepts: [{ id: 9, label: 'Estadía Sider', unit: 'DIA', amount: 50000, currency: 'ARS', editable: false }],
          },
        },
      },
    })

    expect(wrapper.text()).toContain('Diario de bordo')
    expect(wrapper.text()).toContain('Frontera Brasil')
    expect(wrapper.text()).toContain('6 días')
    expect(wrapper.text()).toContain('ARS 200.000,00')
    expect(wrapper.get('form[action="/mantenimiento/viajes/operativos/42/diario-bordo/5/eliminar"]').exists()).toBe(true)

    const logbookButton = wrapper.findAll('button').find((button) => button.text().includes('Diario de bordo'))
    await logbookButton.trigger('click')
    await nextTick()

    const form = wrapper.get('form[action="/mantenimiento/viajes/operativos/42/diario-bordo"]')
    await form.get('input[name="llegada"]').setValue('2026-10-17')
    await form.get('input[name="salida"]').setValue('2026-10-23')
    await form.get('input[name="horas_libres"]').setValue(48)
    await nextTick()

    expect(wrapper.text()).toContain('Permanencia: 6 días')
    expect(wrapper.text()).toContain('Días calculados: 4')
    expect(wrapper.text()).toContain('Días a liquidar: 4')
    wrapper.unmount()
  })

  it('abre el alta rápida de localidad desde el agregar tramo', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ provinces: [{ id: 10, label: 'Misiones · Argentina' }] }),
    })
    vi.stubGlobal('fetch', fetchMock)

    const wrapper = mount(TripOperationsDetailPage, {
      props: {
        data: {
          ...data,
          canStart: false,
          canCancel: false,
          canAddLegs: true,
          links: { ...data.links, localities: '/mantenimiento/viajes/operativos/localidades', provinces: '/mantenimiento/viajes/operativos/localidades/provincias' },
          options: { expenseCategories: [], paymentMethods: [], currencies: [{ code: 'ARS', label: 'Peso argentino' }] },
        },
      },
      global: { stubs: { teleport: true } },
    })

    const origin = wrapper.findAllComponents(AutocompleteSelect).find((component) => component.props('name') === 'localidad_origen_id')
    origin.vm.$emit('create', 'Capitán Leonidas')
    await nextTick()
    await nextTick()

    expect(wrapper.get('input[name="nombre"]').element.value).toBe('Capitán Leonidas')
    expect(wrapper.text()).toContain('Dar de alta una localidad')
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

  it('permite editar el viaje y un tramo mientras está abierto', async () => {
    const wrapper = mount(TripOperationsDetailPage, {
      props: {
        data: {
          ...data,
          canStart: false,
          canCancel: false,
          canEdit: true,
          canAddLegs: true,
          canRegisterCosts: true,
          links: { ...data.links, update: '/mantenimiento/viajes/operativos/42' },
          trip: { ...data.trip, estado: 'EN_CURSO', estado_label: 'En curso', fecha_salida_real: '2026-10-20 00:00:00', km_salida: 145300 },
          assignments: [
            { rol: 'TRACTOR', rol_label: 'Tractor / camión', label: 'AA111AA · T01', equipo_id: 7, desde: '2026-10-20 00:00:00', hasta: null },
            { rol: 'ACOPLADO', rol_label: 'Acoplado', label: 'AC222AC · A01', equipo_id: 9, desde: '2026-10-20 00:00:00', hasta: null },
            { rol: 'CHOFER', rol_label: 'Chofer', label: 'Pérez, Juan', empleado_id: 11, porcentaje_liquidacion: 14, desde: '2026-10-20 00:00:00', hasta: null },
          ],
          legs: [{
            id: 9, orden: 1, cliente: 'Cliente Sur', cliente_id: 324, localidad_origen_id: 1, localidad_destino_id: 2,
            origen: 'Rosario', destino: 'Córdoba', km: 320, toneladas: 30, moneda: 'ARS', cotizacion: 1,
            flete_facturacion: 1000000, flete_facturacion_ars: 1000000,
            flete_liquidacion: 800000, flete_liquidacion_ars: 800000,
            commission_ars: 112000, assigned_costs_ars: 250000, margin_ars: 638000,
          }],
          totals: {
            has_legs: true, invoice_ars: 1000000, liquidation_ars: 800000,
            invoice_complete: true, liquidation_complete: true, legs_without_amounts: [],
            commission_ars: 112000, driver_percentage: 14, drivers_count: 1, multiple_drivers: false,
            fuel_amount_ars: 0, expense_amount_ars: 0,
          },
          editOptions: {
            tractors: [{ id: 7, label: 'AA111AA · T01', type: 'Camión', lastKm: 145300 }],
            trailers: [{ id: 9, label: 'AC222AC · A01', type: 'Acoplado' }],
            drivers: [{ id: 11, label: 'Pérez, Juan', employeeNumber: '100', assignedEquipmentLabel: 'AA111AA · T01' }],
          },
        },
      },
    })

    const editTrip = wrapper.findAll('button').find((button) => button.text().includes('Editar viaje'))
    await editTrip.trigger('click')
    await nextTick()
    const tripForm = wrapper.get('form[action="/mantenimiento/viajes/operativos/42"]')
    expect(tripForm.get('input[name="km_salida"]').element.value).toBe('145300')
    expect(tripForm.get('input[name="fecha_salida_real"]').element.value).toBe('2026-10-20')
    expect(tripForm.get('input[name="choferes_porcentajes[11]"]').element.value).toBe('14')

    const editLeg = wrapper.findAll('button').find((button) => button.text() === 'Editar')
    await editLeg.trigger('click')
    await nextTick()
    const legForm = wrapper.get('form[action="/mantenimiento/viajes/operativos/42/tramos/9"]')
    expect(legForm.get('input[name="flete_facturacion"]').element.value).toBe('1000000')
    expect(legForm.get('input[name="flete_liquidacion"]').element.value).toBe('800000')
    expect(legForm.get('input[name="flete_facturacion"]').attributes('required')).toBeUndefined()
    wrapper.unmount()
  })

  it('bloquea el cierre si falta el flete de algún tramo', () => {
    const wrapper = mount(TripOperationsDetailPage, {
      props: {
        data: {
          ...data,
          canStart: false,
          canCancel: false,
          canClose: true,
          canRegisterCosts: false,
          trip: { ...data.trip, estado: 'EN_CURSO', estado_label: 'En curso', km_salida: 145300 },
          legs: [{
            id: 9, orden: 1, cliente: null, origen: 'Rosario', destino: 'Córdoba', km: 320,
            flete_facturacion_ars: null, flete_liquidacion_ars: null,
            commission_ars: null, assigned_costs_ars: 0, margin_ars: null,
          }],
          totals: {
            has_legs: true, invoice_ars: null, liquidation_ars: null,
            invoice_complete: false, liquidation_complete: false, legs_without_amounts: [1],
            commission_ars: null, driver_percentage: 14, drivers_count: 1, multiple_drivers: false,
            fuel_amount_ars: 0, expense_amount_ars: 0,
          },
        },
      },
    })

    expect(wrapper.text()).toContain('Completá el flete de facturación y el de liquidación de los tramos 1')
    const closeButton = wrapper.findAll('button').find((button) => button.text().includes('Cerrar y generar lectura'))
    expect(closeButton.attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('Sin datos')
    wrapper.unmount()
  })
})
