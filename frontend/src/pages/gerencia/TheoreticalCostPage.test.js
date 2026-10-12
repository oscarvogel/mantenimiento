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
})
