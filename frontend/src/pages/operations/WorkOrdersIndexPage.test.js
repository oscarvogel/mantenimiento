import { afterEach, describe, expect, it } from 'vitest'
import { DOMWrapper, mount } from '@vue/test-utils'
import WorkOrdersIndexPage from './WorkOrdersIndexPage.vue'

const data = () => ({
  routes: { index: '/mantenimiento/ordenes', maintenance: '/mantenimiento', registerCorrective: '/mantenimiento/ordenes/correctivas' },
  filters: { q: '', status: '', branchId: '', ownerId: '', attention: '' },
  can: { editOrder: true, closeOrder: true },
  csrf: { name: 'csrf_test', hash: 'SECURE' },
  kpis: { open: 4, issued: 1, inProgress: 1, waitingParts: 2, delayed: 1, finishedToday: 3 },
  delayDays: 3,
  branches: [{ id: 2, name: 'Garuhapé' }],
  owners: [{ id: 7, name: 'Técnico Uno' }],
  correctiveEquipments: [
    { id: 9, code: 'CAM-01', plate: 'AA123BB', typeName: 'Camión', branchName: 'Garuhapé', controlsKm: true, controlsHours: false, currentKm: 120500, currentHours: null },
    { id: 12, code: 'AE223WN', plate: 'AE-223-WN', typeName: 'Camión', branchName: 'Norte', controlsKm: true, controlsHours: false, currentKm: 101250, currentHours: null },
  ],
  pagination: { page: 1, totalPages: 1, total: 3, perPage: 25, previousUrl: null, nextUrl: null },
  orders: [
    {
      id: 31, number: 'OT-00031', origin: 'PREVENTIVO', priority: 'ALTA', status: 'EMITIDA', equipmentCode: 'CAM-01', plate: 'AA123BB', branchName: 'Garuhapé', serviceName: 'Servicio motor', ownerName: 'Técnico Uno', openedAt: '2026-08-20 08:00:00', startedAt: null, finishedAt: null, ageDays: 4, delayed: true, entryKm: 120000, entryHours: null, currentKm: 120500, currentHours: null, diagnosis: null, notes: 'Control general', costs: { labor: 0, parts: 0, other: 0, total: 0 }, tasks: [{ id: 81, description: 'Cambiar aceite', status: 'PENDIENTE', workPerformed: null }], routes: { print: '/mantenimiento/ordenes/31/imprimir', start: '/mantenimiento/ordenes/31/iniciar', resume: '/mantenimiento/ordenes/31/reanudar', cancel: '/mantenimiento/ordenes/31/cancelar', close: '/mantenimiento/ordenes/31/cerrar' },
    },
    {
      id: 32, number: 'OT-00032', origin: 'CORRECTIVO', priority: 'MEDIA', status: 'ESPERA_REPUESTOS', equipmentCode: 'TR-04', plate: null, branchName: 'Garuhapé', serviceName: 'OT correctiva', ownerName: 'Técnico Uno', openedAt: '2026-08-24 08:00:00', startedAt: '2026-08-24 09:00:00', finishedAt: null, ageDays: 0, delayed: false, entryKm: null, entryHours: '8420.0', currentKm: null, currentHours: '8420.0', diagnosis: 'Pérdida hidráulica', notes: null, costs: { labor: 0, parts: 0, other: 624.9, total: 624.9 }, historicalCost: { currency: 'BRL', originalAmount: 624.9, exchangeRateArs: 350, exchangeRateDate: '2026-08-12', exchangeRateSource: 'BCRA', amountArs: 218715 }, tasks: [], routes: { print: '/mantenimiento/ordenes/32/imprimir', start: '/mantenimiento/ordenes/32/iniciar', resume: '/mantenimiento/ordenes/32/reanudar', cancel: '/mantenimiento/ordenes/32/cancelar', close: '/mantenimiento/ordenes/32/cerrar' },
    },
    {
      id: 33, number: 'OT-00033', origin: 'PREVENTIVO', priority: 'MEDIA', status: 'EN_PROCESO', equipmentCode: 'CAM-09', plate: 'AB999CD', branchName: 'Garuhapé', serviceName: 'Frenos', ownerName: 'Técnico Uno', openedAt: '2026-08-23 08:00:00', startedAt: '2026-08-23 09:00:00', finishedAt: null, ageDays: 1, delayed: false, entryKm: 99000, entryHours: null, currentKm: 99100, currentHours: null, diagnosis: null, notes: null, costs: { labor: 0, parts: 0, other: 0, total: 0 }, tasks: [{ id: 91, description: 'Revisar pastillas', status: 'PENDIENTE', workPerformed: null }], routes: { print: '/mantenimiento/ordenes/33/imprimir', start: '/mantenimiento/ordenes/33/iniciar', resume: '/mantenimiento/ordenes/33/reanudar', cancel: '/mantenimiento/ordenes/33/cancelar', close: '/mantenimiento/ordenes/33/cerrar' },
    },
  ],
})

