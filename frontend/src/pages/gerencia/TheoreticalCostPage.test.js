import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import TheoreticalCostPage from './TheoreticalCostPage.vue'

const data = () => ({
  canEdit: true,
  csrf: { hash: 'csrf-test' },
  urls: { assets: '/activos', scenarios: '/escenarios', exchangeRate: '/cotizacion-bcra' },
})

const inputWithLabel = (wrapper, labelText) => wrapper.findAll('label')
  .find((label) => label.text().includes(labelText))
  ?.find('input')

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

describe('TheoreticalCostPage standard assumptions', () => {
  it('loads the latest BCRA reference rate and uses it as the scenario quote', async () => {
    const fetchMock = vi.fn(async (url) => ({
      ok: true,
      json: async () => String(url).includes('cotizacion-bcra')
        ? { data: { ars_por_usd: 1515.8766, fecha: '2026-10-09', fuente: 'BCRA · Tipo de Cambio de Referencia Com. A 3500' } }
        : String(url).includes('activos') ? { data: { tractores: [], semis: [] } } : { data: { items: [] } },
    }))
    vi.stubGlobal('fetch', fetchMock)

    const wrapper = mount(TheoreticalCostPage, { props: { data: data() } })
    await flushPromises()

    expect(inputWithLabel(wrapper, 'ARS por 1 USD').element.value).toBe('1515.8766')
    expect(inputWithLabel(wrapper, 'Fecha de referencia').element.value).toBe('2026-10-09')
    expect(inputWithLabel(wrapper, 'Fuente o tipo').element.value).toContain('BCRA')
  })

  it('loads documented assumptions while preserving prices and company-specific inputs', async () => {
    vi.stubGlobal('fetch', vi.fn(async (url) => ({
      ok: true,
      json: async () => String(url).includes('activos')
        ? { data: { tractores: [], semis: [] } }
        : { data: { items: [] } },
    })))

    const wrapper = mount(TheoreticalCostPage, { props: { data: data() } })
    await flushPromises()

    await inputWithLabel(wrapper, 'Precio por litro').setValue('1250')
    await inputWithLabel(wrapper, 'Juego de neumáticos').setValue('10000')
    await inputWithLabel(wrapper, 'ARS por 1 USD').setValue('1234')
    await inputWithLabel(wrapper, 'Mantenimiento y reparaciones').setValue('12')
    await inputWithLabel(wrapper, 'Reposición (US$)').setValue('100000')
    const assetValues = wrapper.findAll('label').filter((label) => label.text().includes('Reposición (US$)'))
    await assetValues[1].find('input').setValue('150000')
    const assetLifeValues = wrapper.findAll('label').filter((label) => label.text().includes('Vida útil (años)'))
    await assetLifeValues[0].find('input').setValue('5')
    await assetLifeValues[1].find('input').setValue('8')
    await wrapper.get('button[aria-label="Cargar supuestos documentados"]')
      .trigger('click')

    expect(inputWithLabel(wrapper, 'Consumo (litros cada 100 km)').element.value).toBe('37.04')
    expect(inputWithLabel(wrapper, 'Vida útil total (km)').element.value).toBe('250000')
    expect(inputWithLabel(wrapper, 'Recapados del ciclo').element.value).toBe('3000.00')
    expect(inputWithLabel(wrapper, 'Precio por litro').element.value).toBe('1250')
    expect(inputWithLabel(wrapper, 'ARS por 1 USD').element.value).toBe('1234')
    expect(inputWithLabel(wrapper, 'Mantenimiento y reparaciones').element.value).toBe('12')
    expect(assetValues[0].find('input').element.value).toBe('100000')
    expect(assetValues[1].find('input').element.value).toBe('150000')
    expect(assetLifeValues[0].find('input').element.value).toBe('5')
    expect(assetLifeValues[1].find('input').element.value).toBe('8')
    expect(wrapper.findAll('label').find((label) => label.text().includes('Residual (US$)')).find('input').element.value).toBe('30000')
    expect(wrapper.findAll('label').filter((label) => label.text().includes('Residual (US$)'))[1].find('input').element.value).toBe('45000')
    expect(inputWithLabel(wrapper, 'Lubricantes').element.value).toBe('0')
    expect(inputWithLabel(wrapper, 'Costo financiero').element.value).toBe('0')
    expect(wrapper.text()).toContain('Estimación inicial editable: $0')
    expect(wrapper.text()).toContain('Disposición 273/2011')
    expect(wrapper.get('a[href*="disposici%C3%B3n-273-2011"]').exists()).toBe(true)
  })

  it('does not preselect equipment and calculates a company-wide theoretical scenario without equipment', async () => {
    const fetchMock = vi.fn(async (url, options = {}) => {
      if (String(url).includes('activos')) {
        return { ok: true, json: async () => ({ data: { tractores: [{ id: 4, codigo: 'T-4' }], semis: [{ id: 8, codigo: 'S-8' }] } }) }
      }
      if (options.method === 'POST') {
        return { ok: true, json: async () => ({ data: { resultado: { cobertura: 'parcial', costo_km: { ars: 50, usd: 0.05 } } } }) }
      }
      return { ok: true, json: async () => ({ data: { items: [] } }) }
    })
    vi.stubGlobal('fetch', fetchMock)

    const wrapper = mount(TheoreticalCostPage, { props: { data: data() } })
    await flushPromises()

    expect(wrapper.findAll('select').slice(0, 2).some((select) => select.element.value !== '')).toBe(false)
    const calculate = wrapper.findAll('button').find((button) => button.text().includes('Calcular referencia'))
    expect(calculate.attributes('disabled')).toBeUndefined()
    await calculate.trigger('click')
    await flushPromises()

    const request = fetchMock.mock.calls.find(([, options]) => options?.method === 'POST')
    expect(JSON.parse(request[1].body)).toMatchObject({ tractor_id: null, semi_id: null })
    expect(wrapper.text()).toContain('Resultado teórico')
  })

  it('allows calculating with only a selected tractor', async () => {
    const fetchMock = vi.fn(async (url, options = {}) => ({
      ok: true,
      json: async () => String(url).includes('activos')
        ? { data: { tractores: [{ id: 4, codigo: 'T-4' }], semis: [{ id: 8, codigo: 'S-8' }] } }
        : options.method === 'POST'
          ? { data: { resultado: { cobertura: 'parcial', costo_km: { ars: 50, usd: 0.05 }, rubros: [] } } }
          : { data: { items: [] } },
    }))
    vi.stubGlobal('fetch', fetchMock)

    const wrapper = mount(TheoreticalCostPage, { props: { data: data() } })
    await flushPromises()

    await wrapper.get('select').setValue('4')
    const calculate = wrapper.findAll('button').find((button) => button.text().includes('Calcular referencia'))
    expect(calculate.attributes('disabled')).toBeUndefined()
    await calculate.trigger('click')
    await flushPromises()

    const request = fetchMock.mock.calls.find(([, options]) => options?.method === 'POST')
    expect(JSON.parse(request[1].body)).toMatchObject({ tractor_id: 4, semi_id: null })
  })

  it('labels company-wide saved scenarios without equipment in the history', async () => {
    vi.stubGlobal('fetch', vi.fn(async (url) => ({
      ok: true,
      json: async () => String(url).includes('activos')
        ? { data: { tractores: [], semis: [] } }
        : { data: { items: [{ id: 12, nombre: 'Flota general', tractor: null, semi: null, version: 1, versiones: [{ numero: 1, cobertura: 'parcial', costo_ars_km: 45 }] }] } },
    })))

    const wrapper = mount(TheoreticalCostPage, { props: { data: data() } })
    await flushPromises()
    await wrapper.findAll('button').find((button) => button.text().includes('Escenarios guardados')).trigger('click')

    expect(wrapper.text()).toContain('Escenario general · sin equipo asociado')
    expect(wrapper.text()).toContain('Flota general')
  })

  it('loads a saved scenario as a copy so edits create a new scenario', async () => {
    const record = {
      id: 12, nombre: 'Base flota', version: 2, guardado_en: '2026-10-10 12:00:00',
      tractor: null, semi: null,
      parametros: {
        km_mensuales: 10000,
        otros_rubros: [
          { codigo: 'lubricantes', nombre: 'Lubricantes', importe: 3, unidad: 'por_km', moneda: 'ARS' },
          { codigo: 'costo_financiero', nombre: 'Costo financiero', importe: 25000, unidad: 'por_mes', moneda: 'ARS' },
        ],
      },
      viaje: null,
      resultado: { cobertura: 'parcial', costo_km: { ars: 50, usd: 0.05 }, tarifa_km: { ars: 55, usd: 0.055 }, rubros: [] },
    }
    const fetchMock = vi.fn(async (url, options = {}) => ({
      ok: true,
      json: async () => String(url).includes('/versiones/2')
        ? { data: record }
        : String(url).includes('activos') ? { data: { tractores: [], semis: [] } }
          : options.method === 'POST'
            ? { data: { id: 13, version: 1, resultado: record.resultado } }
            : { data: { items: [{ id: 12, nombre: 'Base flota', tractor: null, semi: null, version: 2, versiones: [{ numero: 2, cobertura: 'parcial', costo_ars_km: 50 }] }] } },
    }))
    vi.stubGlobal('fetch', fetchMock)

    const wrapper = mount(TheoreticalCostPage, { props: { data: data() } })
    await flushPromises()
    await wrapper.get('[role="tab"][aria-selected="false"]').trigger('click')
    await wrapper.findAll('button').find((button) => button.text().includes('Usar como base')).trigger('click')
    await flushPromises()

    expect(inputWithLabel(wrapper, 'Lubricantes').element.value).toBe('3')
    expect(inputWithLabel(wrapper, 'Costo financiero').element.value).toBe('25000')
    expect(wrapper.get('#scenario-name').element.value).toBe('Base flota (copia)')
    await wrapper.findAll('button').find((button) => button.text() === 'Guardar').trigger('click')
    await flushPromises()

    const save = fetchMock.mock.calls.find(([, options]) => options?.method === 'POST')
    expect(JSON.parse(save[1].body)).toMatchObject({ escenario_id: null, nombre: 'Base flota (copia)' })
  })
})
