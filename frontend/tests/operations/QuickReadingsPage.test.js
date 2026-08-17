import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import QuickReadingsPage from '../../src/pages/operations/QuickReadingsPage.vue'

const deferred = () => {
  let resolve
  const promise = new Promise((done) => { resolve = done })
  return { promise, resolve }
}
const jsonResponse = (payload, status = 200) => ({
  ok: status >= 200 && status < 300,
  status,
  url: '/mantenimiento/lecturas/rapidas/fila',
  headers: { get: () => 'application/json' },
  text: async () => JSON.stringify(payload),
})
const htmlResponse = (status = 200, url = '/login') => ({
  ok: status >= 200 && status < 300,
  status,
  url,
  headers: { get: () => 'text/html; charset=UTF-8' },
  text: async () => '<html>respuesta</html>',
})
const successPayload = (equipmentId, currentHours = '1258.4') => ({
  csrf: { name: 'csrf_test_name', hash: 'next-token' },
  result: { rowNumber: 1, equipmentId, success: true, message: 'Lectura registrada y planes reevaluados.', currentKilometers: null, currentHours, plansEvaluated: 4, overduePlans: 1, noticeIds: [] },
})
const dataFor = (count = 2) => ({
  csrf: { name: 'csrf_test_name', hash: 'secure-token' },
  results: [],
  recordedAtDefault: '2026-08-15T10:00',
  filters: { q: '', branchId: '', typeId: '' },
  routes: { index: '/mantenimiento/lecturas/rapidas', submit: '/mantenimiento/lecturas/rapidas', submitRow: '/mantenimiento/lecturas/rapidas/fila', assets: '/mantenimiento/equipos' },
  canRegister: true,
  catalogs: { branches: [{ id: 1, name: 'Casa central' }], types: [{ id: 1, name: 'Máquina' }] },
  equipment: {
    total: count,
    pagination: { page: 1, totalPages: 1, total: count, perPage: 10, perPageOptions: [5, 10, 25], pageKey: 'page', perPageKey: 'per_page', previousUrl: null, nextUrl: null },
    items: Array.from({ length: count }, (_, index) => ({ id: index + 1, code: `MOT-${String(index + 1).padStart(2, '0')}`, plate: `AA${index + 1}`, chassis: `CH-${index + 1}`, typeName: 'Máquina', branchName: 'Casa central', controlsKm: false, controlsHours: true, currentKm: 1000 + index, currentHours: '1250.4', detailUrl: `/mantenimiento/equipos/${index + 1}` })),
  },
})
const render = (data = dataFor()) => mount(QuickReadingsPage, { props: { data }, attachTo: document.body })

afterEach(() => {
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
})

