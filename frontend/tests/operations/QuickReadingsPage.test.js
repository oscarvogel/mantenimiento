import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import QuickReadingsPage from '../../src/pages/operations/QuickReadingsPage.vue'

const response = (payload, status = 200, url = '/mantenimiento/lecturas/rapidas/fila') => ({
  ok: status >= 200 && status < 300, status, url,
  headers: { get: () => 'application/json' }, text: async () => JSON.stringify(payload),
})
const maintenance = (state = 'PROXIMO', overrides = {}) => ({
  state,
  planCount: 1,
  primaryPlan: {
    planId: 10, serviceName: 'Service motor', state, displayState: state,
    baseKm: 985624, baseHours: null, baseDate: null,
    nextKm: 995624, nextHours: null, nextDate: null,
    critical: { value: state === 'VENCIDO' ? -7514 : 6124, unit: 'km' },
    noticeId: state === 'VENCIDO' ? 44 : null, order: null,
    ...overrides,
  },
  plans: [],
})
const dataFor = (count = 2) => ({
  csrf: { name: 'csrf_test_name', hash: 'secure-token' }, results: [], canRegister: true, canGenerateOrder: true,
  recordedAtDefault: '2026-08-17T15:00', filters: { q: '', branchId: '', typeId: '', perPage: 50 },
  routes: {
    index: '/mantenimiento/lecturas/rapidas', submit: '/mantenimiento/lecturas/rapidas', submitRow: '/mantenimiento/lecturas/rapidas/fila',
    generateOrderBase: '/mantenimiento/lecturas/rapidas/avisos', workOrderBase: '/mantenimiento/ordenes', assets: '/mantenimiento/equipos',
  },
  catalogs: { branches: [{ id: 1, name: 'Central' }], types: [{ id: 1, name: 'Camión' }] },
  equipment: {
    total: count,
    pagination: { page: 1, totalPages: 1, total: count, perPage: 50, pageKey: 'page', perPageKey: 'per_page', previousUrl: null, nextUrl: null },
    items: Array.from({ length: count }, (_, index) => ({
      id: index + 1, code: `CAM-${index + 1}`, plate: `AA${index + 1}`, chassis: `CH-${index + 1}`,
      typeName: 'Camión', branchName: 'Central', controlsKm: true, controlsHours: true,
      currentKm: 988754 + index, currentHours: '1250.4', lastReadingAt: '2026-08-16 10:00:00',
      maintenance: maintenance(index === 0 ? 'PROXIMO' : 'OK'),
    })),
  },
})
const render = (data = dataFor()) => mount(QuickReadingsPage, { props: { data }, attachTo: document.body })

afterEach(() => { vi.unstubAllGlobals(); document.body.innerHTML = '' })

describe('QuickReadingsPage', () => {
  it('muestra una sola entrada operativa por equipo y prioriza km si controla ambos contadores', () => {
    const wrapper = render(dataFor(3))
    expect(wrapper.findAll('[data-reading-input="true"]')).toHaveLength(3)
    expect(wrapper.get('#quick-reading-1').attributes('inputmode')).toBe('numeric')
    expect(wrapper.text()).toContain('Último service')
    expect(wrapper.text()).toContain('Próximo service')
  })

  it('usa horómetro como único contador cuando el equipo no controla km', () => {
    const data = dataFor(1)
    data.equipment.items[0].controlsKm = false
    data.equipment.items[0].controlsHours = true
    const wrapper = render(data)
    expect(wrapper.findAll('[data-reading-input="true"]')).toHaveLength(1)
    expect(wrapper.get('#quick-reading-1').attributes('inputmode')).toBe('decimal')
    expect(wrapper.text()).toContain('1.250,4 h')
  })

  it('filtra instantáneamente por patente o chasis sin navegar', async () => {
    const wrapper = render(dataFor(3))
    await wrapper.get('[data-quick-search]').setValue('CH-2')
    expect(wrapper.findAll('[data-reading-input="true"]')).toHaveLength(1)
    expect(wrapper.text()).toContain('CAM-2')
    expect(wrapper.text()).not.toContain('CAM-1')
  })

  it('valida lectura menor pero permite guardar las filas válidas', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => response({
      csrf: { name: 'csrf_test_name', hash: 'next' },
      result: { rowNumber: 1, equipmentId: 2, success: true, message: 'Lectura registrada.', currentKilometers: 989500, currentHours: '1250.4' },
      maintenance: maintenance('OK'),
    })))
    const wrapper = render(dataFor(2))
    await wrapper.get('#quick-reading-1').setValue('900000')
    await wrapper.get('#quick-reading-2').setValue('989500')
    await nextTick()
    expect(wrapper.text()).toContain('No puede ser menor')
    expect(wrapper.get('button[type="submit"]').text()).toContain('Guardar 1 lectura')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(fetch).toHaveBeenCalledTimes(1)
    expect(wrapper.get('#quick-reading-1').element.value).toBe('900000')
  })

  it('actualiza el mantenimiento en la misma fila después de guardar', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => response({
      csrf: { name: 'csrf_test_name', hash: 'next' },
      result: { rowNumber: 1, equipmentId: 1, success: true, message: 'Lectura registrada.', currentKilometers: 996000, currentHours: '1250.4' },
      maintenance: maintenance('VENCIDO', { critical: { value: -376, unit: 'km' }, noticeId: 44 }),
    })))
    const wrapper = render(dataFor(1))
    await wrapper.get('#quick-reading-1').setValue('996000')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(wrapper.text()).toContain('Vencido 376 km')
    expect(wrapper.text()).toContain('Generar OT')
  })

  it('genera la OT desde la fila y ofrece impresión inmediatamente', async () => {
    const data = dataFor(1)
    data.equipment.items[0].maintenance = maintenance('VENCIDO')
    vi.stubGlobal('fetch', vi.fn(async () => response({
      orderId: 1234, csrf: { name: 'csrf_test_name', hash: 'next' },
      maintenance: maintenance('VENCIDO', { noticeId: null, order: { id: 1234, number: 'OT-2026-1234', status: 'EMITIDA' } }),
    }, 200, '/mantenimiento/lecturas/rapidas/avisos/44/orden')))
    const wrapper = render(data)
    await wrapper.get('button').findAll
    const button = wrapper.findAll('button').find((item) => item.text().includes('Generar OT'))
    await button.trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('OT-2026-1234')
    expect(wrapper.get('a[href="/mantenimiento/ordenes/1234/imprimir"]').text()).toContain('Imprimir')
  })

  it('Enter avanza al siguiente móvil sin enviar', async () => {
    const wrapper = render(dataFor(2))
    const inputs = wrapper.findAll('[data-reading-input="true"]')
    Object.defineProperty(inputs[0].element, 'offsetParent', { configurable: true, get: () => document.body })
    Object.defineProperty(inputs[1].element, 'offsetParent', { configurable: true, get: () => document.body })
    const submit = vi.fn()
    wrapper.get('form').element.addEventListener('submit', submit)
    await inputs[0].trigger('keydown.enter')
    expect(document.activeElement).toBe(inputs[1].element)
    expect(submit).not.toHaveBeenCalled()
  })
})
