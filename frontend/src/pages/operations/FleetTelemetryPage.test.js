import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import FleetTelemetryPage from './FleetTelemetryPage.vue'

const MapStub = {
  props: ['units'],
  template: '<div data-test="fleet-map">{{ units.length }} ubicaciones</div>',
}

const data = {
  diagnosticUrl: '/mantenimiento/telemetria/diagnostico',
  today: '2026-10-13',
  branches: [{ id: 4, name: 'TSA Argentina' }],
  units: [
    {
      equipmentId: 12, code: 'AB499OK', plate: 'AB499OK', branchId: 4, branchName: 'TSA Argentina',
      detailUrl: '/mantenimiento/equipos/12?tab=telemetria',
      sources: [{ provider: 'wialon', integrationName: 'Obersat', role: 'PRINCIPAL', freshness: 'AL_DIA', ageMinutes: 5,
        observedAt: '2026-10-10 07:58:00', kilometers: 1001268, hours: 4824.4, engineOn: true, idling: false, voltage: 24.5, fuelLiters: 331,
        position: { latitude: -32.5969, longitude: -69.3731, speedKmh: 0, satellites: 28 }, sensorIssues: [] }],
    },
    {
      equipmentId: 13, code: 'AC532DD', plate: 'AC532DD', branchId: 4, branchName: 'TSA Argentina',
      detailUrl: '/mantenimiento/equipos/13?tab=telemetria',
      sources: [{ provider: 'wialon', integrationName: 'Obersat', role: 'PRINCIPAL', freshness: 'SIN_RESPUESTA', ageMinutes: 80,
        observedAt: '2026-10-10 06:38:00', kilometers: null, fuelLiters: null, position: null,
        sensorIssues: [{ sensor: 'Combustible', valor: 999, motivo: 'valor fuera de rango' }] }],
    },
  ],
}

