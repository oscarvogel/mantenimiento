import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import TripDriverSettlementPage from '../../src/pages/operations/TripDriverSettlementPage.vue'
import TripLegLiquidationBasisFields from '../../src/pages/operations/components/TripLegLiquidationBasisFields.vue'
import TripOperationsDetailPage from '../../src/pages/operations/TripOperationsDetailPage.vue'
import { resolveOperationPage } from '../../src/pages/operations/index.js'
import { liquidationBasisAmount, liquidationBasisFromServer } from '../../src/composables/useTripLegMoney.js'

if (typeof HTMLDialogElement !== 'undefined' && !HTMLDialogElement.prototype.showModal) {
  HTMLDialogElement.prototype.showModal = vi.fn(function () { this.open = true })
}

/** Lo que devuelve DriverSettlementStatement: solo datos del chofer. */
const statement = (overrides = {}) => ({
  status: 'DEFINITIVA',
  status_label: 'El viaje está cerrado: los importes son los definitivos.',
  company: { name: 'Transporte Mariela' },
  trip: { id: 42, numero: 7, estado: 'FINALIZADO', estado_label: 'Finalizado', sucursal: 'Argentina', fecha_salida_real: '2026-11-01', fecha_regreso_real: '2026-11-05' },
  driver: { id: 9, label: 'Pérez, Juan' },
  unit: 'AA111AA · T01',
  percentage: 14,
  legs: [
    { orden: 1, origen: 'Rosario', destino: 'Córdoba', modo: 'POR_TONELADA', modo_label: 'Por toneladas', toneladas: 30, precio_unitario: 45000, moneda: 'ARS', importe: 1350000, cotizacion: null, importe_ars: 1350000, comision_ars: 189000 },
    { orden: 2, origen: 'Córdoba', destino: 'Uruguaiana', modo: 'MONTO_ACORDADO', modo_label: 'Monto acordado', toneladas: null, precio_unitario: null, moneda: 'USD', importe: 1000, cotizacion: 1250, importe_ars: 1250000, comision_ars: 175000 },
  ],
  otherEarnings: [{ fecha: '2026-11-02', concepto: 'Estadía', importe: 20000, moneda: 'ARS', cotizacion: null, importe_ars: 20000 }],
  driverExpenses: [{ fecha: '2026-11-03', concepto: 'Peaje', comprobante: 'T-1', importe: 5000, moneda: 'ARS', cotizacion: null, importe_ars: 5000 }],
  cashExpenses: [],
  cashMovements: [{ fecha: '2026-11-01', tipo: 'ANTICIPO', tipo_label: 'Anticipo', importe: 100000, moneda: 'ARS', cotizacion: null, importe_ars: 100000 }],
  totals: {
    base_ars: 2600000, commission_ars: 364000, other_earnings_ars: 20000, driver_expenses_ars: 5000, cash_expenses_ars: 0,
    advances_ars: 100000, returns_ars: 0, account_balance_ars: -95000, total_ars: 289000,
    cash_delivered_ars: 100000, cash_returned_ars: 0, cash_balance_ars: 100000,
  },
  available: true,
  pending: null,
  links: { back: '/mantenimiento/viajes/operativos/42', pdf: '/mantenimiento/viajes/operativos/42/liquidacion-chofer.pdf' },
  ...overrides,
})

