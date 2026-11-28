import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import TripCommercialClosingPage from '../../src/pages/operations/TripCommercialClosingPage.vue'
import CustomerAccountsPage from '../../src/pages/operations/CustomerAccountsPage.vue'
import CustomerAccountDetailPage from '../../src/pages/operations/CustomerAccountDetailPage.vue'
import { operationPageComponents } from '../../src/pages/operations/index.js'

if (typeof HTMLDialogElement !== 'undefined' && !HTMLDialogElement.prototype.showModal) {
  HTMLDialogElement.prototype.showModal = vi.fn(function () { this.open = true })
  HTMLDialogElement.prototype.close = vi.fn(function () { this.open = false })
}

const links = {
  queue: '/mantenimiento/viajes/cierre-comercial',
  accounts: '/mantenimiento/clientes/cuenta-corriente',
  legsBase: '/mantenimiento/viajes/cierre-comercial/tramos/',
  chargesBase: '/mantenimiento/clientes/cuenta-corriente/cargos/',
  tripsBase: '/mantenimiento/viajes/operativos',
}

const pendingLeg = {
  tramo_id: 9, viaje_id: 16, numero: 16, orden: 1, origen: 'Umuarama', destino: 'Santiago',
  cliente_id: 4, cliente: 'Grupo Pluma', sucursal: 'Argentina', fecha_regreso_real: '2026-11-02 00:00:00',
  moneda: 'USD', flete_estimado: 2800, cotizacion_estimada: 1530, flete_estimado_ars: 4284000,
  estado_comercial: 'PENDIENTE', cargo: null, motivo_no_cobrable: null,
}

const queueData = (overrides = {}) => ({
  csrf: { name: 'csrf_test_name', hash: 'abc' },
  links,
  returnTo: '/viajes/cierre-comercial?estado=PENDIENTE',
  status: 'PENDIENTE',
  rows: [pendingLeg],
  counts: { PENDIENTE: 1, SIN_CLIENTE: 2, FORMALIZADO: 3, NO_COBRABLE: 0 },
  currentRates: { USD: 1578 },
  currencies: ['ARS', 'USD', 'BRL'],
  modalities: [
    { value: 'MEMO_AUTORIZADO', label: 'Memo autorizado' },
    { value: 'FACTURA_EMITIDA', label: 'Factura emitida' },
    { value: 'CIERRE_COMERCIAL_APROBADO', label: 'Cierre comercial aprobado' },
  ],
  invoiceTypes: ['FACTURA_A', 'FACTURA_B'],
  canFormalize: true,
  canOperateTrips: true,
  ...overrides,
})

