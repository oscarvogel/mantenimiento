import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import MaintenanceOverviewPage from './MaintenanceOverviewPage.vue'

const pagination = () => ({ page: 1, totalPages: 1, total: 0, previousUrl: null, nextUrl: null, pageKey: 'page', perPageKey: 'per_page', perPage: 10, perPageOptions: [5, 10, 25] })

const baseData = () => ({
  csrf: { name: 'csrf_test', hash: 'HASH123' },
  currentDateTime: '2026-08-24 17:00:00',
  old: {},
  routes: { equipmentIndex: '/mantenimiento/equipos', createEquipment: '/mantenimiento/equipos' },
  can: { createEquipment: true, registerReading: true, assignPlan: false, generateOrder: false, editOrder: true, closeOrder: false },
  pagination: { equipments: { ...pagination(), total: 3 }, plans: pagination(), notices: pagination(), orders: pagination(), readings: pagination() },
  catalogs: {
    branches: [], equipmentTypes: [], brands: [], models: [], serviceTypes: [], templateDefaults: [],
    users: [{ id: 7, name: 'Técnico Uno' }],
  },
  equipments: [
    { id: 10, code: 'AB499OK', plate: 'AB-499-OK', typeId: 1, typeName: 'Camión', branchName: 'Central', status: 'ACTIVO', controlsKm: true, controlsHours: false, currentKm: 125430, currentHours: null, photoUrl: null, routes: { detail: '/mantenimiento/equipos/10', registerReading: '/mantenimiento/equipos/10/lecturas', assignPlan: '/mantenimiento/equipos/10/planes' } },
    { id: 20, code: 'MOT-03', plate: null, typeId: 2, typeName: 'Máquina', branchName: 'Central', status: 'ACTIVO', controlsKm: false, controlsHours: true, currentKm: null, currentHours: '8340.0', photoUrl: null, routes: { detail: '/mantenimiento/equipos/20', registerReading: '/mantenimiento/equipos/20/lecturas', assignPlan: '/mantenimiento/equipos/20/planes' } },
  ],
  correctiveEquipments: [
    { id: 10, code: 'AB499OK', plate: 'AB-499-OK', typeId: 1, typeName: 'Camión', branchName: 'Central', controlsKm: true, controlsHours: false, currentKm: 125430, currentHours: null },
    { id: 20, code: 'MOT-03', plate: null, typeId: 2, typeName: 'Máquina', branchName: 'Central', controlsKm: false, controlsHours: true, currentKm: null, currentHours: '8340.0' },
    { id: 30, code: 'AE223WN', plate: 'AE223WN', typeId: 1, typeName: 'Camión', branchName: 'Norte', controlsKm: true, controlsHours: false, currentKm: 101250, currentHours: null },
  ],
  plans: [], notices: [], orders: [], readings: [],
})

afterEach(() => window.history.replaceState({}, '', '/'))