describe('liquidación del chofer', () => {
  it('se registra como página propia', () => {
    expect(resolveOperationPage('trip-driver-settlement')).toBe(TripDriverSettlementPage)
  })

  it('muestra la base por toneladas y por monto acordado, la comisión y el saldo', () => {
    const wrapper = mount(TripDriverSettlementPage, { props: { data: statement() } })
    const legs = wrapper.get('[data-testid="driver-settlement-legs"]').text()

    expect(legs).toContain('Por toneladas')
    expect(legs).toContain('30,000 t × ARS 45.000,00')
    expect(legs).toContain('Monto acordado')
    expect(legs).toContain('Importe global')
    expect(legs).toContain('USD 1.000,00')
    expect(legs).toContain('Cotización pactada 1.250,0000')
    expect(legs).toContain('ARS 189.000,00')
    expect(wrapper.get('[data-testid="driver-settlement-total"]').text()).toBe('ARS 289.000,00')
    expect(wrapper.get('[data-testid="driver-settlement-pdf"]').attributes('href')).toBe('/mantenimiento/viajes/operativos/42/liquidacion-chofer.pdf')
    expect(wrapper.text()).not.toContain('BORRADOR')
    expect(wrapper.get('[data-testid="driver-settlement-status"]').text()).toContain('LIQUIDACIÓN DEFINITIVA')
    wrapper.unmount()
  })

  it('no menciona facturación, margen ni rentabilidad', () => {
    const wrapper = mount(TripDriverSettlementPage, { props: { data: statement() } })
    const text = wrapper.text().toLowerCase()

    for (const word of ['factura', 'margen', 'rentabilidad', 'cliente', 'diferencia']) {
      expect(text).not.toContain(word)
    }
    wrapper.unmount()
  })

  it('marca la vista previa como borrador antes del cierre', () => {
    const wrapper = mount(TripDriverSettlementPage, {
      props: { data: statement({ status: 'BORRADOR', status_label: 'El viaje todavía no está cerrado y los importes pueden cambiar.' }) },
    })

    expect(wrapper.get('[data-testid="driver-settlement-status"]').text()).toContain('BORRADOR')
    expect(wrapper.text()).toContain('Saldo final (borrador)')
    expect(wrapper.get('[data-testid="driver-settlement-pdf"]').text()).toContain('borrador')
    wrapper.unmount()
  })

  it('explica qué falta cuando no se puede calcular', () => {
    const wrapper = mount(TripDriverSettlementPage, {
      props: { data: statement({ available: false, pending: 'El chofer no tiene un porcentaje de liquidación definido.', percentage: null }) },
    })

    expect(wrapper.get('[data-testid="driver-settlement-pending"]').text()).toContain('porcentaje')
    expect(wrapper.find('[data-testid="driver-settlement-summary"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="driver-settlement-pdf"]').exists()).toBe(false)
    wrapper.unmount()
  })
})

describe('acuerdo con el chofer en el tramo', () => {
  it('pide elegir la modalidad y calcula toneladas por precio sin pedir el monto', async () => {
    const wrapper = mount(TripLegLiquidationBasisFields, {
      props: { modelValue: { modo: '', toneladas: '', precio: '', monto: '' }, currency: 'ARS', idPrefix: 'test', namePrefix: 'tramos[0]' },
    })

    expect(wrapper.text()).toContain('Elegí cómo se liquida este tramo al chofer.')
    expect(wrapper.findAll('input[type="radio"]').every((input) => input.attributes('required') !== undefined)).toBe(true)

    await wrapper.setProps({ modelValue: { modo: 'POR_TONELADA', toneladas: '30', precio: '45000', monto: '' } })
    expect(wrapper.find('input[name="tramos[0][flete_liquidacion]"]').exists()).toBe(false)
    expect(wrapper.get('input[name="tramos[0][toneladas_liquidacion]"]').element.value).toBe('30')
    expect(wrapper.get('[data-testid="liquidation-basis-total"]').text()).toBe('ARS 1.350.000,00')

    await wrapper.setProps({ modelValue: { modo: 'MONTO_ACORDADO', toneladas: '', precio: '', monto: '1250000' } })
    expect(wrapper.find('input[name="tramos[0][toneladas_liquidacion]"]').exists()).toBe(false)
    expect(wrapper.get('input[name="tramos[0][flete_liquidacion]"]').element.value).toBe('1250000')
    wrapper.unmount()
  })

  it('emite la modalidad elegida', async () => {
    const wrapper = mount(TripLegLiquidationBasisFields, {
      props: { modelValue: { modo: '', toneladas: '', precio: '', monto: '' }, idPrefix: 'emit' },
    })
    await wrapper.get('input[value="POR_TONELADA"]').setValue(true)

    expect(wrapper.emitted('update:modelValue')[0][0].modo).toBe('POR_TONELADA')
    wrapper.unmount()
  })

  it('no inventa cantidades ni precios para un monto acordado', () => {
    expect(liquidationBasisAmount({ modo: 'POR_TONELADA', toneladas: '30', precio: '45000' })).toBe(1350000)
    expect(liquidationBasisAmount({ modo: 'POR_TONELADA', toneladas: '30', precio: '' })).toBeNull()
    expect(liquidationBasisAmount({ modo: 'MONTO_ACORDADO', monto: '1250000' })).toBe(1250000)
    expect(liquidationBasisAmount({ modo: '', monto: '1250000' })).toBeNull()

    const legacy = liquidationBasisFromServer({ flete_liquidacion: 800000 })
    expect(legacy).toEqual({ modo: 'MONTO_ACORDADO', toneladas: '', precio: '', monto: 800000 })
    const perTonne = liquidationBasisFromServer({ modo_liquidacion: 'POR_TONELADA', toneladas_liquidacion: 30, precio_unitario_liquidacion: 45000, flete_liquidacion: 1350000 })
    expect(perTonne).toEqual({ modo: 'POR_TONELADA', toneladas: 30, precio: 45000, monto: '' })
  })
})

