import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import QuickReadingsPage from './QuickReadingsPage.vue'

const baseData = () => ({
  csrf: { name: 'csrf_test', hash: 'HASH123' },
  canRegister: true,
  canGenerateOrder: false,
  recordedAtDefault: '2026-08-17T09:30',
  routes: {
    index: '/mantenimiento/lecturas/rapidas',
    submit: '/mantenimiento/lecturas/rapidas',
    submitRow: '/mantenimiento/lecturas/rapidas/fila',
    generateOrderBase: '/mantenimiento/lecturas/rapidas/avisos',
    workOrderBase: '/mantenimiento/ordenes',
  },
  filters: { q: '', branchId: '', typeId: '', perPage: 25 },
  catalogs: {
    branches: [{ id: 1, name: 'TSA Argentina' }],
    types: [{ id: 1, name: 'Camión' }, { id: 2, name: 'Máquina' }],
  },
  equipment: {
    total: 2,
    items: [
      {
        id: 10, code: 'AB499OK', plate: 'AB-499-OK', chassis: 'CH-10', typeName: 'Camión', branchName: 'TSA Argentina',
        controlsKm: true, controlsHours: false, currentKm: 125430, currentHours: null, lastReadingAt: '2026-08-16 08:00:00',
      },
      {
        id: 20, code: 'MOT-03', plate: null, chassis: 'CH-20', typeName: 'Máquina', branchName: 'TSA Argentina',
        controlsKm: false, controlsHours: true, currentKm: null, currentHours: '8340.0', lastReadingAt: '2026-08-16 08:15:00',
      },
    ],
    pagination: { page: 1, totalPages: 1, total: 2, previousUrl: null, nextUrl: null, pageKey: 'page', perPageKey: 'per_page', perPage: 25 },
  },
  results: [],
})

afterEach(() => { vi.restoreAllMocks(); vi.unstubAllGlobals() })