describe('FleetTelemetryPage', () => {
  afterEach(() => vi.unstubAllGlobals())

  it('shows fleet map, sensor problems, and one clear row per vehicle', () => {
    const wrapper = mount(FleetTelemetryPage, { props: { data }, global: { stubs: { FleetTelemetryMap: MapStub } } })

    expect(wrapper.text()).toContain('Telemetría de flota')
    expect(wrapper.text()).toContain('1 equipo con sensores a revisar')
    expect(wrapper.text()).toContain('Combustible (999) · valor fuera de rango')
    expect(wrapper.text()).toContain('Las alertas se incluyen en las notificaciones diarias.')
    expect(wrapper.text()).toContain('331 l')
    expect(wrapper.text()).not.toContain('780 l')
    expect(wrapper.text()).not.toContain('Tanque 1')
    expect(wrapper.text()).not.toContain('Tanque 2')
    expect(wrapper.text()).toContain('1 de 2 equipos con ubicación disponible')
  })

  it('filters the map and list by problem state and search text', async () => {
    const wrapper = mount(FleetTelemetryPage, { props: { data }, global: { stubs: { FleetTelemetryMap: MapStub } } })

    await wrapper.get('[data-test="issues-only"]').setValue(true)
    expect(wrapper.text()).toContain('AC532DD')
    expect(wrapper.text()).not.toContain('AB499OK')

    await wrapper.get('[data-test="issues-only"]').setValue(false)
    await wrapper.get('[data-test="fleet-search"]').setValue('AB499')
    expect(wrapper.text()).toContain('AB499OK')
    expect(wrapper.findAll('article').map((article) => article.text()).join('')).not.toContain('AC532DD')
  })

  it('explains when the fleet has telemetry but no current coordinates', () => {
    const noPosition = { ...data, units: [{ ...data.units[1], sources: [{ ...data.units[1].sources[0], sensorIssues: [] }] }] }
    const wrapper = mount(FleetTelemetryPage, { props: { data: noPosition }, global: { stubs: { FleetTelemetryMap: MapStub } } })

    expect(wrapper.text()).toContain('Todavía no hay equipos con ubicación disponible para mostrar.')
    expect(wrapper.text()).toContain('AC532DD')
  })

  it('separates delayed provider signals from active sensor anomalies', () => {
    const issueFreeData = {
      ...data,
      units: data.units.map((unit, index) => ({
        ...unit,
        sources: [{ ...unit.sources[0], sensorIssues: [], freshness: index === 0 ? 'RECIENTE' : 'SIN_RESPUESTA' }],
      })),
    }
    const wrapper = mount(FleetTelemetryPage, { props: { data: issueFreeData }, global: { stubs: { FleetTelemetryMap: MapStub } } })

    expect(wrapper.text()).toContain('Estado de la señal')
    expect(wrapper.text()).toContain('1 reciente')
    expect(wrapper.text()).toContain('1 sin respuesta')
    expect(wrapper.text()).not.toContain('requieren atención')
    expect(wrapper.text()).toContain('0 equipos con sensores a revisar')
    expect(wrapper.text()).toContain('No se detectaron valores de sensores fuera de rango.')
  })

  it('shows practical indicators in a desktop table and mobile equipment cards', () => {
    const wrapper = mount(FleetTelemetryPage, { props: { data }, global: { stubs: { FleetTelemetryMap: MapStub } } })

    expect(wrapper.find('[data-test="fleet-desktop-table"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="fleet-desktop-table"]').classes()).toContain('hidden')
    expect(wrapper.find('[data-test="fleet-mobile-cards"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="fleet-mobile-cards"]').classes()).toContain('lg:hidden')
    expect(wrapper.text()).toContain('Equipos vinculados')
    expect(wrapper.text()).toContain('Señal atrasada o ausente')
    expect(wrapper.text()).toContain('4.824,4 h')
    expect(wrapper.text()).toContain('24,5 V')
    expect(wrapper.text()).toContain('Encendido')
    expect(wrapper.text()).toContain('Sin ralentí')
    expect(wrapper.text()).toContain('0 km/h')
  })

  it('loads a day diagnostic with provider and saved timestamps plus reconstructed trips', async () => {
    const fetch = vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({
        samples: [{
          provider: 'wialon',
          observedAt: '2026-10-13 10:00:00', recordedAt: '2026-10-13 10:05:00',
          fuelLiters: 220.5, kilometers: 1710049, hours: 4571.2, speedKmh: 42, anomalies: [],
        }],
        trips: [{ start: '2026-10-13 09:00:00', end: '2026-10-13 10:00:00', kilometers: 28, liters: 2.7 }],
        summary: { coveragePercent: 94 },
      }),
    })
    vi.stubGlobal('fetch', fetch)
    const wrapper = mount(FleetTelemetryPage, { props: { data }, global: { stubs: { FleetTelemetryMap: MapStub } } })

    await wrapper.get('[data-test="diagnostic-open-12"]').trigger('click')
    await wrapper.get('[data-test="diagnostic-date"]').setValue('2026-10-13')
    await wrapper.get('[data-test="diagnostic-load"]').trigger('click')

    expect(fetch).toHaveBeenCalledWith('/mantenimiento/telemetria/diagnostico?equipo=12&fecha=2026-10-13', expect.objectContaining({ credentials: 'same-origin' }))
    await vi.waitFor(() => expect(wrapper.text()).toContain('Observado por Wialon'))
    expect(wrapper.text()).toContain('Observado por Wialon')
    expect(wrapper.text()).toContain('2026-10-13 10:00:00')
    expect(wrapper.text()).toContain('Guardado en el sistema')
    expect(wrapper.text()).toContain('2026-10-13 10:05:00')
    expect(wrapper.text()).toContain('Viajes reconstruidos')
    expect(wrapper.text()).toContain('28 km')
  })

  it('explains sensor values that were set aside instead of showing them as missing', async () => {
    const fetch = vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({
        samples: [{ provider: 'wialon', observedAt: '2026-10-13 10:00:00', recordedAt: '2026-10-13 10:05:00',
          fuelLiters: null, kilometers: 1710049, hours: 4571.2, speedKmh: 0,
          anomalies: [{ sensor: 'COMBUSTIBLE', value: -5, reason: 'un tanque no puede tener litros negativos' }] }],
        trips: [], summary: { sampleCount: 1, tripCount: 0, coveragePercent: 0, coverageSufficient: false,
          coverageReason: 'No hay cobertura suficiente.', uncalibrated: true },
      }),
    })
    vi.stubGlobal('fetch', fetch)
    const wrapper = mount(FleetTelemetryPage, { props: { data }, global: { stubs: { FleetTelemetryMap: MapStub } } })

    await wrapper.get('[data-test="diagnostic-open-12"]').trigger('click')
    await wrapper.get('[data-test="diagnostic-load"]').trigger('click')

    await vi.waitFor(() => expect(wrapper.text()).toContain('COMBUSTIBLE (-5)'))
    expect(wrapper.text()).toContain('COMBUSTIBLE (-5)')
    expect(wrapper.text()).toContain('un tanque no puede tener litros negativos')
    expect(wrapper.get('[data-test="diagnostic-fuel"]').text()).toBe('—')
  })
})
