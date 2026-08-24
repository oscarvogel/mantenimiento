import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import WorkOrdersIndexPage from './WorkOrdersIndexPage.vue'

const data = () => ({
  routes: { index: '/mantenimiento/ordenes', maintenance: '/mantenimiento' },
  filters: { q: '', status: '', branchId: '', ownerId: '', attention: '' },
  can: { editOrder: true, closeOrder: true },
  csrf: { name: 'csrf_test', hash: 'SECURE' },
  kpis: { open: 4, issued: 1, inProgress: 1, waitingParts: 2, delayed: 1, finishedToday: 3 },
  delayDays: 3,
  branches: [{ id: 2, name: 'Garuhapé' }],
  owners: [{ id: 7, name: 'Técnico Uno' }],
  pagination: { page: 1, totalPages: 1, total: 2, perPage: 25, previousUrl: null, nextUrl: null },
  orders: [
    { id: 31, number: 'OT-00031', origin: 'PREVENTIVO', priority: 'ALTA', status: 'EMITIDA', equipmentCode: 'CAM-01', plate: 'AA123BB', branchName: 'Garuhapé', serviceName: 'Servicio motor', ownerName: 'Técnico Uno', openedAt: '2026-08-20 08:00:00', ageDays: 4, delayed: true, entryKm: 120000, entryHours: null, routes: { print: '/mantenimiento/ordenes/31/imprimir', start: '/mantenimiento/ordenes/31/iniciar', resume: '/mantenimiento/ordenes/31/reanudar' } },
    { id: 32, number: 'OT-00032', origin: 'CORRECTIVO', priority: 'MEDIA', status: 'ESPERA_REPUESTOS', equipmentCode: 'TR-04', plate: null, branchName: 'Garuhapé', serviceName: 'OT correctiva', ownerName: 'Técnico Uno', openedAt: '2026-08-24 08:00:00', ageDays: 0, delayed: false, entryKm: null, entryHours: '8420.0', routes: { print: '/mantenimiento/ordenes/32/imprimir', start: '/mantenimiento/ordenes/32/iniciar', resume: '/mantenimiento/ordenes/32/reanudar' } },
  ],
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

  it('prioriza datos accionables y expone imprimir/iniciar/reanudar', () => {
    const wrapper = mount(WorkOrdersIndexPage, { props: { data: data() } })
    expect(wrapper.text()).toContain('OT-00031 · CAM-01')
    expect(wrapper.text()).toContain('DEMORADA')
    expect(wrapper.find('a[href="/mantenimiento/ordenes/31/imprimir"]').attributes('target')).toBe('_blank')
    expect(wrapper.find('form[action="/mantenimiento/ordenes/31/iniciar"]').exists()).toBe(true)
    expect(wrapper.find('form[action="/mantenimiento/ordenes/32/reanudar"]').exists()).toBe(true)
    expect(wrapper.find('input[name="csrf_test"]').attributes('value')).toBe('SECURE')
  })
})
