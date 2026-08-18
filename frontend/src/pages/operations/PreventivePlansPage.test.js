import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import PreventivePlansPage from './PreventivePlansPage.vue'

const baseData = () => ({
  csrf: { name: 'csrf_test', hash: 'HASH123' },
  canEdit: true,
  routes: {
    index: '/mantenimiento/planes',
    create: '/mantenimiento/planes',
    equipmentIndex: '/mantenimiento/equipos',
  },
  filters: { q: '', branchId: '', equipmentId: '10', state: '', perPage: 10 },
  old: {},
  catalogs: {
    equipment: [{
      id: 10,
      code: 'AC532DD',
      plate: 'AC532DD',
      branchId: 1,
      branchCode: 'TSAARG',
      branchName: 'TSA Argentina',
      typeId: 1,
      typeName: 'Camión',
      brandName: null,
      modelName: null,
      controlsKm: true,
      controlsHours: false,
      currentKm: 120000,
      currentHours: null,
      assignedServiceTypeIds: [3],
    }],
    serviceTypes: [{ id: 3, code: 'ACEITE', name: 'Cambio de aceite de motor' }],
    branches: [{ id: 1, code: 'TSAARG', name: 'TSA Argentina' }],
    templateDefaults: [],
  },
  plans: {
    total: 1,
    items: [{
      id: 77,
      equipment: { id: 10, code: 'AC532DD', plate: 'AC532DD', typeName: 'Camión', detailUrl: '/mantenimiento/equipos/10' },
      branch: { id: 1, code: 'TSAARG', name: 'TSA Argentina' },
      serviceName: 'Cambio de aceite de motor',
      state: 'SIN_DATOS',
      priority: 'ALTA',
      editUrl: '/mantenimiento/planes/77/editar',
      criteria: {
        kilometers: { interval: 20000, warning: 1000, base: 120000, next: 140000, current: 120000 },
        hours: null,
        date: { interval: 365, warning: 30, base: '2026-08-01', next: '2027-08-01', current: '2026-08-18' },
      },
      notes: 'Propuesta general.',
    }],
    pagination: {
      page: 1,
      totalPages: 1,
      total: 1,
      perPage: 10,
      perPageOptions: [5, 10, 25],
      perPageKey: 'por_pagina',
      pageKey: 'page',
      previousUrl: null,
      nextUrl: null,
    },
  },
})

describe('PreventivePlansPage', () => {
  it('abre un modal al editar y precarga los valores actuales del plan', async () => {
    const wrapper = mount(PreventivePlansPage, { props: { data: baseData() } })

    expect(wrapper.find('[data-testid="edit-plan-modal"]').exists()).toBe(false)
    await wrapper.find('[data-testid="edit-plan-77"]').trigger('click')

    const modal = wrapper.find('[data-testid="edit-plan-modal"]')
    expect(modal.exists()).toBe(true)
    expect(modal.text()).toContain('Cambio de aceite de motor')
    expect(modal.text()).toContain('AC532DD')
    expect(modal.find('form').attributes('action')).toBe('/mantenimiento/planes/77/editar')
    expect(modal.find('#edit-km-77').element.value).toBe('20000')
    expect(modal.find('#edit-wkm-77').element.value).toBe('1000')
    expect(modal.find('#edit-bkm-77').element.value).toBe('120000')
    expect(modal.find('#edit-days-77').element.value).toBe('365')
    expect(modal.find('#edit-bdate-77').element.value).toBe('2026-08-01')
    expect(modal.find('#edit-priority-77').element.value).toBe('ALTA')
    expect(modal.find('#edit-notes-77').element.value).toBe('Propuesta general.')
  })

  it('cancela la edición sin modificar los datos del plan', async () => {
    const data = baseData()
    const wrapper = mount(PreventivePlansPage, { props: { data } })

    await wrapper.find('[data-testid="edit-plan-77"]').trigger('click')
    await wrapper.find('#edit-km-77').setValue('25000')
    await wrapper.find('[data-testid="edit-plan-modal"] button[type="button"]:last-of-type').trigger('click')

    expect(wrapper.find('[data-testid="edit-plan-modal"]').exists()).toBe(false)
    expect(data.plans.items[0].criteria.kilometers.interval).toBe(20000)
  })

  it('oculta campos de horómetro cuando el equipo no controla horas', async () => {
    const wrapper = mount(PreventivePlansPage, { props: { data: baseData() } })
    await wrapper.find('[data-testid="edit-plan-77"]').trigger('click')

    expect(wrapper.find('#edit-hours-77').exists()).toBe(false)
    expect(wrapper.find('#edit-km-77').exists()).toBe(true)
  })
})
