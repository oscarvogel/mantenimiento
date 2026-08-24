import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import MaintenanceOverviewPage from './MaintenanceOverviewPage.vue'

const pagination = () => ({ page: 1, totalPages: 1, total: 0, previousUrl: null, nextUrl: null, pageKey: 'page', perPageKey: 'per_page', perPage: 10, perPageOptions: [5, 10, 25] })

const baseData = () => ({
  csrf: { name: 'csrf_test', hash: 'HASH123' },
  currentDateTime: '2026-08-24 17:00:00',
  old: {},
  routes: {
    equipmentIndex: '/mantenimiento/equipos',
    createEquipment: '/mantenimiento/equipos',
  },
  can: {
    createEquipment: true,
    registerReading: true,
    assignPlan: false,
    generateOrder: false,
    editOrder: true,
    closeOrder: false,
  },
  pagination: {
    equipments: { ...pagination(), total: 2 },
    plans: pagination(),
    notices: pagination(),
    orders: pagination(),
    readings: pagination(),
  },
  catalogs: {
    branches: [], equipmentTypes: [], brands: [], models: [], serviceTypes: [], templateDefaults: [],
    users: [{ id: 7, name: 'Técnico Uno' }],
  },
  equipments: [
    { id: 10, code: 'AB499OK', plate: 'AB-499-OK', typeId: 1, typeName: 'Camión', branchName: 'Central', status: 'ACTIVO', controlsKm: true, controlsHours: false, currentKm: 125430, currentHours: null, photoUrl: null, routes: { detail: '/mantenimiento/equipos/10', registerReading: '/mantenimiento/equipos/10/lecturas', assignPlan: '/mantenimiento/equipos/10/planes' } },
    { id: 20, code: 'MOT-03', plate: null, typeId: 2, typeName: 'Máquina', branchName: 'Central', status: 'ACTIVO', controlsKm: false, controlsHours: true, currentKm: null, currentHours: '8340.0', photoUrl: null, routes: { detail: '/mantenimiento/equipos/20', registerReading: '/mantenimiento/equipos/20/lecturas', assignPlan: '/mantenimiento/equipos/20/planes' } },
  ],
  plans: [], notices: [], orders: [], readings: [],
})

describe('MaintenanceOverviewPage', () => {
  it('reemplaza el alta rápida de equipos por acciones operativas compactas', () => {
    const wrapper = mount(MaintenanceOverviewPage, { props: { data: baseData() } })

    expect(wrapper.text()).toContain('Nueva OT correctiva')
    expect(wrapper.text()).toContain('Registrar lectura')
    expect(wrapper.text()).toContain('Administrar equipos')
    expect(wrapper.text()).not.toContain('Nuevo equipo')
    expect(wrapper.text()).not.toContain('Crear equipo')
    expect(wrapper.find('form[action="/mantenimiento/equipos"]').exists()).toBe(false)
  })

  it('abre el modal, busca por patente y prepara el flujo existente de OT correctiva', async () => {
    const wrapper = mount(MaintenanceOverviewPage, { props: { data: baseData() } })
    const quickAction = wrapper.findAll('button').find((button) => button.text().includes('Nueva OT correctiva'))
    await quickAction.trigger('click')

    const dialog = wrapper.find('[role="dialog"]')
    expect(dialog.exists()).toBe(true)
    expect(dialog.text()).toContain('Nueva OT correctiva')

    const search = dialog.find('input[type="search"]')
    await search.setValue('ab 499 ok')
    expect(dialog.text()).toContain('AB499OK')
    expect(dialog.text()).not.toContain('MOT-03')

    await dialog.findAll('button').find((button) => button.text().includes('AB499OK')).trigger('click')
    expect(dialog.text()).toContain('Seleccionado: AB499OK · AB-499-OK')

    const form = dialog.find('form')
    expect(form.attributes('action')).toBe('/mantenimiento/ordenes/correctivas')
    expect(form.find('input[name="equipo_id"]').element.value).toBe('10')
    expect(form.find('textarea[name="problema_reportado"]').attributes('required')).toBeDefined()
    expect(form.find('select[name="prioridad"] option:checked').text()).toBe('Normal')
    expect(form.find('textarea[name="observaciones"]').exists()).toBe(true)
  })

  it('desde una tarjeta abre el mismo modal con el equipo ya seleccionado', async () => {
    const wrapper = mount(MaintenanceOverviewPage, { props: { data: baseData() } })
    const cardButton = wrapper.findAll('button').filter((button) => button.text().includes('Nueva OT correctiva'))[1]
    await cardButton.trigger('click')

    const dialog = wrapper.find('[role="dialog"]')
    expect(dialog.text()).toContain('Seleccionado: AB499OK · AB-499-OK')
    expect(dialog.find('input[name="equipo_id"]').element.value).toBe('10')
  })
})
