import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import QuickReadingsPage from './QuickReadingsPage.vue'

const baseData = () => ({
  csrf: { name: 'csrf_test', hash: 'HASH123' },
  canRegister: true,
  recordedAtDefault: '2026-08-17T09:30',
  routes: {
    index: '/mantenimiento/lecturas/rapidas',
    submit: '/mantenimiento/lecturas/rapidas',
    submitRow: '/mantenimiento/lecturas/rapidas/fila',
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
        id: 10,
        code: 'AB499OK',
        plate: 'AB499OK',
        typeName: 'Camión',
        branchName: 'TSA Argentina',
        controlsKm: true,
        controlsHours: false,
        currentKm: 125430,
        currentHours: null,
        lastReadingAt: '2026-08-16 08:00:00',
      },
      {
        id: 20,
        code: 'MOT-03',
        plate: null,
        typeName: 'Máquina',
        branchName: 'TSA Argentina',
        controlsKm: false,
        controlsHours: true,
        currentKm: null,
        currentHours: '8340.0',
        lastReadingAt: '2026-08-16 08:15:00',
      },
    ],
    pagination: {
      page: 1,
      totalPages: 1,
      total: 2,
      previousUrl: null,
      nextUrl: null,
      pageKey: 'page',
      perPageKey: 'per_page',
      perPage: 25,
    },
  },
  results: [],
})

afterEach(() => {
  vi.restoreAllMocks()
})

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
  })

  it('filtra inmediatamente los móviles visibles mientras se escribe', async () => {
    const wrapper = mount(QuickReadingsPage, { props: { data: baseData() } })
    const search = wrapper.find('input[type="search"]')

    await search.setValue('MOT-03')

    expect(wrapper.findAll('tbody tr')).toHaveLength(1)
    expect(wrapper.text()).toContain('MOT-03')
    expect(wrapper.text()).not.toContain('AB499OK')
    wrapper.unmount()
  })

  it('valida una lectura menor a la actual sin impedir guardar otras filas válidas', async () => {
    const wrapper = mount(QuickReadingsPage, { props: { data: baseData() } })

    await wrapper.find('#quick-10-km').setValue('120000')
    await wrapper.find('#quick-20-hours').setValue('8350,5')

    expect(wrapper.text()).toContain('No puede ser menor a 125.430 km.')
    expect(wrapper.text()).toContain('2 cargadas · 1 lista para guardar')
    expect(wrapper.find('button[type="submit"]').text()).toContain('Guardar 1 lectura')
    expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeUndefined()
  })

  it('guarda sólo las filas válidas y conserva el procesamiento independiente', async () => {
    const data = baseData()
    const fetchMock = vi.fn().mockResolvedValue({
      status: 200,
      ok: true,
      url: data.routes.submitRow,
      headers: { get: () => 'application/json' },
      text: async () => JSON.stringify({
        result: {
          rowNumber: 1,
          equipmentId: 20,
          success: true,
          message: 'Lectura registrada.',
          currentKilometers: null,
          currentHours: 8350.5,
          plansEvaluated: 0,
          overduePlans: 0,
        },
        csrf: { name: 'csrf_test', hash: 'HASH456' },
      }),
    })
    vi.stubGlobal('fetch', fetchMock)
    const wrapper = mount(QuickReadingsPage, { props: { data } })

    await wrapper.find('#quick-10-km').setValue('120000')
    await wrapper.find('#quick-20-hours').setValue('8350,5')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(fetchMock).toHaveBeenCalledTimes(1)
    const body = fetchMock.mock.calls[0][1].body
    expect(body.get('equipmentId')).toBe('20')
    expect(body.get('hours')).toBe('8350.5')
    expect(wrapper.text()).toContain('Guardada')
    expect(wrapper.text()).toContain('No puede ser menor a 125.430 km.')

    vi.unstubAllGlobals()
  })
})
