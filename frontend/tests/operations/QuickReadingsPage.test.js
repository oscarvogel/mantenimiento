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
  catalogs: { branches: [{ id: 1, name: 'Casa central' }], types: [{ id: 1, name: 'Camión' }] },
  equipment: {
    total: count,
    pagination: { page: 1, totalPages: 1, total: count, perPage: 10, perPageOptions: [5, 10, 25], pageKey: 'page', perPageKey: 'per_page', previousUrl: null, nextUrl: null },
    items: Array.from({ length: count }, (_, index) => ({ id: index + 1, code: `CAM-${String(index + 1).padStart(2, '0')}`, plate: `AA${index + 1}`, typeName: 'Camión', branchName: 'Casa central', controlsKm: true, controlsHours: true, currentKm: 1000 + index, currentHours: '1250.4', detailUrl: `/mantenimiento/equipos/${index + 1}` })),
  },
})
const render = (data = dataFor()) => mount(QuickReadingsPage, { props: { data }, attachTo: document.body })

afterEach(() => {
  vi.unstubAllGlobals()
  document.body.innerHTML = ''
})

describe('QuickReadingsPage', () => {
  it('deshabilita guardar cuando no hay lecturas', () => {
    const wrapper = render()
    const button = wrapper.get('form[method="post"][action="/mantenimiento/lecturas/rapidas"] button[type="submit"]')
    expect(button.attributes('disabled')).toBeDefined()
    expect(button.text()).toContain('Ingresá al menos una lectura')
  })

  it('indica la cantidad de lecturas listas para guardar', async () => {
    const wrapper = render(dataFor(3))
    await wrapper.get('input[name="readings[1][kilometers]"]').setValue('1100')
    await wrapper.get('input[name="readings[2][hours]"]').setValue('1251,4')
    await nextTick()
    expect(wrapper.get('form[method="post"][action="/mantenimiento/lecturas/rapidas"] button[type="submit"]').text()).toContain('Guardar 2 lecturas')
  })

  it('muestra progreso real mientras guarda cuatro filas', async () => {
    const pending = [deferred(), deferred(), deferred(), deferred()]
    let call = 0
    vi.stubGlobal('fetch', vi.fn(() => pending[call++].promise))
    const wrapper = render(dataFor(4))
    for (const id of [1, 2, 3, 4]) await wrapper.get(`input[name="readings[${id}][hours]"]`).setValue(`125${id},4`)

    const submit = wrapper.get('form[method="post"][action="/mantenimiento/lecturas/rapidas"]')
    const saving = submit.trigger('submit')
    await nextTick()
    expect(wrapper.get('form[method="post"][action="/mantenimiento/lecturas/rapidas"] button[type="submit"]').text()).toContain('Guardando 1 de 4…')
    pending[0].resolve(jsonResponse(successPayload(1, '1251.4')))
    await flushPromises()
    expect(wrapper.get('form[method="post"][action="/mantenimiento/lecturas/rapidas"] button[type="submit"]').text()).toContain('Guardando 2 de 4…')
    pending[1].resolve(jsonResponse(successPayload(2, '1252.4')))
    pending[2].resolve(jsonResponse(successPayload(3, '1253.4')))
    pending[3].resolve(jsonResponse(successPayload(4, '1254.4')))
    await saving
  })

  it('identifica el equipo y conserva el delta antes de limpiar la fila', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(successPayload(1, '1258.4'))))
    const wrapper = render(dataFor(1))
    await wrapper.get('input[name="readings[1][hours]"]').setValue('1258,4')
    await wrapper.get('form[method="post"][action="/mantenimiento/lecturas/rapidas"]').trigger('submit')
    await flushPromises()
    expect(wrapper.text()).toContain('CAM-01')
    expect(wrapper.text()).toContain('Horómetro actualizado a 1.258,4 h')
    expect(wrapper.text()).toContain('+8,0 h desde la lectura anterior')
    expect(wrapper.text()).toContain('1 mantenimiento quedó vencido.')
    expect(wrapper.get('input[name="readings[1][hours]"]').element.value).toBe('')
  })

  it('asocia el error de validación al equipo correcto', async () => {
    vi.stubGlobal('fetch', vi.fn()
      .mockResolvedValueOnce(jsonResponse({ error: 'La lectura retrocede respecto del último valor.', csrf: { name: 'csrf_test_name', hash: 'next-token' } }, 422))
      .mockResolvedValueOnce(jsonResponse(successPayload(2, '1252.4'))))
    const wrapper = render(dataFor(2))
    await wrapper.get('input[name="readings[1][hours]"]').setValue('1249,4')
    await wrapper.get('input[name="readings[2][hours]"]').setValue('1252,4')
    await wrapper.get('form[method="post"][action="/mantenimiento/lecturas/rapidas"]').trigger('submit')
    await flushPromises()
    const text = wrapper.text()
    expect(text).toContain('CAM-01')
    expect(text).toContain('La lectura retrocede respecto del último valor.')
    expect(text).toContain('CAM-02')
    expect(wrapper.text()).toContain('Guardada')
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

  it('aplica la fecha común solo a las filas sin fecha personalizada', async () => {
    const wrapper = render(dataFor(2))
    const common = wrapper.findAll('input[type="datetime-local"]')[0]
    await common.setValue('2026-08-16T11:30')
    await common.trigger('change')
    expect(wrapper.vm.rows[1].recordedAt).toBe('2026-08-16T11:30')
    expect(wrapper.vm.rows[2].recordedAt).toBe('2026-08-16T11:30')
  })

  it('mantiene la fecha individual cuando cambia la fecha común', async () => {
    const wrapper = render(dataFor(2))
    const checkbox = wrapper.findAll('input[type="checkbox"]')[0]
    await checkbox.setValue(true)
    const individual = wrapper.findAll('input[type="datetime-local"]')[1]
    await individual.setValue('2026-08-16T08:00')
    const common = wrapper.findAll('input[type="datetime-local"]')[0]
    await common.setValue('2026-08-17T12:00')
    await common.trigger('change')
    expect(wrapper.vm.rows[1].recordedAt).toBe('2026-08-16T08:00')
    expect(wrapper.vm.rows[2].recordedAt).toBe('2026-08-17T12:00')
  })

  it('mueve Enter al siguiente campo operativo sin enviar el formulario', async () => {
    const wrapper = render(dataFor(2))
    const inputs = wrapper.findAll('[data-reading-input="true"]')
    await inputs[0].trigger('keydown.enter')
    expect(document.activeElement).toBe(inputs[1].element)
  })

  it('no produce un submit accidental al pulsar Enter', async () => {
    const wrapper = render(dataFor(2))
    const form = wrapper.get('form[method="post"][action="/mantenimiento/lecturas/rapidas"]')
    const submitSpy = vi.fn()
    form.element.addEventListener('submit', submitSpy)
    const fetchSpy = vi.fn()
    vi.stubGlobal('fetch', fetchSpy)
    await wrapper.find('[data-reading-input="true"]').trigger('keydown.enter')
    expect(submitSpy).not.toHaveBeenCalled()
    expect(fetchSpy).not.toHaveBeenCalled()
  })
})
