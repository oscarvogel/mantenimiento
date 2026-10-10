import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
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
      equipment: { id: 123, code: 'AC532DD' },
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

  it('ofrece configurar el proveedor a quien puede editar equipos aunque aún no haya señales', () => {
    const wrapper = mount(TelemetryBoard, {
      props: {
        telemetry: {
          canManage: true,
          manageUrl: '/mantenimiento/telemetria/integraciones',
          canRefresh: false,
          sources: [],
        },
        equipment: { id: 123, code: 'AC532DD' },
      },
    })
    wrappers.push(wrapper)

    expect(wrapper.get('a[href="/mantenimiento/telemetria/integraciones"]').text()).toContain('Configurar proveedor')
    expect(wrapper.text()).toContain('Todavía no hay una fuente vinculada')
  })

  it('muestra un indicador total cuando el proveedor solo informa litros y explica la referencia', () => {
    const wrapper = render({ extraSensors: [] })

    expect(wrapper.text()).toContain('555 / 780 l')
    expect(wrapper.text()).not.toContain('555 l en total')
    expect(wrapper.text()).toContain('Capacidad de referencia')
    expect(wrapper.find('[aria-label="Combustible total: 555 de 780 litros de referencia, 71%"]').exists()).toBe(true)
  })

  it('conserva un tanque en cero y no lo confunde con una lectura faltante', () => {
    const wrapper = render({ fuelLiters: 0, extraSensors: [{ etiqueta: 'COMBUSTIBLE T1', valor: 0, unidad: 'l' }] })

    expect(wrapper.find('[aria-label="Tanque 1: 0 litros, 0% de capacidad de referencia"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('0 l')
    expect(wrapper.text()).not.toContain('0 l en total')
  })

  it('muestra solo el tanque que informa el proveedor', () => {
    const wrapper = render({ extraSensors: [{ etiqueta: 'COMBUSTIBLE T2', valor: 115, unidad: 'l' }] })

    expect(wrapper.find('[aria-label="Tanque 2: 115 litros, 50% de capacidad de referencia"]').exists()).toBe(true)
    expect(wrapper.find('[aria-label^="Tanque 1:"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('555')
  })

  it('mantiene el nivel individual si el total del proveedor falta', () => {
    const wrapper = render({
      fuelLiters: null,
      extraSensors: [{ etiqueta: 'COMBUSTIBLE T1', valor: 440, unidad: 'l' }],
    })

    expect(wrapper.text()).not.toContain('Total sin dato')
    expect(wrapper.find('[aria-label="Tanque 1: 440 litros, 80% de capacidad de referencia"]').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('Sin lectura de combustible en la última señal.')
  })

  it('carga el mapa como iframe cuando la fuente tiene coordenadas', () => {
    const wrapper = render({
      position: { latitude: -32.5969, longitude: -69.3731, speedKmh: 0, satellites: 28 },
    })

    expect(wrapper.find('iframe').attributes('src')).toContain('openstreetmap.org/export/embed.html')
    expect(wrapper.find('img').exists()).toBe(false)
  })

  it('abre el mapa como modal sobre el contenido sin expandir el mapa dentro de la ficha', async () => {
    const wrapper = render({
      position: { latitude: -32.5969, longitude: -69.3731, speedKmh: 0, satellites: 28 },
    })

    await wrapper.get('button[aria-label="Ampliar el mapa"]').trigger('click')

    const dialog = document.body.querySelector('[role="dialog"][aria-modal="true"]')
    expect(dialog).not.toBeNull()
    expect(dialog.querySelector('iframe').className).toContain('flex-1')
    expect(wrapper.find('iframe').classes()).toContain('h-40')

    dialog.querySelector('button[aria-label="Cerrar el mapa"]').click()
    await nextTick()

    expect(document.body.querySelector('[role="dialog"][aria-modal="true"]')).toBeNull()
  })

  it('abre un solo modal cuando hay más de una fuente con ubicación', async () => {
    const wrapper = mount(TelemetryBoard, {
      props: {
        telemetry: {
          canRefresh: false,
          csrf: null,
          refreshUrl: '#',
          equipmentKm: null,
          sources: [
            source({ position: { latitude: -32.5, longitude: -69.3, speedKmh: null, satellites: null } }),
            source({
              integrationId: 2,
              provider: 'GPS alternativo',
              position: { latitude: -31.5, longitude: -68.3, speedKmh: null, satellites: null },
            }),
          ],
        },
        equipment: { id: 123, code: 'AC532DD' },
      },
    })
    wrappers.push(wrapper)

    await wrapper.findAll('button[aria-label="Ampliar el mapa"]')[0].trigger('click')

    expect(document.body.querySelectorAll('[role="dialog"][aria-modal="true"]')).toHaveLength(1)
    expect(document.body.querySelector('[role="dialog"] iframe').getAttribute('src')).toContain('-32.5')
  })

  it('envía el identificador del equipo con el refresco para volver a su ficha', () => {
    const wrapper = mount(TelemetryBoard, {
      props: {
        telemetry: {
          canRefresh: true,
          csrf: { name: 'csrf_token', hash: 'csrf_hash' },
          refreshUrl: '/mantenimiento/telemetria/actualizar',
          equipmentKm: null,
          sources: [source()],
        },
        equipment: { id: 123, code: 'AC532DD' },
      },
    })
    wrappers.push(wrapper)

    expect(wrapper.find('input[name="equipment_id"]').element.value).toBe('123')
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