afterEach(() => {
  document.body.innerHTML = ''
  window.history.replaceState(null, '', '/')
})

describe('WorkOrdersIndexPage', () => {
  it('muestra KPIs accionables y filtros operativos', () => {
    const wrapper = mount(WorkOrdersIndexPage, { props: { data: data() } })
    expect(wrapper.text()).toContain('OT abiertas')
    expect(wrapper.text()).toContain('Demoradas')
    expect(wrapper.text()).toContain('Órdenes registradas')
    expect(wrapper.text()).not.toContain('demorada desde')
    expect(wrapper.find('a[href="/mantenimiento/ordenes?atencion=delayed"]').exists()).toBe(true)
    expect(wrapper.find('input[name="q"]').attributes('placeholder')).toContain('Número')
    expect(wrapper.find('select[name="estado"]').exists()).toBe(true)
    expect(wrapper.find('select[name="sucursal_id"]').exists()).toBe(true)
    expect(wrapper.find('select[name="responsable_id"]').exists()).toBe(true)
    expect(wrapper.findAll('a[href="/mantenimiento/ordenes"]').some((link) => link.text().includes('Limpiar filtros'))).toBe(false)
  })

  it('contextualiza la bandeja de demoradas y permite limpiar filtros', () => {
    const payload = data()
    payload.filters = { ...payload.filters, attention: 'delayed', q: 'OT-00031' }
    payload.pagination = { ...payload.pagination, total: 1 }

    const wrapper = mount(WorkOrdersIndexPage, { props: { data: payload } })

    expect(wrapper.text()).toContain('Órdenes que requieren atención')
    expect(wrapper.text()).toContain('1 OT encontrada · abiertas desde 3 días o más.')
    expect(wrapper.findAll('a[href="/mantenimiento/ordenes"]').some((link) => link.text().includes('Limpiar filtros'))).toBe(true)
  })

  it('los contadores son píldoras siempre visibles que filtran, con la activa marcada', () => {
    const payload = data()
    payload.filters = { ...payload.filters, status: 'EN_PROCESO' }
    const wrapper = mount(WorkOrdersIndexPage, { props: { data: payload } })

    const chips = wrapper.get('nav[aria-label="Indicadores de órdenes"]')
    expect(chips.classes()).toContain('overflow-x-auto')
    expect(chips.findAll('a').map((chip) => chip.text())).toEqual([
      'Todas', 'OT abiertas 4', 'Emitidas 1', 'En proceso 1', 'Esperando repuestos 2', 'Demoradas 1', 'Finalizadas hoy 3',
    ])
    expect(chips.get('a[href="/mantenimiento/ordenes?estado=EN_PROCESO"]').attributes('aria-current')).toBe('true')
    expect(chips.get('a[href="/mantenimiento/ordenes"]').attributes('aria-current')).toBeUndefined()
    // Cada píldora lista exactamente lo que cuenta.
    expect(chips.find('a[href="/mantenimiento/ordenes?atencion=open"]').text()).toBe('OT abiertas 4')
    expect(chips.find('a[href="/mantenimiento/ordenes?atencion=finished-today"]').text()).toBe('Finalizadas hoy 3')
    expect(chips.find('a[href="/mantenimiento/ordenes?atencion=delayed"]').exists()).toBe(true)
    expect(wrapper.find('.w-full.flex-col').exists()).toBe(true)
    expect(wrapper.findAll('a').some((link) => link.text().includes('Volver a Mantenimiento'))).toBe(false)
  })

  it('sin filtros marca "Todas" como activa; con la vista de abiertas, marca esa', () => {
    const wrapper = mount(WorkOrdersIndexPage, { props: { data: data() } })
    expect(wrapper.get('nav[aria-label="Indicadores de órdenes"] a[href="/mantenimiento/ordenes"]').attributes('aria-current')).toBe('true')

    const payload = data()
    payload.filters = { ...payload.filters, attention: 'open' }
    const open = mount(WorkOrdersIndexPage, { props: { data: payload } })
    expect(open.get('nav[aria-label="Indicadores de órdenes"] a[href="/mantenimiento/ordenes?atencion=open"]').attributes('aria-current')).toBe('true')
    expect(open.get('nav[aria-label="Indicadores de órdenes"] a[href="/mantenimiento/ordenes"]').attributes('aria-current')).toBeUndefined()
  })

  it('permite desplegar los filtros adicionales sin perder la búsqueda', async () => {
    const wrapper = mount(WorkOrdersIndexPage, { props: { data: data() } })
    const filtersButton = wrapper.get('button[aria-controls="work-order-extra-filters"]')
    const search = wrapper.get('input[name="q"]')

    expect(filtersButton.attributes('aria-expanded')).toBe('false')
    await search.setValue('OT-00031')
    await filtersButton.trigger('click')

    expect(filtersButton.attributes('aria-expanded')).toBe('true')
    expect(search.element.value).toBe('OT-00031')
    expect(wrapper.get('#work-order-extra-filters select[name="estado"]').element.form).toBe(search.element.form)
  })

  it('deja abiertos los filtros adicionales si hay estado, sucursal o responsable aplicado', () => {
    const payload = data()
    payload.filters = { ...payload.filters, branchId: 2 }
    const wrapper = mount(WorkOrdersIndexPage, { props: { data: payload } })

    expect(wrapper.get('button[aria-controls="work-order-extra-filters"]').attributes('aria-expanded')).toBe('true')
    expect(wrapper.get('select[name="sucursal_id"]').element.value).toBe('2')
  })

  it('lanza un correctivo rápido sin abandonar el listado', async () => {
    const wrapper = mount(WorkOrdersIndexPage, { props: { data: data() } })
    const button = wrapper.findAll('button').find((item) => item.text().includes('Registrar correctivo'))
    expect(button).toBeDefined()
    await button.trigger('click')

    // The modal is a BaseDialog teleported to <body>, outside the page wrapper.
    const dialog = new DOMWrapper(document.body.querySelector('[role="dialog"]'))
    expect(dialog.exists()).toBe(true)
    expect(dialog.text()).toContain('Registrar trabajo realizado')
    const search = dialog.find('input[type="search"]')
    await search.setValue('ae 223 wn')
    expect(dialog.text()).toContain('AE223WN')
    await dialog.findAll('button').find((item) => item.text().includes('AE223WN')).trigger('click')

    const form = dialog.find('form')
    expect(form.attributes('action')).toBe('/mantenimiento/ordenes/correctivas')
    expect(form.attributes('enctype')).toBe('multipart/form-data')
    expect(form.find('input[name="volver_ordenes"]').element.value).toBe('1')
    expect(form.find('input[name="equipo_id"]').element.value).toBe('12')
    expect(form.find('textarea[name="trabajo_realizado_correctivo"]').exists()).toBe(true)
    expect(form.find('input[name="evidencia"]').exists()).toBe(true)
  })

  it('oculta el registro rápido sin permiso para editar órdenes', () => {
    const payload = data()
    payload.can.editOrder = false
    const wrapper = mount(WorkOrdersIndexPage, { props: { data: payload } })
    expect(wrapper.findAll('button').some((item) => item.text().includes('Registrar correctivo'))).toBe(false)
  })

  it('prioriza datos accionables y expone imprimir/iniciar/reanudar', () => {
    const wrapper = mount(WorkOrdersIndexPage, { props: { data: data() } })
    expect(wrapper.text()).toContain('OT-00031 · CAM-01')
    expect(wrapper.text()).toContain('Fecha de apertura:')
    expect(wrapper.text()).toContain('Demorada')
    expect(wrapper.find('a[href="/mantenimiento/ordenes/31/imprimir"]').attributes('target')).toBe('_blank')
    expect(wrapper.find('form[action="/mantenimiento/ordenes/31/iniciar"]').exists()).toBe(true)
    expect(wrapper.find('form[action="/mantenimiento/ordenes/32/reanudar"]').exists()).toBe(true)
    expect(wrapper.find('input[name="csrf_test"]').attributes('value')).toBe('SECURE')
  })

  it('permite desplegar el detalle operativo sin abandonar el listado', async () => {
    const wrapper = mount(WorkOrdersIndexPage, { props: { data: data() } })
    const detailButton = wrapper.findAll('button').find((button) => button.text().includes('Ver detalle'))
    await detailButton.trigger('click')

    expect(wrapper.find('[data-testid="work-order-detail"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Cambiar aceite')
    expect(wrapper.text()).toContain('Control general')
    expect(wrapper.text()).toContain('Total: $\u00a00')
  })

  it('muestra moneda original y equivalente histórico ARS para una OT en BRL', async () => {
    const wrapper = mount(WorkOrdersIndexPage, { props: { data: data() } })
    const order = wrapper.find('#orden-32')
    const detailButton = order.findAll('button').find((button) => button.text().includes('Ver detalle'))
    await detailButton.trigger('click')

    expect(order.text()).toContain('otros BRL\u00a0625')
    expect(order.text()).toContain('Total original: BRL\u00a0625')
    expect(order.text()).toContain('1 BRL = $\u00a0350,00 ARS')
    expect(order.text()).toContain('Fecha cotización: 12/08/2026')
    expect(order.text()).toContain('Origen cotización: BCRA')
    expect(order.text()).toContain('Total histórico ARS: $\u00a0218.715')
    expect(order.text()).not.toContain('Total: $\u00a0625')
  })

  it('permite anular una OT activa con motivo obligatorio', async () => {
    const wrapper = mount(WorkOrdersIndexPage, { props: { data: data() }, attachTo: document.body })
    const order = wrapper.find('#orden-33')
    const cancelButton = order.findAll('button').find((button) => button.text().trim() === 'Anular')
    expect(cancelButton).toBeDefined()

    await cancelButton.trigger('click')
    const modal = document.body.querySelector('[data-testid="work-order-cancel-modal"]')
    expect(modal).not.toBeNull()
    expect(modal.textContent).toContain('Anular OT-00033')
    expect(modal.querySelector('form').getAttribute('action')).toBe('/mantenimiento/ordenes/33/cancelar')
    expect(modal.querySelector('textarea[name="motivo"]').required).toBe(true)
    expect(modal.querySelector('textarea[name="motivo"]').minLength).toBe(5)
  })

  it('abre el mismo modal de cierre desde una OT en proceso', async () => {
    const wrapper = mount(WorkOrdersIndexPage, { props: { data: data() }, attachTo: document.body })
    const closeButton = wrapper.findAll('button').find((button) => button.text().trim() === 'Cerrar')
    expect(closeButton).toBeDefined()

    await closeButton.trigger('click')
    const modal = document.body.querySelector('[data-testid="work-order-closure-modal"]')
    expect(modal).not.toBeNull()
    expect(modal.textContent).toContain('Cerrar OT-00033')
    expect(modal.querySelector('form').getAttribute('action')).toBe('/mantenimiento/ordenes/33/cerrar')
    expect(modal.textContent).toContain('Revisar pastillas')
  })

  it('en Mi trabajo agrupa las órdenes por lo que hay que hacer y oculta filtros y totales', () => {
    window.history.replaceState(null, '', '/mantenimiento/ordenes?responsable_id=7&vista=mi-trabajo')
    const wrapper = mount(WorkOrdersIndexPage, { props: { data: data() } })

    expect(wrapper.get('h1').text()).toBe('Mi trabajo')
    expect(wrapper.findAll('section[aria-label] > h2').map((node) => node.text())).toEqual(['En curso (1)', 'Para empezar (1)', 'En espera de repuestos (1)'])
    expect(wrapper.find('form[method="get"]').exists()).toBe(false)
    expect(wrapper.find('[aria-label="Indicadores de órdenes"]').exists()).toBe(false)
  })

  it('en Mi trabajo sin órdenes lo dice con palabras propias', () => {
    window.history.replaceState(null, '', '/mantenimiento/ordenes?responsable_id=7&vista=mi-trabajo')
    const wrapper = mount(WorkOrdersIndexPage, { props: { data: { ...data(), orders: [] } } })
    expect(wrapper.text()).toContain('No tenés órdenes asignadas')
  })
})
