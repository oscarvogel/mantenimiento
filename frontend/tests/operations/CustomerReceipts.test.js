import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import CustomerReceiptsPage from '../../src/pages/operations/CustomerReceiptsPage.vue'
import CustomerReceiptFormPage from '../../src/pages/operations/CustomerReceiptFormPage.vue'
import CustomerReceiptDetailPage from '../../src/pages/operations/CustomerReceiptDetailPage.vue'
import CustomerAccountDetailPage from '../../src/pages/operations/CustomerAccountDetailPage.vue'
import { operationPageComponents } from '../../src/pages/operations/index.js'

if (typeof HTMLDialogElement !== 'undefined' && !HTMLDialogElement.prototype.showModal) {
  HTMLDialogElement.prototype.showModal = vi.fn(function () { this.open = true })
  HTMLDialogElement.prototype.close = vi.fn(function () { this.open = false })
}

const links = {
  receipts: '/mantenimiento/clientes/recibos',
  receiptsBase: '/mantenimiento/clientes/recibos/',
  newReceiptBase: '/mantenimiento/clientes/recibos/nuevo/',
  accounts: '/mantenimiento/clientes/cuenta-corriente',
  accountsBase: '/mantenimiento/clientes/cuenta-corriente/',
  tripsBase: '/mantenimiento/viajes/operativos',
}
const csrf = { name: 'csrf_test_name', hash: 'abc' }

const receipt = (overrides = {}) => ({
  id: 9, numero_visible: 'R-000009', estado: 'CONFIRMADO', estado_label: 'Confirmado',
  fecha_emision: '2026-11-20', fecha_recepcion: '2026-11-20', usuario: 'Ana',
  total_ars: 2250000, acreditado_ars: 750000, pendiente_ars: 1500000,
  imputado_ars: 500000, disponible_ars: 2500000 - 1500000,
  saldo_anterior_ars: 5000000, saldo_posterior_ars: 4250000, saldo_posterior_previsto_ars: 2750000,
  anulado_por: null, anulado_at: null, motivo_anulacion: null, observaciones: null,
  ...overrides,
})

const listData = (overrides = {}) => ({
  csrf, links, filters: {}, statuses: [{ value: 'CONFIRMADO', label: 'Confirmado' }],
  media: [{ value: 'CHEQUE', label: 'Cheque físico' }],
  rows: [{
    id: 9, numero_visible: 'R-000009', cliente: 'Grupo Pluma', identificacion_fiscal: '30-1',
    sucursal: 'Argentina', fecha_emision: '2026-11-20', fecha_recepcion: '2026-11-20',
    estado: 'CONFIRMADO', estado_label: 'Confirmado', total_ars: 2250000, acreditado_ars: 750000,
    pendiente_ars: 1500000, medios: [{ value: 'EFECTIVO', label: 'Efectivo' }, { value: 'CHEQUE', label: 'Cheque físico' }],
    medios_pendientes: 1,
  }],
  totals: { total_ars: 2250000, acreditado_ars: 750000, pendiente_ars: 1500000 },
  pagination: { page: 1, totalPages: 2, total: 11, perPage: 10, nextUrl: '/clientes/recibos?page=2' },
  canRegister: true, canConfirm: true, canCancel: true, canImpute: true, canPrint: true,
  ...overrides,
})

const formData = (overrides = {}) => ({
  csrf, links,
  customer: { id: 4, nombre: 'Grupo Pluma', identificacion_fiscal: '30-11111111-1' },
  branches: [{ id: 2, nombre: 'Argentina' }], defaultBranchId: 2, defaultSeries: 'R', today: '2026-11-25',
  pendingDocuments: [
    { id: 31, documento: 'Memo autorizado M-77', documento_fecha: '2026-11-10', pendiente_ars: 300000, importe_ars: 1000000, estado_cobranza: 'PARCIAL', estado_cobranza_label: 'Parcialmente cobrado', modalidad_label: 'Memo autorizado', recibos: [] },
    { id: 32, documento: 'Cierre comercial aprobado LI-000002', documento_fecha: '2026-11-12', pendiente_ars: 700000, importe_ars: 700000, estado_cobranza: 'PENDIENTE', estado_cobranza_label: 'Pendiente', modalidad_label: 'Cierre comercial aprobado', recibos: [] },
  ],
  advances: [], summary: { saldo_ars: 5000000 },
  currencies: ['ARS', 'USD', 'BRL'], currentRates: { USD: 1500, BRL: 280 },
  canConfirm: true, canImpute: true, returnTo: '/clientes/cuenta-corriente/4',
  ...overrides,
})