describe('MaintenanceOverviewPage', () => {
  it('ofrece registrar un correctivo realizado como acción operativa', () => {
    const wrapper = mount(MaintenanceOverviewPage, { props: { data: baseData() } })
    expect(wrapper.text()).toContain('Registrar correctivo realizado')
    expect(wrapper.text()).toContain('Registrar lectura')
    expect(wrapper.text()).toContain('Administrar equipos')
    expect(wrapper.text()).not.toContain('Nuevo equipo')
  })

  it('busca el equipo sobre el catálogo completo aunque no esté en la página visible', async () => {
    const wrapper = mount(MaintenanceOverviewPage, { props: { data: baseData() } })
    const quickAction = wrapper.findAll('button').find((button) => button.text().includes('Registrar correctivo realizado'))
    await quickAction.trigger('click')
    const dialog = wrapper.find('[role="dialog"]')
    const search = dialog.find('input[type="search"]')
    await search.setValue('ae 223 wn')
    expect(dialog.text()).toContain('AE223WN')
    await dialog.findAll('button').find((button) => button.text().includes('AE223WN')).trigger('click')
    expect(dialog.find('input[name="equipo_id"]').element.value).toBe('30')
  })

  it('prepara una carga única que finaliza el correctivo y admite evidencia', async () => {
    const wrapper = mount(MaintenanceOverviewPage, { props: { data: baseData() } })
    const quickAction = wrapper.findAll('button').find((button) => button.text().includes('Registrar correctivo realizado'))
    await quickAction.trigger('click')
    const dialog = wrapper.find('[role="dialog"]')
    expect(dialog.text()).toContain('Registrar trabajo realizado')
    expect(dialog.text()).toContain('sin abrir ni iniciar una OT')

    const search = dialog.find('input[type="search"]')
    await search.setValue('ab-499-ok')
    await dialog.findAll('button').find((button) => button.text().includes('AB499OK')).trigger('click')

    const form = dialog.find('form')
    expect(form.attributes('action')).toBe('/mantenimiento/ordenes/correctivas')
    expect(form.attributes('enctype')).toBe('multipart/form-data')
    expect(form.find('textarea[name="problema_reportado"]').attributes('required')).toBeDefined()
    expect(form.find('textarea[name="trabajo_realizado_correctivo"]').attributes('required')).toBeDefined()
    expect(form.find('input[name="km_salida"]').exists()).toBe(true)
    expect(form.find('input[name="evidencia"]').attributes('accept')).toContain('application/pdf')
    expect(form.text()).toContain('FINALIZADA')
  })

  it('desde una tarjeta abre con el equipo fijo y sin volver a pedirlo', async () => {
    const wrapper = mount(MaintenanceOverviewPage, { props: { data: baseData() } })
    const cardButton = wrapper.findAll('button').find((button) => button.text().trim() === 'Registrar correctivo')
    await cardButton.trigger('click')
    const dialog = wrapper.find('[role="dialog"]')
    expect(dialog.text()).toContain('Equipo de esta ficha')
    expect(dialog.text()).toContain('AB499OK · AB-499-OK')
    expect(dialog.find('input[type="search"]').exists()).toBe(false)
    expect(dialog.find('input[name="equipo_id"]').element.value).toBe('10')
    expect(dialog.find('input[name="volver_equipo"]').element.value).toBe('1')
  })

  it('puede abrirse desde una ficha por query con el equipo fijo', () => {
    window.history.replaceState({}, '', '/mantenimiento?ot_correctiva=1&equipo_id=30')
    const wrapper = mount(MaintenanceOverviewPage, { props: { data: baseData() } })
    const dialog = wrapper.find('[role="dialog"]')
    expect(dialog.exists()).toBe(true)
    expect(dialog.text()).toContain('AE223WN')
    expect(dialog.find('input[type="search"]').exists()).toBe(false)
    expect(dialog.find('input[name="equipo_id"]').element.value).toBe('30')
  })

  it('muestra Imprimir OT en cada orden y abre la impresión existente en otra pestaña', () => {
    const data = baseData()
    data.pagination.orders.total = 1
    data.orders = [{ id: 31, number: 'OT-00031', equipmentCode: 'AB499OK', photoUrl: null, status: 'EMITIDA', serviceName: 'Frenos', ownerName: 'Técnico Uno', tasks: [], startUrl: '/mantenimiento/ordenes/31/iniciar', currentKm: 125430, currentHours: null }]
    const wrapper = mount(MaintenanceOverviewPage, { props: { data } })
    const printLink = wrapper.findAll('a').find((link) => link.text().includes('Imprimir OT'))
    expect(printLink).toBeDefined()
    expect(printLink.attributes('href')).toBe('/mantenimiento/ordenes/31/imprimir')
    expect(printLink.attributes('target')).toBe('_blank')
    expect(printLink.attributes('rel')).toContain('noopener')
  })
})