describe('QuickReadingsPage', () => {
  it('renderiza una grilla compacta y elimina los controles secundarios de las tarjetas anteriores', () => {
    const wrapper = mount(QuickReadingsPage, { props: { data: baseData() } })
    expect(wrapper.find('table').exists()).toBe(true)
    expect(wrapper.findAll('tbody tr')).toHaveLength(2)
    expect(wrapper.text()).toContain('AB499OK')
    expect(wrapper.text()).toContain('125.430 km')
    expect(wrapper.text()).toContain('8.340 h')
    expect(wrapper.text()).not.toContain('Observación opcional')
    expect(wrapper.text()).not.toContain('Usar fecha individual')
    expect(wrapper.text()).not.toContain('Ver equipos')
    expect(wrapper.findAll('input[type="datetime-local"]')).toHaveLength(1)
    expect(wrapper.findAll('[data-reading-input="true"]')).toHaveLength(2)
  })

  it('patrón Carga: encabezado compartido, estados en píldoras y una fila por equipo', async () => {
    const wrapper = mount(QuickReadingsPage, { props: { data: baseData() } })

    expect(wrapper.get('h1').text()).toBe('Registrar km/horas')
    expect(wrapper.text()).toContain('Campo')
    const chips = wrapper.get('[role="group"][aria-label="Estado del plan preventivo"]').findAll('button')
    expect(chips[0].classes()).toContain('rounded-pill')
    expect(chips[0].attributes('aria-pressed')).toBe('true')
    // Cada equipo es una fila redondeada; la tabla no usa .ui-table, que pintaba celdas sueltas en el celular.
    expect(wrapper.get('table').classes()).not.toContain('ui-table')
    expect(wrapper.get('tbody tr').classes()).toContain('rounded-[18px]')
    await chips[1].trigger('click')
    expect(chips[1].attributes('aria-pressed')).toBe('true')
  })

  it('filtra inmediatamente los móviles visibles mientras se escribe', async () => {
    const wrapper = mount(QuickReadingsPage, { props: { data: baseData() } })
    await wrapper.find('input[type="search"]').setValue('CH-20')
    expect(wrapper.findAll('tbody tr')).toHaveLength(1)
    expect(wrapper.text()).toContain('MOT-03')
    expect(wrapper.text()).not.toContain('AB499OK')
  })

  it('permite desplegar los filtros y la fecha sin duplicar el formulario', async () => {
    const wrapper = mount(QuickReadingsPage, { props: { data: baseData() } })
    const disclosure = wrapper.get('button[aria-controls="quick-reading-options"]')
    const options = wrapper.get('#quick-reading-options')

    expect(disclosure.attributes('aria-expanded')).toBe('false')
    expect(options.classes()).toContain('hidden')
    await disclosure.trigger('click')
    expect(disclosure.attributes('aria-expanded')).toBe('true')
    expect(options.classes()).not.toContain('hidden')
    expect(wrapper.findAll('input[type="datetime-local"]')).toHaveLength(1)
  })

  it('busca código o patente sin distinguir mayúsculas, espacios ni guiones', async () => {
    const wrapper = mount(QuickReadingsPage, { props: { data: baseData() } })
    const search = wrapper.find('input[type="search"]')

    await search.setValue('ab499ok')
    expect(wrapper.findAll('tbody tr')).toHaveLength(1)
    expect(wrapper.text()).toContain('AB499OK')

    await search.setValue('ab 499 ok')
    expect(wrapper.findAll('tbody tr')).toHaveLength(1)
    expect(wrapper.text()).toContain('AB-499-OK')
  })

  it('explica qué dato falta cuando un plan preventivo queda SIN_DATOS', () => {
    const data = baseData()
    data.equipment.items[0].maintenance = {
      state: 'PROBLEMA',
      planCount: 2,
      primaryPlan: {
        planId: 1, serviceName: 'Cambio de aceite de motor', state: 'SIN_DATOS', displayState: 'PROBLEMA',
        baseKm: null, baseHours: null, baseDate: null, nextKm: null, nextHours: null, nextDate: null,
        missingCriteria: ['KILOMETRAJE'], critical: null,
      },
      plans: [
        {
          planId: 1, serviceName: 'Cambio de aceite de motor', state: 'SIN_DATOS', displayState: 'PROBLEMA',
          baseKm: null, baseHours: null, baseDate: null, nextKm: null, nextHours: null, nextDate: null,
          missingCriteria: ['KILOMETRAJE'], critical: null,
        },
        {
          planId: 2, serviceName: 'Filtros de combustible', state: 'SIN_DATOS', displayState: 'PROBLEMA',
          baseKm: 120000, baseHours: null, baseDate: null, nextKm: 140000, nextHours: null, nextDate: null,
          missingCriteria: ['FECHA'], critical: null,
        },
      ],
    }

    const wrapper = mount(QuickReadingsPage, { props: { data } })
    expect(wrapper.text()).toContain('Falta última realización en km')
    expect(wrapper.text()).toContain('Ver faltantes')
    expect(wrapper.text()).toContain('Cambio de aceite de motor: Falta última realización en km')
    expect(wrapper.text()).toContain('Filtros de combustible: Falta fecha de última realización')
  })

  it('valida una lectura menor a la actual sin impedir guardar otras filas válidas', async () => {
    const wrapper = mount(QuickReadingsPage, { props: { data: baseData() } })
    const input = wrapper.get('#quick-reading-10')
    await input.setValue('120000')
    await wrapper.find('#quick-reading-20').setValue('8350,5')
    expect(wrapper.get('label[for="quick-reading-10"]').text()).toContain('AB499OK')
    expect(input.attributes('aria-invalid')).toBe('true')
    expect(input.attributes('aria-describedby')).toContain('quick-reading-feedback-10')
    expect(wrapper.get('#quick-reading-feedback-10').text()).toContain('No puede ser menor a 125.430 km.')
    expect(wrapper.text()).toContain('1 lectura lista para guardar')
    expect(wrapper.find('button[type="submit"]').text()).toContain('Guardar 1 lectura')
    expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeUndefined()
  })

  it('guarda sólo las filas válidas y conserva el procesamiento independiente', async () => {
    const data = baseData()
    const fetchMock = vi.fn().mockResolvedValue({
      status: 200, ok: true, url: data.routes.submitRow, headers: { get: () => 'application/json' },
      text: async () => JSON.stringify({
        result: { rowNumber: 1, equipmentId: 20, success: true, message: 'Lectura registrada.', currentKilometers: null, currentHours: 8350.5, plansEvaluated: 0, overduePlans: 0 },
        csrf: { name: 'csrf_test', hash: 'HASH456' },
      }),
    })
    vi.stubGlobal('fetch', fetchMock)
    const wrapper = mount(QuickReadingsPage, { props: { data } })
    await wrapper.find('#quick-reading-10').setValue('120000')
    await wrapper.find('#quick-reading-20').setValue('8350,5')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(fetchMock).toHaveBeenCalledTimes(1)
    const body = fetchMock.mock.calls[0][1].body
    expect(body.get('equipmentId')).toBe('20')
    expect(body.get('hours')).toBe('8350.5')
    expect(wrapper.text()).toContain('Lectura registrada.')
    expect(wrapper.text()).toContain('No puede ser menor a 125.430 km.')
  })
})