const detailData = (overrides = {}) => ({
  csrf, links,
  receipt: receipt(),
  company: { razon_social: 'Transporte Sur SA', nombre_fantasia: 'Transporte Sur', cuit: '30-71234567-8' },
  customer: { id: 4, nombre: 'Grupo Pluma', identificacion_fiscal: '30-11111111-1' },
  branch: { id: 2, nombre: 'Argentina' },
  instruments: [
    { id: 51, medio: 'EFECTIVO', medio_label: 'Efectivo', moneda: 'ARS', importe: 500000, cotizacion: 1, cotizacion_fecha: '2026-11-20', cotizacion_fuente: 'PESOS', importe_ars: 500000, acredita_al_confirmar: true, estado_acreditacion: 'ACREDITADO', estado_acreditacion_label: 'Acreditado', banco: null, referencia: null, cheque: null, observaciones: null, fecha_transferencia: null },
    { id: 52, medio: 'CHEQUE', medio_label: 'Cheque físico', moneda: 'ARS', importe: 1500000, cotizacion: 1, cotizacion_fecha: '2026-11-20', cotizacion_fuente: 'PESOS', importe_ars: 1500000, acredita_al_confirmar: false, estado_acreditacion: 'PENDIENTE', estado_acreditacion_label: 'Pendiente de acreditación', banco: 'Galicia', referencia: null, cheque: { numero: '000123', banco: 'Galicia', librador: 'Grupo Pluma', fecha_pago: '2026-12-01', estado_label: 'En cartera' }, observaciones: null, fecha_transferencia: null },
  ],
  imputations: [{ id: 61, cargo_id: 31, documento: 'Memo autorizado M-77', documento_fecha: '2026-11-10', estado: 'VIGENTE', estado_label: 'Vigente', importe_ars: 500000 }],
  attachments: [{ id: 71, nombre: 'transferencia.pdf', mime_type: 'application/pdf', tamanio: 2048, descripcion: 'Comprobante' }],
  pendingDocuments: [{ id: 31, documento: 'Memo autorizado M-77', documento_fecha: '2026-11-10', pendiente_ars: 500000, estado_cobranza: 'PARCIAL', estado_cobranza_label: 'Parcialmente cobrado' }],
  advances: [], pendingInstruments: [],
  history: [{ accion: 'cliente_recibo.confirmado', accion_label: 'Recibo confirmado y numerado', fecha: '2026-11-20 10:00:00', usuario: 'Ana', motivo: null }],
  canConfirm: true, canCancel: true, canImpute: true, canPrint: true, canRegister: true,
  returnTo: '/clientes/recibos/9',
  ...overrides,
})