describe('QuickReadingsPage', () => {
  it('renderiza una grilla compacta con un único input operativo por equipo', () => {
    const wrapper = render(dataFor(3))
    expect(wrapper.text()).toContain('Lecturas rápidas')
    expect(wrapper.text()).toContain('Última lectura')
    expect(wrapper.text()).toContain('Nueva lectura')
    expect(wrapper.findAll('[data-reading-input="true"]')).toHaveLength(3)
    expect(wrapper.find('input[name="readings[1][kilometers]"]').exists()).toBe(false)
    expect(wrapper.find('input[name="readings[1][hours]"]').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('Observación opcional')
    expect(wrapper.text()).not.toContain('Usar fecha individual')
    expect(wrapper.text()).not.toContain('Ver equipos')
  })

  it('determina kilómetros para equipos que no controlan horómetro', () => {
    const data = dataFor(1)
    data.equipment.items[0] = { ...data.equipment.items[0], controlsKm: true, controlsHours: false, currentKm: 15400 }
    const wrapper = render(data)
    expect(wrapper.find('input[name="readings[1][kilometers]"]').exists()).toBe(true)
    expect(wrapper.find('input[name="readings[1][hours]"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('15.400 km')
  })

  it('deshabilita guardar cuando no hay lecturas', () => {
    const wrapper = render()
    const button = wrapper.get('form[method="post"][action="/mantenimiento/lecturas/rapidas"] button[type="submit"]')
    expect(button.attributes('disabled')).toBeDefined()
    expect(button.text()).toContain('Sin lecturas pendientes')
  })

  it('indica la cantidad de lecturas listas para guardar', async () => {
    const wrapper = render(dataFor(3))
    await wrapper.get('input[name="readings[1][hours]"]').setValue('1251,4')
    await wrapper.get('input[name="readings[2][hours]"]').setValue('1252,4')
    await nextTick()
    expect(wrapper.text()).toContain('2 lecturas pendientes')
    expect(wrapper.get('form[method="post"][action="/mantenimiento/lecturas/rapidas"] button[type="submit"]').text()).toContain('Guardar 2 lecturas')
  })

  it('filtra instantáneamente por código, patente o chasis sin botón Filtrar', async () => {
    const wrapper = render(dataFor(3))
    expect(wrapper.text()).not.toContain('Filtrar')
    const search = wrapper.get('input[type="search"]')
    await search.setValue('CH-2')
    expect(wrapper.findAll('[data-reading-input="true"]')).toHaveLength(1)
    expect(wrapper.text()).toContain('MOT-02')
    expect(wrapper.text()).not.toContain('MOT-01')
  })

  it('bloquea en cliente una lectura menor a la última registrada', async () => {
    const wrapper = render(dataFor(1))
    await wrapper.get('input[name="readings[1][hours]"]').setValue('1249,4')
    await nextTick()
    expect(wrapper.text()).toContain('No puede ser menor a 1.250,4 h.')
    expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined()
  })

  it('muestra progreso real mientras guarda cuatro filas', async () => {
    const pending = [deferred(), deferred(), deferred(), deferred()]
    let call = 0
    vi.stubGlobal('fetch', vi.fn(() => pending[call++].promise))
    const wrapper = render(dataFor(4))
    for (const id of [1, 2, 3, 4]) await wrapper.get(`input[name="readings[${id}][hours]"]`).setValue(`125${id + 1},4`)

    const submit = wrapper.get('form[method="post"][action="/mantenimiento/lecturas/rapidas"]')
    const saving = submit.trigger('submit')
    await nextTick()
    expect(wrapper.get('button[type="submit"]').text()).toContain('Guardando 1 de 4…')
    pending[0].resolve(jsonResponse(successPayload(1, '1252.4')))
    await flushPromises()
    expect(wrapper.get('button[type="submit"]').text()).toContain('Guardando 2 de 4…')
    pending[1].resolve(jsonResponse(successPayload(2, '1253.4')))
    pending[2].resolve(jsonResponse(successPayload(3, '1254.4')))
    pending[3].resolve(jsonResponse(successPayload(4, '1255.4')))
    await saving
  })

  it('identifica el equipo y conserva el delta antes de limpiar la fila', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(successPayload(1, '1258.4'))))
    const wrapper = render(dataFor(1))
    await wrapper.get('input[name="readings[1][hours]"]').setValue('1258,4')
    await wrapper.get('form[method="post"][action="/mantenimiento/lecturas/rapidas"]').trigger('submit')
    await flushPromises()
    expect(wrapper.text()).toContain('MOT-01')
    expect(wrapper.text()).toContain('Horómetro actualizado a 1.258,4 h')
    expect(wrapper.text()).toContain('+8,0 h desde la lectura anterior')
    expect(wrapper.text()).toContain('1 mantenimiento quedó vencido.')
    expect(wrapper.get('input[name="readings[1][hours]"]').element.value).toBe('')
  })

  it('solo informa la métrica que se envió aunque el backend devuelva ambas actuales', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({
      csrf: { name: 'csrf_test_name', hash: 'next-token' },
      result: { ...successPayload(1, '1258.4').result, currentKilometers: 1200, currentHours: '1258.4', submittedKilometers: false, submittedHours: true },
    })))
    const wrapper = render(dataFor(1))
    await wrapper.get('input[name="readings[1][hours]"]').setValue('1258,4')
    await wrapper.get('form[method="post"][action="/mantenimiento/lecturas/rapidas"]').trigger('submit')
    await flushPromises()
    expect(wrapper.text()).toContain('Horómetro actualizado a 1.258,4 h')
    expect(wrapper.text()).not.toContain('Kilometraje actualizado')
    expect(wrapper.text()).not.toContain('+200 km')
  })

  it('asocia el error de validación al equipo correcto y conserva las demás filas', async () => {
    vi.stubGlobal('fetch', vi.fn()
      .mockResolvedValueOnce(jsonResponse({ error: 'La lectura fue rechazada por el servidor.', csrf: { name: 'csrf_test_name', hash: 'next-token' } }, 422))
      .mockResolvedValueOnce(jsonResponse(successPayload(2, '1253.4'))))
    const wrapper = render(dataFor(2))
    await wrapper.get('input[name="readings[1][hours]"]').setValue('1252,4')
    await wrapper.get('input[name="readings[2][hours]"]').setValue('1253,4')
    await wrapper.get('form[method="post"][action="/mantenimiento/lecturas/rapidas"]').trigger('submit')
    await flushPromises()
    const text = wrapper.text()
    expect(text).toContain('MOT-01')
    expect(text).toContain('La lectura fue rechazada por el servidor.')
    expect(text).toContain('MOT-02')
    expect(text).toContain('Guardada')
  })

  it('distingue una respuesta HTML de una falla de red', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => htmlResponse()))
    const wrapper = render(dataFor(1))
    await wrapper.get('input[name="readings[1][hours]"]').setValue('1251,4')
    await wrapper.get('form[method="post"][action="/mantenimiento/lecturas/rapidas"]').trigger('submit')
    await flushPromises()
    expect(wrapper.text()).toContain('La sesión pudo haber vencido')
    expect(wrapper.text()).not.toContain('No se pudo conectar con el servidor')
  })

  it('aplica la fecha común a las filas de la carga', async () => {
    const wrapper = render(dataFor(2))
    const common = wrapper.get('input[type="datetime-local"]')
    await common.setValue('2026-08-16T11:30')
    await common.trigger('change')
    expect(wrapper.vm.rows[1].recordedAt).toBe('2026-08-16T11:30')
    expect(wrapper.vm.rows[2].recordedAt).toBe('2026-08-16T11:30')
    expect(wrapper.findAll('input[type="datetime-local"]')).toHaveLength(1)
  })

  it('mueve Enter al siguiente equipo sin enviar el formulario', async () => {
    const wrapper = render(dataFor(2))
    const inputs = wrapper.findAll('[data-reading-input="true"]')
    inputs[1].element.getClientRects = () => [{ width: 1 }]
    inputs[0].element.getClientRects = () => [{ width: 1 }]
    Object.defineProperty(inputs[0].element, 'offsetParent', { configurable: true, get: () => document.body })
    Object.defineProperty(inputs[1].element, 'offsetParent', { configurable: true, get: () => document.body })
    const form = wrapper.get('form[method="post"][action="/mantenimiento/lecturas/rapidas"]')
    const submitSpy = vi.fn()
    form.element.addEventListener('submit', submitSpy)
    await inputs[0].trigger('keydown.enter')
    expect(document.activeElement).toBe(inputs[1].element)
    expect(submitSpy).not.toHaveBeenCalled()
  })
})
