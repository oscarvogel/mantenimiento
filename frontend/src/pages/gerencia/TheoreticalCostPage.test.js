import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import TheoreticalCostPage from './TheoreticalCostPage.vue'

const data = () => ({
  canEdit: true,
  csrf: { hash: 'csrf-test' },
  urls: { assets: '/activos', scenarios: '/escenarios' },
})

const inputWithLabel = (wrapper, labelText) => wrapper.findAll('label')
  .find((label) => label.text().includes(labelText))
  ?.find('input')

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
})

describe('TheoreticalCostPage standard assumptions', () => {
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
    await wrapper.get('button[aria-label="Cargar supuestos documentados"]')
      .trigger('click')

    expect(inputWithLabel(wrapper, 'Consumo (litros cada 100 km)').element.value).toBe('37.04')
    expect(inputWithLabel(wrapper, 'Vida útil total (km)').element.value).toBe('250000')
    expect(inputWithLabel(wrapper, 'Recapados del ciclo').element.value).toBe('3000.00')
    expect(inputWithLabel(wrapper, 'Precio por litro').element.value).toBe('1250')
    expect(inputWithLabel(wrapper, 'ARS por 1 USD').element.value).toBe('1234')
    expect(inputWithLabel(wrapper, 'Mantenimiento y reparaciones').element.value).toBe('12')
    expect(inputWithLabel(wrapper, 'Reposición (US$)').element.value).toBe('100000')
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

  it('requires selecting tractor and semi together when associating a scenario to equipment', async () => {
    vi.stubGlobal('fetch', vi.fn(async (url) => ({
      ok: true,
      json: async () => String(url).includes('activos')
        ? { data: { tractores: [{ id: 4, codigo: 'T-4' }], semis: [{ id: 8, codigo: 'S-8' }] } }
        : { data: { items: [] } },
    })))

    const wrapper = mount(TheoreticalCostPage, { props: { data: data() } })
    await flushPromises()

    await wrapper.get('select').setValue('4')
    const calculate = wrapper.findAll('button').find((button) => button.text().includes('Calcular referencia'))
    expect(calculate.attributes('disabled')).toBeDefined()
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
})
