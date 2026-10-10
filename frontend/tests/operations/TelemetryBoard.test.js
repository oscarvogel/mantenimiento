import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import TelemetryBoard from '../../src/pages/operations/components/TelemetryBoard.vue'

const wrappers = []

const source = (overrides = {}) => ({
  integrationId: 1,
  integrationName: 'Wialon TSA',
  provider: 'Wialon',
  observedAt: '2026-10-10 08:51:00',
  ageMinutes: 600,
  stale: true,
  position: null,
  kilometers: 1027872,
  kilometersDifference: -2128,
  hours: 4824.4,
  engineOn: false,
  idling: null,
  voltage: 26.92,
  fuelLiters: 555,
  extraSensors: [
    { etiqueta: 'COMBUSTIBLE T1', valor: 440, unidad: 'l' },
    { etiqueta: 'COMBUSTIBLE T2', valor: 115, unidad: 'l' },
  ],
  ...overrides,
})

const render = (sourceOverrides = {}) => {
  const wrapper = mount(TelemetryBoard, {
    props: {
      telemetry: {
        canRefresh: false,
        csrf: null,
        refreshUrl: '#',
        equipmentKm: 1030000,
        sources: [source(sourceOverrides)],
      },
      equipment: { code: 'AC532DD' },
    },
  })
  wrappers.push(wrapper)
  return wrapper
}

afterEach(() => wrappers.splice(0).forEach((wrapper) => wrapper.unmount()))

describe('TelemetryBoard', () => {
  it('muestra por separado el nivel de cada tanque contra su capacidad de referencia', () => {
    const wrapper = render()

    expect(wrapper.text()).toContain('440 l')
    expect(wrapper.text()).toContain('80%')
    expect(wrapper.text()).toContain('115 l')
    expect(wrapper.text()).toContain('50%')
    expect(wrapper.find('[aria-label="Tanque 1: 440 litros, 80% de capacidad de referencia"]').exists()).toBe(true)
    expect(wrapper.find('[aria-label="Tanque 2: 115 litros, 50% de capacidad de referencia"]').exists()).toBe(true)
  })

  it('muestra un indicador total cuando el proveedor solo informa litros y explica la referencia', () => {
    const wrapper = render({ extraSensors: [] })

    expect(wrapper.text()).toContain('555 l')
    expect(wrapper.text()).toContain('Capacidad de referencia: 780 l')
    expect(wrapper.find('[aria-label="Combustible total: 555 litros, 71% de una capacidad de referencia de 780 litros"]').exists()).toBe(true)
  })

  it('conserva un tanque en cero y no lo confunde con una lectura faltante', () => {
    const wrapper = render({ fuelLiters: 0, extraSensors: [{ etiqueta: 'COMBUSTIBLE T1', valor: 0, unidad: 'l' }] })

    expect(wrapper.find('[aria-label="Tanque 1: 0 litros, 0% de capacidad de referencia"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('0 l en total')
  })

  it('muestra solo el tanque que informa el proveedor', () => {
    const wrapper = render({ extraSensors: [{ etiqueta: 'COMBUSTIBLE T2', valor: 115, unidad: 'l' }] })

    expect(wrapper.find('[aria-label="Tanque 2: 115 litros, 50% de capacidad de referencia"]').exists()).toBe(true)
    expect(wrapper.find('[aria-label^="Tanque 1:"]').exists()).toBe(false)
  })

  it('mantiene el nivel individual si el total del proveedor falta', () => {
    const wrapper = render({ fuelLiters: null })

    expect(wrapper.text()).toContain('Total sin dato')
    expect(wrapper.find('[aria-label="Tanque 1: 440 litros, 80% de capacidad de referencia"]').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('Sin lectura de combustible en la última señal.')
  })

  it('no dibuja un nivel inventado cuando el proveedor no informa combustible', () => {
    const wrapper = render({ fuelLiters: null, extraSensors: [] })

    expect(wrapper.text()).toContain('Sin lectura de combustible en la última señal.')
    expect(wrapper.find('[role="meter"]').exists()).toBe(false)
  })

  it('explica que no hubo respuesta del proveedor aunque conserve la última señal', () => {
    const wrapper = render()

    expect(wrapper.text()).toContain('sin respuesta')
    expect(wrapper.text()).toContain('Hace 10 h')
  })

  it('pone la comparación de kilometraje al principio y aclara que no actualiza el sistema', () => {
    const wrapper = render()
    const text = wrapper.text()

    expect(text.indexOf('Comparación de kilometraje')).toBeLessThan(text.indexOf('Última señal'))
    expect(text).toContain('La lectura de telemetría es informativa; no modifica la del sistema.')
    expect(text).toContain('Diferencia: -2.128 km')
  })
})
