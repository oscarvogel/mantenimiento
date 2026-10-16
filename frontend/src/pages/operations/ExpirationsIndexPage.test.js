import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ExpirationsIndexPage from './ExpirationsIndexPage.vue'

const baseData = () => ({
  csrf: { name: 'csrf_test', hash: 'HASH123' },
  canSeeEquipment: true,
  canSeeEmployees: true,
  canEditEquipment: true,
  canEditEmployees: true,
  canManageTypes: true,
  routes: {
    index: '/mantenimiento/vencimientos',
    types: '/mantenimiento/maestros/vencimientos',
  },
  items: [],
  branches: [
    { id: 1, name: 'TSA Argentina' },
    { id: 2, name: 'TSA Brasil' },
  ],
  expirationTypes: [
    { id: 11, name: 'CRVL', appliesTo: 'EQUIPO', active: true },
    { id: 12, name: 'VTV / ITV', appliesTo: 'EQUIPO', active: true },
  ],
  summary: { total: 0, overdue: 0, next7: 0, next30: 0 },
  filters: {
    subject: 'EQUIPO',
    status: '30',
    branchId: 2,
    expirationTypeId: 11,
    q: '',
  },
})

describe('ExpirationsIndexPage', () => {
  it('separa el sujeto del tipo de documentación y conserva la selección', () => {
    const wrapper = mount(ExpirationsIndexPage, { props: { data: baseData() } })

    const subject = wrapper.find('#expiration-subject')
    const documentation = wrapper.find('#expiration-document-type')

    expect(subject.exists()).toBe(true)
    expect(documentation.exists()).toBe(true)
    expect(subject.attributes('name')).toBe('tipo')
    expect(documentation.attributes('name')).toBe('tipo_vencimiento_id')
    expect(subject.element.value).toBe('EQUIPO')
    expect(documentation.element.value).toBe('11')
    expect(documentation.findAll('option').map((option) => option.text())).toEqual([
      'Todos los documentos',
      'CRVL',
      'VTV / ITV',
    ])
  })

  it('muestra fechas día/mes/año y permite corregirlas desde el listado', async () => {
    const data = baseData()
    data.items = [{
      id: 42,
      subjectType: 'EQUIPO',
      subjectName: 'AF117ZK',
      subjectSubtitle: 'Equipo / móvil',
      typeName: 'VTV / ITV',
      expiresAt: '2027-10-12',
      issuedAt: '2026-10-12',
      daysUntil: 364,
      status: 'VIGENTE',
      branchName: 'TSA Argentina',
      documentNumber: '',
      notes: '',
      updateUrl: '/mantenimiento/vencimientos/42',
      renewUrl: '/mantenimiento/vencimientos/42/renovar',
      detailUrl: '/mantenimiento/equipos/7',
    }]

    const wrapper = mount(ExpirationsIndexPage, { props: { data } })

    expect(wrapper.text()).toContain('12/10/2027')
    const editButton = wrapper.findAll('button').find((button) => button.text().trim() === 'Editar')
    expect(editButton).toBeTruthy()
    await editButton.trigger('click')

    expect(document.body.querySelector('#edit-expiration-date')?.value).toBe('12/10/2027')
  })

  it('oculta la edición cuando el usuario no tiene permiso sobre el equipo', () => {
    const data = baseData()
    data.canEditEquipment = false
    data.items = [{
      id: 42,
      subjectType: 'EQUIPO',
      subjectName: 'AF117ZK',
      subjectSubtitle: 'Equipo / móvil',
      typeName: 'VTV / ITV',
      expiresAt: '2027-10-12',
      daysUntil: 364,
      status: 'VIGENTE',
      branchName: 'TSA Argentina',
      documentNumber: '',
      notes: '',
      updateUrl: '/mantenimiento/vencimientos/42',
      renewUrl: '/mantenimiento/vencimientos/42/renovar',
      detailUrl: '/mantenimiento/equipos/7',
    }]

    const wrapper = mount(ExpirationsIndexPage, { props: { data } })

    expect(wrapper.findAll('button').some((button) => button.text().trim() === 'Editar')).toBe(false)
  })
})