describe('cierre comercial', () => {
  it('registra las tres pantallas en el catálogo de páginas', () => {
    expect(operationPageComponents['trips-commercial-closing']).toBe(TripCommercialClosingPage)
    expect(operationPageComponents['customer-accounts']).toBe(CustomerAccountsPage)
    expect(operationPageComponents['customer-account-detail']).toBe(CustomerAccountDetailPage)
  })

  it('muestra los tramos pendientes con la estimación del viaje y los contadores', () => {
    const wrapper = mount(TripCommercialClosingPage, { props: { data: queueData() } })

    expect(wrapper.text()).toContain('Cerrar el viaje no genera deuda')
    expect(wrapper.get('[data-testid="closing-tabs"]').text()).toContain('Sin cliente2')
    const row = wrapper.get('[data-testid="closing-row-9"]')
    expect(row.text()).toContain('Grupo Pluma')
    expect(row.text()).toContain('US$\u00a02.800,00 · est. $\u00a04.284.000,00')
    wrapper.unmount()
  })

  it('formaliza con la cotización vigente sugerida y muestra el cargo en ARS', async () => {
    const wrapper = mount(TripCommercialClosingPage, { props: { data: queueData() }, attachTo: document.body })
    await wrapper.findAll('button').find((button) => button.text() === 'Formalizar').trigger('click')
    await nextTick()

    const form = wrapper.get('[data-testid="formalize-form"]')
    expect(form.attributes('action')).toBe('/mantenimiento/viajes/cierre-comercial/tramos/9/formalizar')
    expect(form.get('input[name="volver"]').element.value).toBe('/viajes/cierre-comercial?estado=PENDIENTE')
    expect(form.get('input[name="cotizacion"]').element.value).toBe('1578')
    expect(form.get('input[name="importe"]').element.value).toBe('2800')
    // 2.800 × 1.578 = 4.418.400; la estimación era 4.284.000.
    expect(wrapper.get('[data-testid="formalize-preview"]').text()).toContain('$\u00a04.418.400,00')
    expect(wrapper.get('[data-testid="formalize-preview"]').text()).toContain('+$\u00a0134.400,00')

    await form.get('select[name="modalidad"]').setValue('FACTURA_EMITIDA')
    expect(form.find('select[name="documento_tipo"]').exists()).toBe(true)
    await form.get('select[name="modalidad"]').setValue('CIERRE_COMERCIAL_APROBADO')
    expect(form.find('input[name="documento_numero"]').exists()).toBe(false)

    await form.get('select[name="moneda"]').setValue('ARS')
    expect(form.find('input[name="cotizacion"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('muestra la diferencia negativa con el signo delante de la moneda', async () => {
    const wrapper = mount(TripCommercialClosingPage, { props: { data: queueData() }, attachTo: document.body })
    await wrapper.findAll('button').find((button) => button.text() === 'Formalizar').trigger('click')
    await nextTick()
    await wrapper.get('input[name="cotizacion"]').setValue('1490')
    expect(wrapper.get('[data-testid="formalize-preview"]').text()).toContain('−$\u00a0112.000,00')
    wrapper.unmount()
  })

  it('en celular muestra tarjetas con la acción a la vista', () => {
    const wrapper = mount(TripCommercialClosingPage, { props: { data: queueData() } })
    const card = wrapper.get('[data-testid="closing-card-9"]')
    expect(card.text()).toContain('Viaje #16 · Tramo 1')
    expect(card.findAll('button').map((button) => button.text())).toEqual(['Formalizar', 'No cobrable'])
    wrapper.unmount()
  })

  it('pide motivo para marcar no cobrable y no muestra acciones sin permiso', async () => {
    const wrapper = mount(TripCommercialClosingPage, { props: { data: queueData() }, attachTo: document.body })
    await wrapper.findAll('button').find((button) => button.text() === 'No cobrable').trigger('click')
    await nextTick()
    const form = wrapper.get('[data-testid="reason-form"]')
    expect(form.attributes('action')).toBe('/mantenimiento/viajes/cierre-comercial/tramos/9/no-cobrable')
    expect(form.get('textarea[name="motivo"]').attributes('required')).toBeDefined()
    wrapper.unmount()

    const readOnly = mount(TripCommercialClosingPage, { props: { data: queueData({ canFormalize: false }) } })
    expect(readOnly.findAll('button').some((button) => button.text() === 'Formalizar')).toBe(false)
    readOnly.unmount()
  })

  it('en sin cliente oculta el link para cargar el cliente si no puede operar viajes', () => {
    const noClient = { ...pendingLeg, cliente_id: null, cliente: null, estado_comercial: 'SIN_CLIENTE' }
    const operator = mount(TripCommercialClosingPage, { props: { data: queueData({ status: 'SIN_CLIENTE', rows: [noClient] }) } })
    expect(operator.text()).toContain('Cargar cliente en el viaje')
    operator.unmount()

    const readOnly = mount(TripCommercialClosingPage, {
      props: { data: queueData({ status: 'SIN_CLIENTE', rows: [noClient], canFormalize: false, canOperateTrips: false }) },
    })
    expect(readOnly.text()).not.toContain('Cargar cliente en el viaje')
    expect(readOnly.text()).not.toContain('Acciones')
    readOnly.unmount()
  })

  it('en formalizados muestra el cargo congelado y permite anularlo', async () => {
    const formalized = {
      ...pendingLeg,
      estado_comercial: 'FORMALIZADO',
      cargo: { id: 31, modalidad: 'MEMO_AUTORIZADO', modalidad_label: 'Memo autorizado', documento_numero: 'M-77', documento_fecha: '2026-11-10', importe: 2800, moneda: 'USD', cotizacion: 1578, importe_ars: 4418400 },
    }
    const wrapper = mount(TripCommercialClosingPage, { props: { data: queueData({ status: 'FORMALIZADO', rows: [formalized], returnTo: '/viajes/cierre-comercial?estado=FORMALIZADO' }) }, attachTo: document.body })
    const row = wrapper.get('[data-testid="closing-row-9"]')
    expect(row.text()).toContain('$\u00a04.418.400,00')
    expect(row.text()).toContain('US$\u00a02.800,00 × 1.578')
    expect(row.text()).toContain('M-77')

    await wrapper.findAll('button').find((button) => button.text() === 'Anular').trigger('click')
    await nextTick()
    expect(wrapper.get('[data-testid="reason-form"]').attributes('action')).toBe('/mantenimiento/clientes/cuenta-corriente/cargos/31/anular')
    wrapper.unmount()
  })
})

describe('cuenta corriente de clientes', () => {
  const balance = (moneda, saldoOriginal, saldoArs) => ({
    moneda,
    saldo_original: saldoOriginal,
    pendiente: saldoOriginal > 0 ? saldoOriginal : 0,
    a_favor: saldoOriginal < 0 ? -saldoOriginal : 0,
    saldo_ars: saldoArs,
  })

  const accountsData = (overrides = {}) => ({
    links,
    rows: [{
      id: 7,
      nombre: 'Lottus Transportes',
      identificacion_fiscal: '30-1',
      debe_ars: 4397000,
      haber_ars: 0,
      saldo_ars: 4397000,
      balances: { ARS: balance('ARS', 4397000, 4397000) },
      monedas: ['ARS'],
      ultimo_movimiento: '2026-11-10',
      movimientos: 2,
      valuation: { disponible: true, ars: 4397000, ars_parcial: null, monedas_sin_cotizacion: [], cotizaciones: {} },
    }],
    totals: {
      debe_ars: 4397000,
      haber_ars: 0,
      saldo_ars: 4397000,
      currencies: { ARS: balance('ARS', 4397000, 4397000) },
    },
    valuation: { disponible: true, ars: 4397000, ars_parcial: null, monedas_sin_cotizacion: [], cotizaciones: {} },
    pagination: { page: 1, perPage: 25, perPageOptions: [10, 25, 50, 100], total: 1, pages: 1, from: 1, to: 1 },
    customers: [{ id: 7, nombre: 'Lottus Transportes' }],
    currencies: ['ARS', 'USD', 'BRL'],
    canFormalize: true,
    canCollect: true,
    ...overrides,
  })

  it('lista el saldo por moneda con el consolidado histórico y la valorización actual', () => {
    const wrapper = mount(CustomerAccountsPage, { props: { data: accountsData() } })

    expect(wrapper.get('[data-testid="accounts-total"]').text()).toBe('ARS 4.397.000,00')
    expect(wrapper.get('[data-testid="accounts-valuation"]').text()).toBe('ARS 4.397.000,00')
    expect(wrapper.get('[data-testid="accounts-table"]').text()).toContain('a cobrar')
    expect(wrapper.get('a[href="/mantenimiento/clientes/cuenta-corriente/7"]').text()).toBe('Lottus Transportes')
    wrapper.unmount()
  })

  it('muestra una columna por moneda y el saldo a favor discriminado', () => {
    const data = accountsData({
      rows: [{
        id: 7,
        nombre: 'Lottus Transportes',
        identificacion_fiscal: '30-1',
        debe_ars: 4397000,
        haber_ars: 750000,
        saldo_ars: 3647000,
        balances: {
          ARS: balance('ARS', 4397000, 4397000),
          USD: balance('USD', 1800, 2700000),
          BRL: balance('BRL', -500, -140000),
        },
        monedas: ['ARS', 'USD', 'BRL'],
        ultimo_movimiento: '2026-11-10',
        movimientos: 4,
        valuation: { disponible: true, ars: 6960000, ars_parcial: null, monedas_sin_cotizacion: [], cotizaciones: {} },
      }],
      totals: {
        debe_ars: 4397000,
        haber_ars: 750000,
        saldo_ars: 3647000,
        currencies: {
          ARS: balance('ARS', 4397000, 4397000),
          USD: balance('USD', 1800, 2700000),
          BRL: balance('BRL', -500, -140000),
        },
      },
      valuation: { disponible: true, ars: 6960000, ars_parcial: null, monedas_sin_cotizacion: [], cotizaciones: {} },
    })
    const wrapper = mount(CustomerAccountsPage, { props: { data } })

    const table = wrapper.get('[data-testid="accounts-table"]').text()
    expect(table).toContain('USD 1.800,00')
    expect(table).toContain('BRL 500,00')
    expect(table).toContain('a favor del cliente')
    // El saldo a favor se discrimina por moneda.
    expect(wrapper.get('[data-testid="accounts-favor"]').text()).toContain('BRL 500,00')
    wrapper.unmount()
  })

  it('informa que la valorización no está disponible cuando falta una cotización', () => {
    const data = accountsData({
      valuation: { disponible: false, ars: null, ars_parcial: 4397000, monedas_sin_cotizacion: ['BRL'], cotizaciones: {} },
    })
    const wrapper = mount(CustomerAccountsPage, { props: { data } })

    expect(wrapper.get('[data-testid="accounts-valuation"]').text()).toBe('No disponible')
    expect(wrapper.text()).toContain('BRL')
    wrapper.unmount()
  })

  it('filtra por moneda y pagina preservando el filtro', () => {
    window.history.replaceState({}, '', '/mantenimiento/clientes/cuenta-corriente?moneda=USD')
    const data = accountsData({
      currency: 'USD',
      pagination: { page: 2, perPage: 10, perPageOptions: [10, 25, 50, 100], total: 35, pages: 4, from: 11, to: 20 },
    })
    const wrapper = mount(CustomerAccountsPage, { props: { data } })

    expect(wrapper.get('[data-testid="accounts-currency-filter"]').element.value).toBe('USD')
    const pagination = wrapper.get('[data-testid="accounts-pagination"]')
    expect(pagination.text()).toContain('11–20 de 35')
    // La paginación preserva el filtro de moneda.
    const hrefs = wrapper.get('[data-testid="accounts-pagination"]').findAll('a').map((link) => link.attributes('href'))
    expect(hrefs.length).toBeGreaterThan(0)
    expect(hrefs.every((href) => href.includes('moneda=USD'))).toBe(true)
    expect(hrefs.some((href) => href.includes('pagina=3'))).toBe(true)
    wrapper.unmount()
    window.history.replaceState({}, '', '/')
  })

  it('muestra el estado de cuenta con origen, anulados y saldo acumulado', async () => {
    const wrapper = mount(CustomerAccountDetailPage, {
      props: {
        data: {
          csrf: { name: 'csrf_test_name', hash: 'abc' },
          links: { ...links, openingBalance: '/mantenimiento/clientes/cuenta-corriente/4/saldo-inicial', paymentsBase: '/mantenimiento/clientes/cuenta-corriente/cobranzas/' },
          returnTo: '/clientes/cuenta-corriente/4',
          customer: { id: 4, nombre: 'Grupo Pluma', identificacion_fiscal: '30-9' },
          hasOpeningBalance: false,
          canFormalize: true,
          canCollect: true,
          currencies: ['ARS', 'USD'],
          currentRates: { USD: 1600 },
          totals: { debe_ars: 8590400, haber_ars: 4418400, saldo_ars: 4172000 },
          balances: { USD: balance('USD', 2800, 4172000) },
          valuation: { disponible: true, ars: 4172000, ars_parcial: null, monedas_sin_cotizacion: [], cotizaciones: {} },
          documents: [],
          payments: [],
          movements: [
            { id: 1, fecha: '2026-11-10', tipo: 'CARGO', tipo_label: 'Memo autorizado', descripcion: 'Viaje #16 · Tramo 1 · Memo autorizado M-77', cargo_id: 30, cargo_vigente: false, cargo_anulado: true, viaje_id: 16, moneda: 'USD', importe_original: 2800, cotizacion: 1578, cotizacion_fecha: '2026-11-10', cotizacion_fuente: 'MANUAL', debe_ars: 4418400, haber_ars: 0, saldo_ars: 4418400 },
            { id: 2, fecha: '2026-11-10', tipo: 'CARGO', tipo_label: 'Factura emitida', descripcion: 'Viaje #16 · Tramo 1 · Factura emitida 0003-1', cargo_id: 31, cargo_vigente: true, cargo_anulado: false, viaje_id: 16, moneda: 'USD', importe_original: 2800, cotizacion: 1490, cotizacion_fecha: '2026-11-10', cotizacion_fuente: 'MANUAL', debe_ars: 4172000, haber_ars: 0, saldo_ars: 8590400 },
            { id: 4, fecha: '2026-11-01', tipo: 'SALDO_INICIAL', tipo_label: 'Saldo inicial', descripcion: 'Saldo inicial Resumen ERP', cargo_id: 32, cargo_vigente: true, cargo_anulado: false, viaje_id: null, moneda: 'ARS', importe_original: 1, cotizacion: 1, cotizacion_fecha: '2026-11-01', cotizacion_fuente: 'PESOS', debe_ars: 1, haber_ars: 0, saldo_ars: 1 },
            { id: 3, fecha: '2026-11-17', tipo: 'ANULACION', tipo_label: 'Anulación', descripcion: 'Anulación del cargo #30', cargo_id: 30, cargo_vigente: false, cargo_anulado: true, viaje_id: 16, moneda: 'USD', debe_ars: 0, haber_ars: 4418400, saldo_ars: 4172000 },
          ],
        },
      },
      attachTo: document.body,
    })

    expect(wrapper.get('[data-testid="statement-balance"]').text()).toBe('ARS 4.172.000,00')
    const table = wrapper.get('[data-testid="statement-table"]').text()
    expect(table).toContain('USD 2.800,00 × 1.578 (informada, 10/11/2026)')
    expect(table).toContain('Anulado')
    // Solo el cargo vigente se puede anular (en la tabla de escritorio y en las tarjetas de celular).
    expect(wrapper.get('[data-testid="statement-table"]').findAll('button').filter((button) => button.text() === 'Anular')).toHaveLength(2)
    expect(wrapper.get('[data-testid="statement-cards"]').findAll('button').filter((button) => button.text() === 'Anular')).toHaveLength(2)
    // El saldo inicial muestra solo su referencia al lado del tipo.
    expect(wrapper.get('[data-testid="statement-table"]').text()).not.toContain('Saldo inicial Resumen ERP')
    expect(wrapper.get('[data-testid="statement-table"]').text()).toContain('Resumen ERP')
    // La descripción no repite el tipo del movimiento.
    expect(wrapper.get('[data-testid="statement-table"]').text()).toContain('Anulación del cargo #30')
    // La tarjeta por moneda muestra el saldo pendiente en la moneda original.
    expect(wrapper.get('[data-testid="balance-card-USD"]').text()).toContain('USD 2.800,00')
    expect(wrapper.get('[data-testid="statement-valuation"]').text()).toBe('ARS 4.172.000,00')

    await wrapper.get('[data-testid="open-opening-balance"]').trigger('click')
    await nextTick()
    const form = wrapper.get('[data-testid="opening-form"]')
    expect(form.attributes('action')).toBe('/mantenimiento/clientes/cuenta-corriente/4/saldo-inicial')
    await form.get('select[name="moneda"]').setValue('USD')
    expect(form.get('input[name="cotizacion"]').element.value).toBe('1600')
    await form.get('input[name="importe"]').setValue('100')
    await form.get('select[name="sentido"]').setValue('HABER')
    expect(wrapper.get('[data-testid="opening-preview"]').text()).toBe('Se registra un saldo a favor de ARS 160.000,00.')
    wrapper.unmount()
  })

  it('muestra los documentos con su estado de cobranza y los cobros con su diferencia de cambio', () => {
    const wrapper = mount(CustomerAccountDetailPage, {
      props: {
        data: {
          csrf: { name: 'csrf_test_name', hash: 'abc' },
          links: { ...links, openingBalance: '/mantenimiento/clientes/cuenta-corriente/4/saldo-inicial', paymentsBase: '/mantenimiento/clientes/cuenta-corriente/cobranzas/' },
          returnTo: '/clientes/cuenta-corriente/4',
          customer: { id: 4, nombre: 'Grupo Pluma' },
          canFormalize: true,
          canCollect: true,
          currencies: ['ARS', 'USD'],
          currentRates: { USD: 1600 },
          totals: { debe_ars: 4200000, haber_ars: 1600000, saldo_ars: 2700000 },
          balances: { USD: balance('USD', 1800, 2700000) },
          valuation: { disponible: true, ars: 2880000, ars_parcial: null, monedas_sin_cotizacion: [], cotizaciones: {} },
          documents: [{
            id: 30,
            fecha: '2026-11-10',
            documento_numero: 'M-77',
            documento_tipo: null,
            modalidad: 'MEMO_AUTORIZADO',
            modalidad_label: 'Memo autorizado',
            sentido: 'DEBE',
            estado: 'VIGENTE',
            anulado: false,
            viaje_id: 16,
            viaje_numero: 16,
            tramo_id: 9,
            moneda: 'USD',
            importe_original: 2800,
            cotizacion: 1500,
            cotizacion_fecha: '2026-11-10',
            cotizacion_fuente: 'MANUAL',
            importe_ars: 4200000,
            importe_pagado: 1000,
            saldo_pendiente: 1800,
            saldo_pendiente_ars: 2700000,
            estado_cobranza: 'PARCIAL',
            estado_cobranza_label: 'Cobro parcial',
          }],
          payments: [{
            id: 5,
            fecha: '2026-11-12',
            medio: 'TRANSFERENCIA',
            medio_label: 'Transferencia',
            moneda: 'USD',
            importe: 1000,
            cotizacion: 1600,
            cotizacion_fecha: '2026-11-12',
            cotizacion_fuente: 'MANUAL',
            importe_ars: 1600000,
            estado: 'VIGENTE',
            anulado: false,
            referencia: 'REC-001',
            observaciones: null,
            motivo_anulacion: null,
            imputaciones: [{
              id: 9,
              cargo_id: 30,
              documento_numero: 'M-77',
              documento_tipo: null,
              viaje_numero: 16,
              moneda: 'USD',
              importe_original: 1000,
              cotizacion_cargo: 1500,
              importe_ars_cargo: 1500000,
              cotizacion_pago: 1600,
              importe_ars_pago: 1600000,
              diferencia_cambio: 100000,
              estado: 'VIGENTE',
            }],
          }],
          movements: [],
        },
      },
      attachTo: document.body,
    })

    const documents = wrapper.get('[data-testid="documents-table"]').text()
    expect(documents).toContain('USD 2.800,00')
    expect(documents).toContain('USD 1.000,00')
    expect(documents).toContain('USD 1.800,00')
    expect(documents).toContain('Cobro parcial')
    expect(documents).toContain('ARS 4.200.000,00')

    const payments = wrapper.get('[data-testid="payments-list"]').text()
    expect(payments).toContain('Transferencia')
    expect(payments).toContain('ARS 1.600.000,00')
    expect(payments).toContain('Diferencia de cambio ARS 100.000,00')
    wrapper.unmount()
  })

  it('registra un cobro en su moneda y lo imputa a documentos de la misma moneda', async () => {
    const wrapper = mount(CustomerAccountDetailPage, {
      props: {
        data: {
          csrf: { name: 'csrf_test_name', hash: 'abc' },
          links: { ...links, openingBalance: '/mantenimiento/clientes/cuenta-corriente/4/saldo-inicial', paymentsBase: '/mantenimiento/clientes/cuenta-corriente/cobranzas/' },
          returnTo: '/clientes/cuenta-corriente/4',
          customer: { id: 4, nombre: 'Grupo Pluma' },
          canFormalize: true,
          canCollect: true,
          currencies: ['ARS', 'USD'],
          currentRates: { USD: 1600 },
          totals: { debe_ars: 4200000, haber_ars: 0, saldo_ars: 4200000 },
          balances: { USD: balance('USD', 2800, 4200000) },
          valuation: { disponible: true, ars: 4480000, ars_parcial: null, monedas_sin_cotizacion: [], cotizaciones: {} },
          documents: [{
            id: 30,
            fecha: '2026-11-10',
            documento_numero: 'M-77',
            modalidad_label: 'Memo autorizado',
            estado: 'VIGENTE',
            anulado: false,
            viaje_id: 16,
            viaje_numero: 16,
            moneda: 'USD',
            importe_original: 2800,
            cotizacion: 1500,
            cotizacion_fecha: '2026-11-10',
            cotizacion_fuente: 'MANUAL',
            importe_ars: 4200000,
            importe_pagado: 0,
            saldo_pendiente: 2800,
            saldo_pendiente_ars: 4200000,
            estado_cobranza: 'PENDIENTE',
            estado_cobranza_label: 'Pendiente de cobro',
          }],
          payments: [],
          movements: [],
        },
      },
      attachTo: document.body,
    })

    await wrapper.get('[data-testid="open-payment"]').trigger('click')
    await nextTick()
    const form = wrapper.get('[data-testid="payment-form"]')
    expect(form.attributes('action')).toBe('/mantenimiento/clientes/cuenta-corriente/4/cobranzas')

    await form.get('select[name="moneda"]').setValue('USD')
    expect(form.get('input[name="cotizacion"]').element.value).toBe('1600')
    await form.get('input[name="importe"]').setValue('1000')
    expect(wrapper.get('[data-testid="payment-preview"]').text()).toContain('ARS 1.600.000,00')

    // Solo se pueden imputar documentos de la misma moneda del cobro.
    const imputable = form.findAll('input[name="importe_cargo[]"]')
    expect(imputable).toHaveLength(1)
    await imputable[0].setValue('1200')
    expect(wrapper.get('[data-testid="payment-error"]').text()).toContain('supera el importe del cobro')
    await imputable[0].setValue('1000')
    expect(wrapper.find('[data-testid="payment-error"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('filtra los movimientos por moneda', async () => {
    const wrapper = mount(CustomerAccountDetailPage, {
      props: {
        data: {
          csrf: { name: 'csrf_test_name', hash: 'abc' },
          links,
          returnTo: '/clientes/cuenta-corriente/4',
          customer: { id: 4, nombre: 'Grupo Pluma' },
          canFormalize: true,
          canCollect: true,
          currencies: ['ARS', 'USD'],
          totals: { debe_ars: 100, haber_ars: 0, saldo_ars: 100 },
          balances: { ARS: balance('ARS', 100, 100), USD: balance('USD', 2800, 4200000) },
          valuation: { disponible: true, ars: 100, ars_parcial: null, monedas_sin_cotizacion: [], cotizaciones: {} },
          documents: [],
          payments: [],
          movements: [
            { id: 1, fecha: '2026-11-10', tipo: 'CARGO', tipo_label: 'Memo autorizado', descripcion: 'Memo', cargo_id: 30, cargo_vigente: true, cargo_anulado: false, moneda: 'USD', importe_original: 2800, cotizacion: 1500, cotizacion_fecha: '2026-11-10', cotizacion_fuente: 'MANUAL', debe_ars: 4200000, haber_ars: 0, saldo_ars: 4200000 },
            { id: 2, fecha: '2026-11-01', tipo: 'SALDO_INICIAL', tipo_label: 'Saldo inicial', descripcion: 'Saldo inicial', cargo_id: 32, cargo_vigente: true, cargo_anulado: false, moneda: 'ARS', importe_original: 100, cotizacion: 1, cotizacion_fecha: '2026-11-01', cotizacion_fuente: 'PESOS', debe_ars: 100, haber_ars: 0, saldo_ars: 100 },
          ],
        },
      },
      attachTo: document.body,
    })

    expect(wrapper.get('[data-testid="statement-table"]').text()).toContain('Memo autorizado')
    await wrapper.get('[data-testid="filter-USD"]').trigger('click')
    await nextTick()
    const table = wrapper.get('[data-testid="statement-table"]').text()
    expect(table).toContain('Memo autorizado')
    expect(table).not.toContain('Saldo inicial')
    wrapper.unmount()
  })
})
