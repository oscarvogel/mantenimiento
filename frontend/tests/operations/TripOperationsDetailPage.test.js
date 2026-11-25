import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import TripOperationsDetailPage from '../../src/pages/operations/TripOperationsDetailPage.vue'
import AutocompleteSelect from '../../src/pages/operations/components/AutocompleteSelect.vue'
import TripConceptCreateModal from '../../src/pages/operations/components/TripConceptCreateModal.vue'

const alertsConfirm = vi.hoisted(() => vi.fn())
vi.mock('../../src/composables/useAlerts.js', () => ({ useAlerts: () => ({ confirm: alertsConfirm }) }))

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

  it('avisa que el recorrido terminó y precarga el cierre con la fecha y el odómetro', () => {
    const wrapper = mount(TripOperationsDetailPage, {
      props: {
        data: {
          ...data,
          canStart: false,
          canCancel: false,
          canClose: true,
          cierre_pendiente: true,
          recorrido_cerrado: true,
          fecha_cierre_sugerida: '2026-11-11',
          km_cierre_sugerido: 148900,
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

    expect(wrapper.text()).toContain('El recorrido terminó el 11/11/2026 pero el viaje sigue En curso')
    expect(wrapper.get('input[name="fecha_regreso_real"]').attributes('value')).toBe('2026-11-11')
    expect(wrapper.get('input[name="km_regreso"]').element.value).toBe('148900')
    expect(wrapper.text()).toContain('Regreso estimado (último tramo)')
    wrapper.unmount()
  })

  const closingTrip = (overrides = {}) => ({
    ...data,
    canStart: false,
    canCancel: false,
    canClose: true,
    canEdit: true,
    links: { ...data.links, finish: '/mantenimiento/viajes/operativos/42/cerrar' },
    totals: { has_legs: true, fuel_liters: 2012, expense_amount_ars: 160900, fuel_without_price: 0 },
    consumption: { km: null, liters_per_100: null, source: null, typical_range: { min: 20, max: 60 } },
    km_cierre_sugerido: 1732150,
    km_cierre_sugerencia: { km: 1732150, source: 'TELEMETRIA', observed_at: '2026-11-17 19:40:00', provider: 'AUTOTRAC' },
    trip: { ...data.trip, numero: 21, estado: 'EN_CURSO', estado_label: 'En curso', fecha_salida_real: '2026-10-30 08:00:00', km_salida: 1727000 },
    ...overrides,
  })

  it('sugiere el odómetro de regreso desde la telemetría y muestra el consumo antes de cerrar', () => {
    const wrapper = mount(TripOperationsDetailPage, { props: { data: closingTrip() } })

    expect(wrapper.get('[data-testid="close-km-regreso"]').element.value).toBe('1732150')
    expect(wrapper.get('[data-testid="close-km-hint"]').text()).toContain('Lectura de telemetría (AUTOTRAC) del 17/11/2026 19:40')
    expect(wrapper.get('[data-testid="close-preview"]').text()).toContain('5.150 km')
    expect(wrapper.get('[data-testid="close-consumption"]').text()).toBe('39,1 L/100 km')
    expect(wrapper.find('[data-testid="close-consumption-warning"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('avisa cuando el consumo que va a quedar está fuera de lo normal', async () => {
    const wrapper = mount(TripOperationsDetailPage, { props: { data: closingTrip() } })

    await wrapper.get('[data-testid="close-km-regreso"]').setValue('1729288')

    expect(wrapper.get('[data-testid="close-consumption"]').text()).toBe('87,9 L/100 km')
    expect(wrapper.get('[data-testid="close-consumption-warning"]').text()).toContain('más de lo normal')
    wrapper.unmount()
  })

  it('avisa si el odómetro de regreso no supera al de salida', async () => {
    const wrapper = mount(TripOperationsDetailPage, { props: { data: closingTrip() } })

    await wrapper.get('[data-testid="close-km-regreso"]').setValue('1726000')

    expect(wrapper.get('[data-testid="close-consumption-warning"]').text()).toContain('mayor que el de salida')
    wrapper.unmount()
  })

  it('sin telemetría aclara que es la última lectura conocida del equipo', () => {
    const wrapper = mount(TripOperationsDetailPage, {
      props: { data: closingTrip({ km_cierre_sugerencia: { km: 1732150, source: 'EQUIPO', observed_at: null, provider: null } }) },
    })

    expect(wrapper.get('[data-testid="close-km-hint"]').text()).toContain('Última lectura conocida del equipo')
    wrapper.unmount()
  })

  it('pide confirmación con el diálogo del sistema y no envía si se cancela', async () => {
    alertsConfirm.mockReset().mockResolvedValue(false)
    const wrapper = mount(TripOperationsDetailPage, { props: { data: closingTrip() } })
    const form = wrapper.get('[data-testid="close-trip-form"]').element
    form.requestSubmit = vi.fn()

    const event = new Event('submit', { cancelable: true })
    form.dispatchEvent(event)
    await flushPromises()

    expect(event.defaultPrevented).toBe(true)
    expect(alertsConfirm).toHaveBeenCalledOnce()
    const options = alertsConfirm.mock.calls[0][0]
    expect(options.title).toBe('¿Cerrar el viaje #21?')
    expect(options.html).toContain('ya no se va a poder editar')
    expect(options.html).toContain('39,1 L/100 km')
    expect(options.button).toBe('Cerrar viaje')
    expect(form.requestSubmit).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('envía el cierre cuando se confirma', async () => {
    alertsConfirm.mockReset().mockResolvedValue(true)
    const wrapper = mount(TripOperationsDetailPage, { props: { data: closingTrip() } })
    const form = wrapper.get('[data-testid="close-trip-form"]').element
    form.requestSubmit = vi.fn()

    form.dispatchEvent(new Event('submit', { cancelable: true }))
    await flushPromises()

    expect(form.requestSubmit).toHaveBeenCalledOnce()
    wrapper.unmount()
  })

  it('no deja cerrar si el odómetro de regreso no supera al de salida', async () => {
    alertsConfirm.mockReset().mockResolvedValue(true)
    const wrapper = mount(TripOperationsDetailPage, { props: { data: closingTrip() } })
    await wrapper.get('[data-testid="close-km-regreso"]').setValue('480321')

    expect(wrapper.get('[data-testid="close-km-recorridos"]').text()).toBe('—')
    expect(wrapper.get('[data-testid="close-submit"]').attributes('disabled')).toBeDefined()

    const form = wrapper.get('[data-testid="close-trip-form"]').element
    form.requestSubmit = vi.fn()
    const event = new Event('submit', { cancelable: true })
    form.dispatchEvent(event)
    await flushPromises()

    expect(event.defaultPrevented).toBe(true)
    expect(alertsConfirm).not.toHaveBeenCalled()
    expect(form.requestSubmit).not.toHaveBeenCalled()
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

    expect(wrapper.get('a[href="/mantenimiento/viajes/operativos/42/resumen.pdf"]').text()).toContain('Resumen del viaje (PDF)')
    wrapper.unmount()
  })

  it('ofrece el resumen PDF como borrador mientras el viaje está en curso', () => {
    const wrapper = mount(TripOperationsDetailPage, {
      props: {
        data: {
          ...data,
          links: { ...data.links, summaryPdf: '/mantenimiento/viajes/operativos/42/resumen.pdf' },
          trip: { ...data.trip, estado: 'EN_CURSO', estado_label: 'En curso' },
        },
      },
    })

    expect(wrapper.get('[data-testid="trip-summary-pdf-link"]').text()).toContain('Resumen del viaje (borrador)')
    wrapper.unmount()
  })

  it('no ofrece el resumen PDF mientras el viaje está planificado', () => {
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

  it('ofrece iniciar y anular mientras el viaje está planificado', async () => {
    const wrapper = mount(TripOperationsDetailPage, { props: { data } })

    const forms = wrapper.findAll('form')
    expect(forms.map((form) => form.attributes('action'))).toEqual([
      '/mantenimiento/viajes/operativos/42/iniciar',
    ])
    // La anulación está a la vista en el encabezado y abre un diálogo con motivo obligatorio.
    await wrapper.get('[data-testid="trip-cancel-button"]').trigger('click')
    const dialog = wrapper.get('[data-testid="cancel-dialog"]')
    expect(dialog.get('form').attributes('action')).toBe('/mantenimiento/viajes/operativos/42/cancelar')
    expect(dialog.get('textarea').attributes('name')).toBe('motivo_cancelacion')
    expect(dialog.get('textarea').attributes('required')).toBeDefined()
    expect(dialog.text()).toContain('No se puede deshacer')
    expect(wrapper.get('input[name="fecha_salida_real"]').attributes('type')).toBe('date')
    expect(wrapper.text()).toContain('Fecha de salida')
    expect(wrapper.text()).not.toContain('Fecha y hora de salida')
    wrapper.unmount()
  })

  it('anula un tramo con motivo y lista los tramos anulados aparte', async () => {
    const wrapper = mount(TripOperationsDetailPage, {
      props: {
        data: {
          ...data,
          canEdit: true,
          canCancelLegs: true,
          links: { ...data.links, legs: '/mantenimiento/viajes/operativos/42/tramos' },
          legs: [{ id: 7, orden: 1, origen: 'Mendoza', destino: 'Dionisio Cerqueira', cliente: 'Prosper', flete_liquidacion_ars: 3290000, assigned_costs_ars: 0 }],
          cancelled_legs: [{ id: 8, orden: 2, origen: 'Dionisio Cerqueira', destino: 'Mendoza', cliente: 'Prosper', anulado_at: '2026-11-20 16:00:00', anulado_por: 'Camila Kunrath', motivo_anulacion: 'Duplicado' }],
        },
      },
    })

    await wrapper.get('[data-testid="leg-cancel-7"]').trigger('click')
    const dialog = wrapper.get('[data-testid="cancel-dialog"]')
    expect(dialog.get('form').attributes('action')).toBe('/mantenimiento/viajes/operativos/42/tramos/7/anular')
    expect(dialog.get('textarea').attributes('name')).toBe('motivo_anulacion')
    expect(dialog.text()).toContain('Anular el tramo 1: Mendoza → Dionisio Cerqueira')

    const cancelled = wrapper.get('[data-testid="cancelled-legs"]')
    expect(cancelled.text()).toContain('Tramos anulados (1)')
    expect(cancelled.text()).toContain('Camila Kunrath')
    expect(cancelled.text()).toContain('Duplicado')
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
          cashMovements: [
            { id: 1, tipo: 'ANTICIPO', fecha: '2026-10-20 07:00:00', importe: 100000, moneda: 'ARS', importe_ars: 100000, observaciones: null },
            { id: 2, tipo: 'RETIRO', fecha: '2026-10-20 08:00:00', importe: 1800000, moneda: 'ARS', importe_ars: 1800000, observaciones: null },
          ],
          options: { expenseCategories: [{ id: 1, label: 'Peaje' }], paymentMethods: [{ id: 1, label: 'Efectivo' }], currencies: [{ code: 'ARS', label: 'Peso argentino' }] },
        },
      },
    })

    expect(wrapper.text()).toContain('20 L/100 km')
    expect(wrapper.text()).toContain('Pendiente de rendir')
    expect(wrapper.text()).toContain('YPF')
    expect(wrapper.text()).toContain('Peaje')
    expect(wrapper.text()).toContain('Anticipo')
    // El tipo RETIRO se muestra como "Adelanto de sueldo" para los choferes.
    expect(wrapper.text()).toContain('Adelanto de sueldo')
    expect(wrapper.text()).not.toMatch(/\bRetiro\b/)
    const cashButton = wrapper.findAll('button').find((button) => button.text().includes('Caja del chofer'))
    await cashButton.trigger('click')
    await nextTick()
    expect(wrapper.get('option[value="RETIRO"]').text()).toBe('Adelanto de sueldo')
    expect(wrapper.text()).toContain('Cargar combustible')
    expect(wrapper.text()).toContain('Cargar gasto')
    expect(wrapper.text()).toContain('Caja del chofer')
    const fuelButton = wrapper.findAll('button').find((button) => button.text().includes('Cargar combustible'))
    await fuelButton.trigger('click')
    await nextTick()
    expect(wrapper.get('form[action="/mantenimiento/viajes/operativos/42/combustible"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('deja cargar combustible sin importe y marca lo que falta en un viaje finalizado', async () => {
    const open = mount(TripOperationsDetailPage, {
      props: { data: { ...data, canRegisterCosts: true, links: { ...data.links, fuel: '/mantenimiento/viajes/operativos/42/combustible' }, options: { currencies: [{ code: 'ARS', label: 'Peso argentino' }] } } },
    })
    const fuelButton = open.findAll('button').find((button) => button.text().includes('Cargar combustible'))
    await fuelButton.trigger('click')
    await nextTick()
    const amount = open.get('form[action="/mantenimiento/viajes/operativos/42/combustible"] input[name="importe"]')
    expect(amount.attributes('required')).toBeUndefined()
    expect(open.text()).toContain('dejá el importe vacío')
    open.unmount()

    const finished = mount(TripOperationsDetailPage, {
      props: {
        data: {
          ...data,
          trip: { ...data.trip, estado: 'FINALIZADO', estado_label: 'Finalizado' },
          canStart: false,
          canCancel: false,
          canRegisterCosts: false,
          canCompleteFuelPrices: true,
          links: { ...data.links, fuelPending: '/mantenimiento/viajes/operativos/combustible-sin-precio' },
          totals: { fuel_liters: 420.5, fuel_amount_ars: 50000, fuel_without_price: 1, fuel_liters_without_price: 320.5, expense_amount_ars: 0, direct_cost_ars: 50000 },
          fuel: [
            { id: 1, fecha: '2026-10-20 10:00:00', litros: 100, importe: 50000, moneda: 'ARS', importe_ars: 50000, kilometraje: null, estacion: 'YPF', comprobante: null, observaciones: null },
            { id: 2, fecha: '2026-10-21 10:00:00', litros: 320.5, importe: null, moneda: 'ARS', importe_ars: null, sin_precio: true, kilometraje: null, estacion: 'Playa propia', comprobante: null, observaciones: null },
          ],
        },
      },
    })

    expect(finished.findAll('[data-fuel-without-price]')).toHaveLength(1)
    expect(finished.get('[data-fuel-without-price]').text()).toBe('Sin precio')
    const banner = finished.get('[data-fuel-pending-banner]')
    expect(banner.text()).toContain('1 carga sin precio')
    expect(banner.get('a').attributes('href')).toBe('/mantenimiento/viajes/operativos/combustible-sin-precio')
    expect(finished.text()).toContain('salvo el precio del combustible que falta')
    finished.unmount()
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
            fecha_inicio: '2026-10-20', fecha_fin: '2026-10-21',
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
    // El flete de facturación solo se ve al editar el tramo, no en el detalle.
    expect(wrapper.text()).not.toContain('ARS 1.000.000,00')
    expect(wrapper.text()).not.toContain('Facturado')
    expect(wrapper.text()).not.toContain('Flete facturación (ARS)')
    expect(wrapper.text()).toContain('ARS 800.000,00')
    expect(wrapper.text()).toContain('ARS 638.000,00')
    expect(wrapper.text()).toContain('Fechas')
    expect(wrapper.text()).toContain('20/10/2026 → 21/10/2026')
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

  it('avisa los gastos de empresa que no descuentan el adelanto y propone caja del chofer', async () => {
    const wrapper = mount(TripOperationsDetailPage, {
      props: {
        data: {
          ...data,
          canStart: false,
          canCancel: false,
          canRegisterCosts: true,
          links: {
            ...data.links,
            expense: '/mantenimiento/viajes/operativos/42/gastos',
            reattributeExpenses: '/mantenimiento/viajes/operativos/42/gastos/reatribuir-caja',
          },
          expenses: [{
            id: 1, fecha: '2026-11-19 11:58:00', importe: 94900, moneda: 'ARS', importe_ars: 94900,
            categoria: 'Peaje', concepto_catalogo: null, cantidad: 1, medio_pago: 'Efectivo',
            pagado_de_caja: false, pagado_por: 'EMPRESA', comprobante: null, observaciones: null,
          }],
          cashMovements: [
            { id: 1, tipo: 'ANTICIPO', fecha: '2026-11-19 12:01:00', importe: 100000, moneda: 'ARS', importe_ars: 100000, observaciones: null },
          ],
          options: { expenseCategories: [], paymentMethods: [], currencies: [{ code: 'ARS', label: 'Peso argentino' }] },
        },
      },
    })

    expect(wrapper.text()).toContain('ARS 94.900,00')
    expect(wrapper.text()).toContain('no descuentan el adelanto')
    expect(wrapper.get('form[action="/mantenimiento/viajes/operativos/42/gastos/reatribuir-caja"]').exists()).toBe(true)

    const expenseButton = wrapper.findAll('button').find((button) => button.text().includes('Cargar gasto'))
    await expenseButton.trigger('click')
    await nextTick()

    expect(wrapper.get('select[name="pagado_por"]').element.value).toBe('CAJA')
    expect(wrapper.text()).toContain('ARS 100.000,00 de adelantos')
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
    // El concepto se elige con un combo con búsqueda: las opciones siguen
    // siendo las del catálogo, pero ya no es un <select> nativo.
    const conceptSelect = form.getComponent(AutocompleteSelect)
    expect(conceptSelect.props('options').map((option) => option.label).join(' ')).toContain('Estadía Sider')
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
    const conceptSelect = form.findAllComponents(AutocompleteSelect)[1]
    await conceptSelect.setValue('10')
    await form.get('input[name="cantidad"]').setValue('1.5')
    await conceptSelect.setValue('10')

    expect(form.get('input[name="importe"]').element.value).toBe('75000.00')
    expect(form.get('input[name="importe"]').element.readOnly).toBe(true)
    expect(form.get('input[name="concepto_id"]').element.value).toBe('10')
    expect(conceptSelect.get('input[role="combobox"]').element.value).toContain('Estadía Sider')
    expect(conceptSelect.get('input[role="combobox"]').element.value).toContain('ARS 50.000,00 / día')

    await conceptSelect.setValue('11')
    expect(form.get('input[name="importe"]').element.readOnly).toBe(false)
    wrapper.unmount()
  })

  it('crea un concepto desde el viaje sin salir de la pantalla', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({
        concept: { id: 77, label: 'Viático cena', categoria: 'GASTO', unit: 'UNIDAD', amount: 5000, currency: 'ARS', editable: true },
      }),
    })
    vi.stubGlobal('fetch', fetchMock)

    const wrapper = mount(TripOperationsDetailPage, {
      props: {
        data: {
          ...data,
          canStart: false,
          canCancel: false,
          canRegisterCosts: true,
          canCreateConcepts: true,
          links: {
            ...data.links,
            expense: '/mantenimiento/viajes/operativos/42/gastos',
            concept: '/mantenimiento/viajes/operativos/42/conceptos',
          },
          options: {
            expenseCategories: [],
            paymentMethods: [],
            currencies: [{ code: 'ARS', label: 'Peso argentino' }],
            expenseConcepts: [],
          },
        },
      },
    })

    const expenseButton = wrapper.findAll('button').find((button) => button.text().includes('Cargar gasto'))
    await expenseButton.trigger('click')
    await nextTick()

    // El operador escribe un concepto que no existe y lo da de alta.
    const conceptSelect = wrapper.get('form[action="/mantenimiento/viajes/operativos/42/gastos"]')
      .findAllComponents(AutocompleteSelect)
      .find((component) => component.props('name') === 'concepto_id')
    conceptSelect.vm.$emit('create', 'Viático cena')
    await nextTick()
    await nextTick()

    const modal = wrapper.getComponent(TripConceptCreateModal)
    expect(modal.get('input[name="nombre"]').element.value).toBe('Viático cena')
    await modal.get('input[name="importe_unitario"]').setValue('5000')
    await modal.get('form').trigger('submit')
    await flushPromises()
    await nextTick()

    expect(fetchMock).toHaveBeenCalledTimes(1)
    const [url, request] = fetchMock.mock.calls[0]
    expect(url).toBe('/mantenimiento/viajes/operativos/42/conceptos')
    expect(request.body).toContain('nombre=Vi%C3%A1tico+cena')
    expect(request.body).toContain('categoria=GASTO')
    expect(request.body).toContain('importe_unitario=5000')

    // El concepto queda elegido y el importe calculado: no hay que recargar.
    const form = wrapper.get('form[action="/mantenimiento/viajes/operativos/42/gastos"]')
    expect(form.get('input[name="concepto_id"]').element.value).toBe('77')
    expect(form.get('input[name="importe"]').element.value).toBe('5000.00')
    wrapper.unmount()
  })

  it('ofrece crear el concepto de estadía cuando todavía no hay conceptos por día', async () => {
    const wrapper = mount(TripOperationsDetailPage, {
      props: {
        data: {
          ...data,
          canStart: false,
          canCancel: false,
          canRegisterCosts: true,
          canCreateConcepts: true,
          assignments: [
            { rol: 'TRACTOR', rol_label: 'Tractor / camión', label: 'AA111AA · T01', desde: '2026-10-20 08:00:00', hasta: null },
            { rol: 'CHOFER', rol_label: 'Chofer', label: 'Pérez, Juan', empleado_id: 11, desde: '2026-10-20 08:00:00', hasta: null },
          ],
          links: { ...data.links, logbook: '/mantenimiento/viajes/operativos/42/diario-bordo' },
          options: { expenseCategories: [], paymentMethods: [], currencies: [{ code: 'ARS', label: 'Peso argentino' }], earningConcepts: [] },
        },
      },
    })

    // El diario de bordo se puede abrir aunque no haya conceptos cargados.
    const logbookButton = wrapper.findAll('button').find((button) => button.text().includes('Diario de bordo'))
    expect(logbookButton).toBeTruthy()
    expect(wrapper.text()).toContain('Todavía no hay conceptos de haber liquidados por día')

    const createButton = wrapper.findAll('button').find((button) => button.text().includes('Crear el concepto acá mismo'))
    expect(createButton).toBeTruthy()
    await createButton.trigger('click')
    await nextTick()
    await nextTick()

    const modal = wrapper.getComponent(TripConceptCreateModal)
    expect(modal.get('select[name="categoria"]').element.value).toBe('HABER')
    expect(modal.get('select[name="unidad"]').element.value).toBe('DIA')
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
            fecha_inicio: '2026-10-20', fecha_fin: '2026-10-21',
          }],
          totals: {
            has_legs: true, invoice_ars: 1000000, liquidation_ars: 800000,
            invoice_complete: true, liquidation_complete: true, legs_without_amounts: [],
            commission_ars: 112000, driver_percentage: 14, drivers_count: 1, multiple_drivers: false,
            fuel_amount_ars: 0, expense_amount_ars: 0,
          },
          editOptions: {
            tractors: [{ id: 7, label: 'AA111AA · T01', type: 'Camión', lastKm: 145300, linkedTrailerIds: [9] }],
            trailers: [{ id: 9, label: 'AC222AC · A01', type: 'Acoplado', plate: 'AC222AC' }],
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
    expect(legForm.get('input[name="fecha_inicio"]').element.value).toBe('2026-10-20')
    expect(legForm.get('input[name="fecha_fin"]').element.value).toBe('2026-10-21')
    wrapper.unmount()
  })

  it('carga las fechas del tramo y muestra la patente del semirremolque', async () => {
    const wrapper = mount(TripOperationsDetailPage, {
      props: {
        data: {
          ...data,
          canStart: false,
          canCancel: false,
          canEdit: true,
          canAddLegs: true,
          links: { ...data.links, legs: '/mantenimiento/viajes/operativos/42/tramos' },
          trip: { ...data.trip, estado: 'EN_CURSO', estado_label: 'En curso' },
          assignments: [
            { rol: 'TRACTOR', rol_label: 'Tractor / camión', label: 'AA111AA · T01', equipo_id: 7, desde: '2026-10-20 00:00:00', hasta: null },
          ],
          editOptions: {
            tractors: [{ id: 7, label: 'AA111AA · T01', type: 'Camión', lastKm: 145300, linkedTrailerIds: [9] }],
            trailers: [{ id: 9, label: 'AC222AC · A01', type: 'Acoplado', plate: 'AC222AC' }],
            drivers: [],
          },
        },
      },
    })

    const legForm = wrapper.get('form[action="/mantenimiento/viajes/operativos/42/tramos"]')
    expect(legForm.get('input[name="fecha_inicio"]').attributes('type')).toBe('date')
    expect(legForm.get('input[name="fecha_fin"]').attributes('type')).toBe('date')

    const editTrip = wrapper.findAll('button').find((button) => button.text().includes('Editar viaje'))
    await editTrip.trigger('click')
    await nextTick()
    expect(wrapper.text()).toContain('Patente del semirremolque: AC222AC')
    wrapper.unmount()
  })

  it('completa el semirremolque con la patente al cambiar el tractor', async () => {
    const wrapper = mount(TripOperationsDetailPage, {
      props: {
        data: {
          ...data,
          canStart: false,
          canCancel: false,
          canEdit: true,
          links: { ...data.links, update: '/mantenimiento/viajes/operativos/42' },
          trip: { ...data.trip, estado: 'EN_CURSO', estado_label: 'En curso' },
          assignments: [
            { rol: 'TRACTOR', rol_label: 'Tractor / camión', label: 'AA111AA · T01', equipo_id: 7, desde: '2026-10-20 00:00:00', hasta: null },
          ],
          editOptions: {
            tractors: [
              { id: 7, label: 'AA111AA · T01', type: 'Camión', lastKm: 145300, linkedTrailerIds: [9] },
              { id: 8, label: 'BB222BB · T02', type: 'Camión', lastKm: 90000, linkedTrailerIds: [10] },
            ],
            trailers: [
              { id: 9, label: 'AC222AC · A01', type: 'Acoplado', plate: 'AC222AC' },
              { id: 10, label: 'EE333FF · A02', type: 'Acoplado', plate: 'EE333FF' },
            ],
            drivers: [],
          },
        },
      },
    })

    const editTrip = wrapper.findAll('button').find((button) => button.text().includes('Editar viaje'))
    await editTrip.trigger('click')
    await nextTick()

    const autocompletes = wrapper.findAllComponents(AutocompleteSelect)
    const tractor = autocompletes.find((component) => component.props('name') === 'tractor_id')
    const trailer = autocompletes.find((component) => component.props('name') === 'acoplado_id[]')

    tractor.vm.$emit('update:modelValue', 8)
    await nextTick()
    await nextTick()

    expect(trailer.props('modelValue')).toEqual(['10'])
    expect(wrapper.text()).toContain('Patente del semirremolque: EE333FF')
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

  it('carga el flete de liquidación en pesos aunque la facturación esté en dólares', async () => {
    const wrapper = mount(TripOperationsDetailPage, {
      props: {
        data: {
          ...data,
          canStart: false,
          canCancel: false,
          canEdit: true,
          canAddLegs: true,
          links: { ...data.links, legs: '/mantenimiento/viajes/operativos/42/tramos' },
          trip: { ...data.trip, estado: 'EN_CURSO', estado_label: 'En curso' },
          options: { expenseCategories: [], paymentMethods: [], currencies: [{ code: 'ARS', label: 'Peso argentino' }, { code: 'USD', label: 'Dólar' }] },
          legs: [{
            id: 9, orden: 1, cliente: 'Cliente Sur', cliente_id: 324, localidad_origen_id: 1, localidad_destino_id: 2,
            origen: 'Rosario', destino: 'Córdoba', km: 320, toneladas: 30,
            moneda: 'USD', cotizacion: 1250,
            moneda_liquidacion: 'ARS', cotizacion_liquidacion: 1,
            flete_facturacion: 4700, flete_facturacion_ars: 5875000,
            flete_liquidacion: 5875000, flete_liquidacion_ars: 5875000,
            commission_ars: 822500, assigned_costs_ars: 0, margin_ars: 0,
          }],
          totals: {
            has_legs: true, invoice_ars: 5875000, liquidation_ars: 5875000,
            invoice_complete: true, liquidation_complete: true, legs_without_amounts: [],
            commission_ars: 822500, driver_percentage: 14, drivers_count: 1, multiple_drivers: false,
            fuel_amount_ars: 0, expense_amount_ars: 0,
          },
        },
      },
    })

    // La tabla no muestra el flete de facturación: solo se ve al editar el tramo.
    expect(wrapper.find('td[title="USD 4.700,00"]').exists()).toBe(false)
    expect(wrapper.find('td[title="ARS 5.875.000,00"]').exists()).toBe(true)

    const editLeg = wrapper.findAll('button').find((button) => button.text() === 'Editar')
    await editLeg.trigger('click')
    await nextTick()

    const legForm = wrapper.get('form[action="/mantenimiento/viajes/operativos/42/tramos/9"]')
    expect(legForm.get('select[name="moneda"]').element.value).toBe('USD')
    expect(legForm.get('select[name="moneda_liquidacion"]').element.value).toBe('ARS')
    expect(legForm.get('input[name="cotizacion_liquidacion"]').attributes('disabled')).toBeDefined()
    expect(legForm.get('input[name="flete_liquidacion"]').element.value).toBe('5875000')
    wrapper.unmount()
  })

  it('ofrece cargar la liquidación en pesos al agregar un tramo', async () => {
    const wrapper = mount(TripOperationsDetailPage, {
      props: {
        data: {
          ...data,
          canStart: false,
          canCancel: false,
          canEdit: true,
          canAddLegs: true,
          links: { ...data.links, legs: '/mantenimiento/viajes/operativos/42/tramos' },
          trip: { ...data.trip, estado: 'EN_CURSO', estado_label: 'En curso' },
          options: { expenseCategories: [], paymentMethods: [], currencies: [{ code: 'ARS', label: 'Peso argentino' }, { code: 'USD', label: 'Dólar' }] },
        },
      },
    })

    const legForm = wrapper.get('form[action="/mantenimiento/viajes/operativos/42/tramos"]')
    await legForm.get('select[name="moneda"]').setValue('USD')
    await nextTick()

    // Mientras la liquidación sigue a la facturación, comparte su cotización.
    expect(legForm.get('select[name="moneda_liquidacion"]').element.value).toBe('USD')
    expect(legForm.get('input[name="cotizacion_liquidacion"]').attributes('disabled')).toBeDefined()

    const pesos = legForm.findAll('button').find((button) => button.text() === 'En pesos')
    await pesos.trigger('click')
    await nextTick()

    expect(legForm.get('select[name="moneda_liquidacion"]').element.value).toBe('ARS')
    expect(legForm.get('input[name="cotizacion_liquidacion"]').attributes('disabled')).toBeDefined()
    await legForm.get('input[name="modo_liquidacion"][value="MONTO_ACORDADO"]').setValue(true)
    expect(legForm.text()).toContain('Monto acordado (ARS, opcional)')
    wrapper.unmount()
  })
})