describe('detalle administrativo del viaje', () => {
  const adminData = (overrides = {}) => ({
    links: { detail: '/mantenimiento/viajes/operativos/42', driverSettlement: '/mantenimiento/viajes/operativos/42/liquidacion-chofer' },
    csrf: { name: 'csrf_test_name', hash: 'abc123' },
    trip: { id: 42, numero: 7, estado: 'EN_CURSO', estado_label: 'En curso', sucursal: 'Argentina' },
    assignments: [], stops: [], legs: [], fuel: [], expenses: [], cashMovements: [], otherEarnings: [], logbook: [],
    totals: {},
    ...overrides,
  })

  it('ofrece ver la liquidación del chofer a quien tiene el permiso', () => {
    const wrapper = mount(TripOperationsDetailPage, { props: { data: adminData({ canViewDriverSettlement: true }) } })

    const link = wrapper.get('[data-testid="driver-settlement-link"]')
    expect(link.text()).toBe('Ver liquidación del chofer')
    expect(link.attributes('href')).toBe('/mantenimiento/viajes/operativos/42/liquidacion-chofer')
    wrapper.unmount()
  })

  it('no muestra el acceso sin permiso', () => {
    const wrapper = mount(TripOperationsDetailPage, { props: { data: adminData({ canViewDriverSettlement: false }) } })

    expect(wrapper.find('[data-testid="driver-settlement-link"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('muestra la modalidad de liquidación de cada tramo y precarga la edición', async () => {
    const wrapper = mount(TripOperationsDetailPage, {
      props: {
        data: adminData({
          canEdit: true,
          legs: [{
            id: 5, orden: 1, origen: 'Rosario', destino: 'Córdoba', toneladas: 30, km: null, moneda: 'ARS', cotizacion: 1,
            moneda_liquidacion: 'ARS', cotizacion_liquidacion: 1, flete_facturacion: 2000000, flete_facturacion_ars: 2000000,
            flete_liquidacion: 1350000, flete_liquidacion_ars: 1350000, modo_liquidacion: 'POR_TONELADA', modo_liquidacion_label: 'Por toneladas',
            toneladas_liquidacion: 30, precio_unitario_liquidacion: 45000, commission_ars: 189000, assigned_costs_ars: 0, margin_ars: null,
          }],
        }),
      },
    })

    expect(wrapper.get('[data-testid="leg-basis-mode"]').text()).toBe('Por toneladas')
    await wrapper.findAll('button').find((button) => button.text() === 'Editar').trigger('click')
    await nextTick()
    const basis = wrapper.get('#edit-leg-basis-POR_TONELADA')
    expect(basis.element.checked).toBe(true)
    expect(wrapper.get('input[name="precio_unitario_liquidacion"]').element.value).toBe('45000')
    wrapper.unmount()
  })
})
