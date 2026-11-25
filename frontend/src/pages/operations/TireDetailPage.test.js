import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import TireDetailPage from './TireDetailPage.vue'
import { formatDateTimeDmy, tireStatusTone } from './tireStatus.js'

const data = (overrides = {}) => ({
  csrf: { name: 'csrf_test', hash: 'abc' },
  canOperate: true,
  urls: { index: '/neumaticos', update: '/neumaticos/7', status: '/neumaticos/7/estado' },
  neumatic: {
    id: 7,
    number: 'TSA 181917',
    status: 'EN_REPARACION',
    statusLabel: 'En reparación',
    isFinal: false,
    size: '295/80 R22.5',
    year: 2024,
    brandId: 3,
    brandName: 'Michelin',
    modelId: 5,
    modelName: 'X Multi D',
    coverTypeId: null,
    coverTypeName: null,
    branchId: 1,
    branchName: 'Oberá',
    createdAt: '2026-11-01 08:00:00',
  },
  nextStatuses: [{ value: 'REPARADO', label: 'Reparado' }, { value: 'BAJA_DEFINITIVA', label: 'Baja definitiva' }],
  log: [
    { from: 'NUEVA', fromLabel: 'Nueva', to: 'EN_REPARACION', toLabel: 'En reparación', at: '2026-11-20 14:35:00', reason: 'Pinchadura', userName: 'Carla Gómez' },
    { from: null, fromLabel: null, to: 'NUEVA', toLabel: 'Nueva', at: '2026-11-01 08:00:00', reason: 'Alta del neumático', userName: null },
  ],
  performance: { total_km: null, total_label: 'sin datos suficientes', mountings: [] },
  options: {
    brands: [{ id: 4, name: 'Bridgestone' }],
    models: [{ id: 6, name: 'R168', brandId: 4 }],
    coverTypes: [{ id: 2, name: 'Lisa' }],
  },
  ...overrides,
})

const mountPage = (overrides = {}) => mount(TireDetailPage, { props: { data: data(overrides) }, attachTo: document.body })

afterEach(() => {
  document.body.innerHTML = ''
})

describe('TireDetailPage', () => {
  it('muestra los datos, el estado y la vuelta al padrón', () => {
    const wrapper = mountPage()

    expect(wrapper.find('h1').text()).toBe('TSA 181917')
    expect(wrapper.text()).toContain('Oberá · alta 01/11/2026')
    expect(wrapper.text()).toContain('X Multi D')
    expect(wrapper.text()).toContain('Sin cargar')
    expect(wrapper.find('a[href="/neumaticos"]').text()).toContain('Volver al padrón')
  })

  it('ofrece solo las transiciones que manda el servidor y pide motivo', () => {
    const wrapper = mountPage()

    const form = wrapper.find('form[action="/neumaticos/7/estado"]')
    expect(form.findAll('select[name="estado"] option').map((option) => option.text())).toEqual(['Elegí el nuevo estado', 'Reparado', 'Baja definitiva'])
    expect(form.find('input[name="motivo"]').attributes('required')).toBeDefined()
    expect(form.find('input[name="fecha"]').attributes('type')).toBe('datetime-local')
  })

  it('en un estado final explica que no admite más cambios', () => {
    const wrapper = mountPage({ neumatic: { ...data().neumatic, status: 'VENDIDA', statusLabel: 'Vendida', isFinal: true }, nextStatuses: [] })

    expect(wrapper.find('form[action="/neumaticos/7/estado"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Es un estado final')
  })

  it('el historial va del cambio más reciente al alta, con motivo y usuario', () => {
    const wrapper = mountPage()

    const entries = wrapper.findAll('ol li')
    expect(entries[0].text()).toContain('Nueva → En reparación')
    expect(entries[0].text()).toContain('Pinchadura')
    expect(entries[0].text()).toContain('20/11/2026 14:35 · Carla Gómez')
    expect(entries[1].text()).toContain('Alta del neumático')
  })

  it('sin colocaciones dice sin datos suficientes en lugar de un número', () => {
    const wrapper = mountPage()

    expect(wrapper.text()).toContain('Kilómetros recorridos: sin datos suficientes')
    expect(wrapper.text()).toContain('Todavía no tiene colocaciones en equipos registradas.')
  })

  it('al editar conserva la marca y el modelo actuales aunque estén inactivos', async () => {
    const wrapper = mountPage()

    await wrapper.findAll('button').find((button) => button.text().includes('Editar datos')).trigger('click')
    const dialog = document.querySelector('[role="dialog"]')

    expect(dialog.querySelector('#tire-edit-brand').value).toBe('3')
    expect([...dialog.querySelectorAll('#tire-edit-brand option')].map((option) => option.textContent)).toEqual(['Sin marca', 'Michelin (inactiva)', 'Bridgestone'])
    expect(dialog.querySelector('#tire-edit-model').value).toBe('5')
    expect(dialog.querySelector('form').getAttribute('action')).toBe('/neumaticos/7')
    expect(dialog.querySelector('input[name="numero"]')).toBeNull()
  })

  it('quien solo consulta no ve ni la edición ni el cambio de estado', () => {
    const wrapper = mountPage({ canOperate: false, nextStatuses: [] })

    expect(wrapper.text()).not.toContain('Editar datos')
    expect(wrapper.find('form').exists()).toBe(false)
    expect(wrapper.text()).toContain('En reparación')
  })
})

describe('tireStatus', () => {
  it('da un tono por grupo de estado', () => {
    expect(tireStatusTone('RECAPADA')).toContain('success')
    expect(tireStatusTone('EN_REPARACION')).toContain('warning')
    expect(tireStatusTone('BAJA_DEFINITIVA')).toContain('ink-muted')
  })

  it('formatea fecha y hora sin inventar una hora de medianoche', () => {
    expect(formatDateTimeDmy('2026-11-20 14:35:00')).toBe('20/11/2026 14:35')
    expect(formatDateTimeDmy('2026-11-01 00:00:00')).toBe('01/11/2026')
    expect(formatDateTimeDmy('2026-11-01')).toBe('01/11/2026')
    expect(formatDateTimeDmy(null)).toBe('')
  })
})
