import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
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
      id: 31, number: 'OT-00031', origin: 'PREVENTIVO', priority: 'ALTA', status: 'EMITIDA', equipmentCode: 'CAM-01', plate: 'AA123BB', branchName: 'Garuhapé', serviceName: 'Servicio motor', ownerName: 'Técnico Uno', openedAt: '2026-08-20 08:00:00', startedAt: null, finishedAt: null, ageDays: 4, delayed: true, entryKm: 120000, entryHours: null, currentKm: 120500, currentHours: null, diagnosis: null, notes: 'Control general', costs: { labor: 0, parts: 0, other: 0, total: 0 }, tasks: [{ id: 81, description: 'Cambiar aceite', status: 'PENDIENTE', workPerformed: null }], routes: { print: '/mantenimiento/ordenes/31/imprimir', start: '/mantenimiento/ordenes/31/iniciar', resume: '/mantenimiento/ordenes/31/reanudar', close: '/mantenimiento/ordenes/31/cerrar' },
    },
    {
      id: 32, number: 'OT-00032', origin: 'CORRECTIVO', priority: 'MEDIA', status: 'ESPERA_REPUESTOS', equipmentCode: 'TR-04', plate: null, branchName: 'Garuhapé', serviceName: 'OT correctiva', ownerName: 'Técnico Uno', openedAt: '2026-08-24 08:00:00', startedAt: '2026-08-24 09:00:00', finishedAt: null, ageDays: 0, delayed: false, entryKm: null, entryHours: '8420.0', currentKm: null, currentHours: '8420.0', diagnosis: 'Pérdida hidráulica', notes: null, costs: { labor: 0, parts: 0, other: 0, total: 0 }, tasks: [], routes: { print: '/mantenimiento/ordenes/32/imprimir', start: '/mantenimiento/ordenes/32/iniciar', resume: '/mantenimiento/ordenes/32/reanudar', close: '/mantenimiento/ordenes/32/cerrar' },
    },
    {
      id: 33, number: 'OT-00033', origin: 'PREVENTIVO', priority: 'MEDIA', status: 'EN_PROCESO', equipmentCode: 'CAM-09', plate: 'AB999CD', branchName: 'Garuhapé', serviceName: 'Frenos', ownerName: 'Técnico Uno', openedAt: '2026-08-23 08:00:00', startedAt: '2026-08-23 09:00:00', finishedAt: null, ageDays: 1, delayed: false, entryKm: 99000, entryHours: null, currentKm: 99100, currentHours: null, diagnosis: null, notes: null, costs: { labor: 0, parts: 0, other: 0, total: 0 }, tasks: [{ id: 91, description: 'Revisar pastillas', status: 'PENDIENTE', workPerformed: null }], routes: { print: '/mantenimiento/ordenes/33/imprimir', start: '/mantenimiento/ordenes/33/iniciar', resume: '/mantenimiento/ordenes/33/reanudar', close: '/mantenimiento/ordenes/33/cerrar' },
    },
  ],
})

afterEach(() => {
  document.body.innerHTML = ''
})

describe('WorkOrdersIndexPage', () => {
  it('muestra KPIs accionables y filtros operativos', () => {
    const wrapper = mount(WorkOrdersIndexPage, { props: { data: data() } })
    expect(wrapper.text()).toContain('OT abiertas')
    expect(wrapper.text()).toContain('Demoradas')
    expect(wrapper.find('a[href="/mantenimiento/ordenes?atencion=delayed"]').exists()).toBe(true)
    expect(wrapper.find('input[name="q"]').attributes('placeholder')).toContain('Número')
    expect(wrapper.find('select[name="estado"]').exists()).toBe(true)
    expect(wrapper.find('select[name="sucursal_id"]').exists()).toBe(true)
    expect(wrapper.find('select[name="responsable_id"]').exists()).toBe(true)
  })

  it('lanza un correctivo rápido sin abandonar el listado', async () => {
    const wrapper = mount(WorkOrdersIndexPage, { props: { data: data() } })
    const button = wrapper.findAll('button').find((item) => item.text().includes('Registrar correctivo'))
    expect(button).toBeDefined()
    await button.trigger('click')

    const dialog = wrapper.find('[role="dialog"]')
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
    expect(wrapper.text()).toContain('DEMORADA')
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
    expect(wrapper.text()).toContain('Total: $ 0,00')
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
})