describe('recibos de cobranza (#536)', () => {
  it('registra las tres pantallas nuevas en el catálogo', () => {
    expect(operationPageComponents['customer-receipts']).toBe(CustomerReceiptsPage)
    expect(operationPageComponents['customer-receipt-form']).toBe(CustomerReceiptFormPage)
    expect(operationPageComponents['customer-receipt-detail']).toBe(CustomerReceiptDetailPage)
  })

  it('lista recibos con totales, filtros, medios y paginación', () => {
    const wrapper = mount(CustomerReceiptsPage, { props: { data: listData() }, attachTo: document.body })

    expect(wrapper.get('[data-testid="receipts-filters"]').exists()).toBe(true)
    const table = wrapper.get('[data-testid="receipts-table"]').text()
    expect(table).toContain('R-000009')
    expect(table).toContain('Grupo Pluma')
    expect(table).toContain('Efectivo + Cheque físico')
    expect(table).toContain('1 pendiente(s)')
    expect(wrapper.text()).toContain('ARS 2.250.000,00')
    expect(wrapper.find('[aria-label="Paginación"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('permite anular desde el listado con motivo obligatorio', async () => {
    const wrapper = mount(CustomerReceiptsPage, { props: { data: listData() }, attachTo: document.body })
    await wrapper.findAll('button').find((button) => button.text() === 'Anular').trigger('click')
    await nextTick()

    const form = wrapper.get('[data-testid="receipt-annul-form"]')
    expect(form.attributes('action')).toBe('/mantenimiento/clientes/recibos/9/anular')
    expect(form.get('textarea[name="motivo"]').attributes('required')).toBeDefined()
    wrapper.unmount()
  })

  it('construye un recibo multimoneda y previsualiza total, acreditación e imputación', async () => {
    const wrapper = mount(CustomerReceiptFormPage, { props: { data: formData() }, attachTo: document.body })

    await wrapper.get('[data-testid="receipt-line-0"] input[name="medios[0][importe]"]').setValue('500000')
    expect(wrapper.get('[data-testid="receipt-total"]').text()).toBe('ARS 500.000,00')
    expect(wrapper.get('[data-testid="receipt-credited"]').text()).toBe('ARS 500.000,00')

    await wrapper.get('[data-testid="add-receipt-line"]').trigger('click')
    const second = wrapper.get('[data-testid="receipt-line-1"]')
    await second.get('[data-testid="receipt-line-kind"]').setValue('CHEQUE')
    await second.get('select[name="medios[1][moneda]"]').setValue('USD')
    expect(second.get('input[name="medios[1][cotizacion]"]').element.value).toBe('1500')
    await second.get('input[name="medios[1][importe]"]').setValue('500')

    expect(wrapper.get('[data-testid="receipt-total"]').text()).toBe('ARS 1.250.000,00')
    expect(wrapper.get('[data-testid="receipt-credited"]').text()).toBe('ARS 500.000,00')
    expect(wrapper.get('[data-testid="receipt-forecast"]').text()).toContain('ARS 4.500.000,00')
    expect(wrapper.text()).toContain('Queda pendiente de acreditación')

    const imputation = wrapper.get('input[name="imputaciones[0][importe]"]')
    await imputation.setValue('300000')
    expect(wrapper.get('[data-testid="receipt-imputation-warning"]').text()).toContain('sin imputar')
    wrapper.unmount()
  })

  it('marca sobreimputación y bloquea el guardado', async () => {
    const wrapper = mount(CustomerReceiptFormPage, { props: { data: formData() }, attachTo: document.body })
    await wrapper.get('input[name="medios[0][importe]"]').setValue('100')
    await wrapper.get('input[name="imputaciones[0][importe]"]').setValue('500')

    expect(wrapper.get('[data-testid="receipt-imputation-warning"]').text()).toContain('supera el total del recibo')
    expect(wrapper.get('[data-testid="receipt-save-confirm"]').attributes('disabled')).toBeDefined()
    wrapper.unmount()
  })

  it('muestra el detalle con acreditación de cheque, imputación y anulación', async () => {
    const wrapper = mount(CustomerReceiptDetailPage, { props: { data: detailData() }, attachTo: document.body })

    expect(wrapper.get('[data-testid="receipt-detail-total"]').text()).toBe('ARS 2.250.000,00')
    expect(wrapper.get('[data-testid="receipt-instrument-51"]').text()).toContain('Acreditado')
    const cheque = wrapper.get('[data-testid="receipt-instrument-52"]')
    expect(cheque.text()).toContain('Pendiente de acreditación')
    expect(cheque.get('[data-testid="accredit-52"]').element.closest('form').getAttribute('action')).toBe('/mantenimiento/clientes/recibos/9/medios/52/acreditar')
    expect(wrapper.get('[data-testid="receipt-impute-form"]').attributes('action')).toBe('/mantenimiento/clientes/recibos/9/imputar')
    expect(wrapper.get('a[href="/mantenimiento/clientes/recibos/9/imprimir"]').exists()).toBe(true)
    expect(wrapper.get('[data-testid="receipt-attachment-form"]').exists()).toBe(true)
    expect(wrapper.get('[data-testid="receipt-history"]').text()).toContain('Recibo confirmado y numerado')

    await wrapper.get('[data-testid="receipt-annul-open"]').trigger('click')
    await nextTick()
    expect(wrapper.get('[data-testid="receipt-annul-detail-form"]').attributes('action')).toBe('/mantenimiento/clientes/recibos/9/anular')
    wrapper.unmount()
  })

  it('muestra el recibo anulado sin ofrecer anulación otra vez', () => {
    const wrapper = mount(CustomerReceiptDetailPage, {
      props: { data: detailData({ receipt: receipt({ estado: 'ANULADO', estado_label: 'Anulado', motivo_anulacion: 'Devolución', anulado_por: 'Ana', anulado_at: '2026-11-25' }), canCancel: true }) },
    })
    expect(wrapper.get('[data-testid="receipt-cancelled"]').text()).toContain('Devolución')
    expect(wrapper.find('[data-testid="receipt-annul-open"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('integra la cuenta corriente con documentos pendientes, anticipos y recibos', () => {
    const wrapper = mount(CustomerAccountDetailPage, {
      props: {
        data: {
          csrf,
          links: { ...links, newReceiptBase: '/mantenimiento/clientes/recibos/nuevo/' },
          customer: { id: 4, nombre: 'Grupo Pluma', identificacion_fiscal: '30-9' },
          totals: { debe_ars: 5000000, haber_ars: 500000, saldo_ars: 4500000 },
          movements: [],
          canFormalize: true,
          canRegisterReceipt: true,
          canImpute: true,
          canPrintReceipt: true,
          canCancelReceipt: true,
          hasOpeningBalance: false,
          currencies: ['ARS'],
          currentRates: {},
          summary: { saldo_ars: 4500000, saldo_deudor_ars: 4500000, saldo_favor_ars: 0, total_cobrado_ars: 500000, anticipos_disponibles_ars: 250000, medios_pendientes_ars: 1500000 },
          pendingDocuments: [{ id: 31, documento: 'Memo autorizado M-77', documento_fecha: '2026-11-10', pendiente_ars: 4500000, importe_ars: 5000000, estado_cobranza: 'PARCIAL', estado_cobranza_label: 'Parcialmente cobrado', modalidad_label: 'Memo autorizado', recibos: [{ numero_visible: 'R-000009' }] }],
          receipts: [{ id: 9, numero_visible: 'R-000009', fecha_emision: '2026-11-20', total_ars: 500000, acreditado_ars: 500000, disponible_ars: 250000, estado: 'CONFIRMADO' }],
          advances: [{ id: 9, numero_visible: 'R-000009', disponible_ars: 250000 }],
          pendingInstruments: [{ id: 52, medio_label: 'Cheque físico', recibo_numero: 'R-000009', banco: 'Galicia', referencia: '000123', fecha: '2026-12-01', importe_ars: 1500000, recibo_id: 9 }],
        },
      },
      attachTo: document.body,
    })

    expect(wrapper.get('[data-testid="new-receipt"]').attributes('href')).toBe('/mantenimiento/clientes/recibos/nuevo/4')
    expect(wrapper.get('[data-testid="collection-collected"]').text()).toBe('ARS 500.000,00')
    expect(wrapper.get('[data-testid="pending-documents"]').text()).toContain('Cancelado por: R-000009')
    expect(wrapper.get('[data-testid="recent-receipts"]').text()).toContain('R-000009')
    expect(wrapper.get('[data-testid="pending-instruments"]').text()).toContain('Cheque físico')

    return wrapper.get('[data-testid="open-impute-advance"]').trigger('click').then(async () => {
      await nextTick()
      expect(wrapper.get('[data-testid="advance-impute-form"]').attributes('action')).toBe('/mantenimiento/clientes/recibos/9/imputar')
      wrapper.unmount()
    })
  })

  it('anula un recibo desde la cuenta corriente con motivo', async () => {
    const wrapper = mount(CustomerAccountDetailPage, {
      props: {
        data: {
          csrf,
          links,
          customer: { id: 4, nombre: 'Grupo Pluma' },
          totals: { debe_ars: 0, haber_ars: 0, saldo_ars: 0 },
          movements: [],
          canCancelReceipt: true,
          receipts: [{ id: 9, numero_visible: 'R-000009', fecha_emision: '2026-11-20', total_ars: 500000, acreditado_ars: 500000, disponible_ars: 0, estado: 'CONFIRMADO' }],
        },
      },
      attachTo: document.body,
    })
    await wrapper.findAll('button').find((button) => button.text() === 'Anular').trigger('click')
    await nextTick()
    const form = wrapper.get('[data-testid="account-receipt-annul-form"]')
    expect(form.attributes('action')).toBe('/mantenimiento/clientes/recibos/9/anular')
    expect(form.get('textarea[name="motivo"]').attributes('required')).toBeDefined()
    wrapper.unmount()
  })
})
