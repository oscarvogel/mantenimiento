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

  const mountingOptions = (overrides = {}) => ({
    canMount: true,
    canRemove: false,
    blockedReason: null,
    equipment: [{ id: 40, branchId: 1, label: 'AB123CD · M40', plate: 'AB123CD', code: 'M40' }],
    maxAxle: 10,
    sides: [{ value: 'I', label: 'Izquierdo' }, { value: 'D', label: 'Derecho' }],
    slots: [{ value: 'E', label: 'Exterior' }, { value: 'I', label: 'Interior' }, { value: 'S', label: 'Simple' }],
    ...overrides,
  })

  it('sin colocar ofrece colocarlo con eje, lado, rueda y km', () => {
    const wrapper = mountPage({ location: null, mounting: mountingOptions(), urls: { ...data().urls, mount: '/neumaticos/7/colocar' } })

    const form = wrapper.find('form#colocar')
    expect(form.attributes('action')).toBe('/neumaticos/7/colocar')
    expect(form.findAll('#tire-mount-axle option')).toHaveLength(10)
    expect(form.findAll('#tire-mount-side option').map((option) => option.text())).toEqual(['Izquierdo', 'Derecho'])
    expect(form.findAll('#tire-mount-slot option').map((option) => option.text())).toEqual(['Exterior', 'Interior', 'Simple'])
    expect(form.find('#tire-mount-km').attributes('required')).toBeDefined()
    expect(wrapper.findComponent({ name: 'AutocompleteSelect' }).props('options')).toEqual([{ value: 40, label: 'AB123CD · M40' }])
    expect(wrapper.text()).toContain('Se registra una lectura de km del equipo.')
  })

  it('colocado muestra dónde está y ofrece extraerlo', () => {
    const wrapper = mountPage({
      location: { equipmentId: 40, equipmentLabel: 'AB123CD · M40', position: 'Eje 2 · izquierdo · exterior', mountedAt: '2026-11-02', mountedKm: 100000 },
      mounting: mountingOptions({ canMount: false, canRemove: true, equipment: [] }),
      urls: { ...data().urls, remove: '/neumaticos/7/extraer' },
    })

    expect(wrapper.text()).toContain('Colocado en AB123CD · M40, Eje 2 · izquierdo · exterior.')
    expect(wrapper.text()).toContain('Desde el 02/11/2026')
    expect(wrapper.find('form#extraer').attributes('action')).toBe('/neumaticos/7/extraer')
    expect(wrapper.find('form#colocar').exists()).toBe(false)
  })

  it('explica por qué no se puede colocar', () => {
    const wrapper = mountPage({
      location: null,
      mounting: mountingOptions({ canMount: false, blockedReason: 'Un neumático en estado "En reparación" no se puede colocar en un equipo.', equipment: [] }),
    })

    expect(wrapper.text()).toContain('Un neumático en estado "En reparación" no se puede colocar en un equipo.')
    expect(wrapper.find('form#colocar').exists()).toBe(false)
  })

  it('sin equipos activos lo avisa en vez de mostrar un formulario vacío', () => {
    const wrapper = mountPage({ location: null, mounting: mountingOptions({ equipment: [] }) })

    expect(wrapper.text()).toContain('No hay equipos activos en tus sucursales para colocarlo.')
    expect(wrapper.find('form#colocar').exists()).toBe(false)
  })

  it('el rendimiento nombra el equipo y la posición de cada colocación', () => {
    const wrapper = mountPage({
      performance: {
        total_km: 12500,
        total_label: '12.500 km',
        mountings: [{ equipment_label: 'AB123CD · M40', position_label: 'Eje 2 · izquierdo · exterior', mounted_at: '2026-11-02', removed_at: '2026-11-20', label: '12.500 km' }],
      },
    })

    expect(wrapper.text()).toContain('AB123CD · M40 · Eje 2 · izquierdo · exterior')
    expect(wrapper.text()).toContain('02/11/2026 → 20/11/2026')
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
