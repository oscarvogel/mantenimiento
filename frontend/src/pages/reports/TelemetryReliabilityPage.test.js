import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import TelemetryReliabilityPage from './TelemetryReliabilityPage.vue'

const baseReport = {
  filters: { branchId: null, from: '2026-10-01', to: '2026-10-30' },
  branches: [{ id: 9, nombre: 'Central' }],
  tolerances: { toleranceKm: 50, tolerancePercent: 1, matchWindowMinutes: 90 },
  summary: {
    equipmentWithDriverReadings: 3,
    equipmentComparable: 2,
    intervalsComparable: 12,
    withinPercent: 91.7,
    medianAbsoluteErrorKm: 12.5,
    coveragePercent: 85,
  },
  equipment: [
    {
      equipmentId: 10, equipmentCode: 'CAM-01', plate: 'AB123CD', branchName: 'Central',
      integrationId: 31, integrationName: 'Obersat', provider: 'wialon',
      driverReadings: 8, lastReadingAt: '2026-10-29 18:00:00',
      intervalsConsidered: 7, intervalsComparable: 7, intervalsWithin: 7,
      withinPercent: 100, coveragePercent: 100, medianAbsoluteErrorKm: 5,
      meanSignedErrorKm: 2.5, maxAbsoluteErrorKm: 20, driverKm: 3200, telemetryKm: 3205,
      verdict: 'CONFIABLE', verdictLabel: 'Confiable',
    },
    {
      equipmentId: 11, equipmentCode: 'CAM-02', plate: null, branchName: 'Central',
      integrationId: 31, integrationName: 'Obersat', provider: 'wialon',
      driverReadings: 6, lastReadingAt: '2026-10-28 09:00:00',
      intervalsConsidered: 5, intervalsComparable: 3, intervalsWithin: 1,
      withinPercent: 33.3, coveragePercent: 60, medianAbsoluteErrorKm: 180,
      meanSignedErrorKm: -150, maxAbsoluteErrorKm: 320, driverKm: 2100, telemetryKm: 1950,
      verdict: 'NO_CONFIABLE', verdictLabel: 'No confiable',
    },
  ],
}

const mountPage = (report = baseReport) => mount(TelemetryReliabilityPage, {
  props: {
    data: {
      report,
      urls: { index: '/reportes/telemetria', export: '/reportes/telemetria/exportar' },
      portalUrl: '/inicio',
    },
  },
})

describe('TelemetryReliabilityPage', () => {
  it('shows the period summary and the verdict per equipment', () => {
    const wrapper = mountPage()

    expect(wrapper.text()).toContain('Confiabilidad de telemetría')
    expect(wrapper.text()).toContain('91,7%')
    expect(wrapper.text()).toContain('Obersat')
    expect(wrapper.text()).toContain('Confiable')
    expect(wrapper.text()).toContain('No confiable')
  })

  it('explains when there is no data for the selected period', () => {
    const wrapper = mountPage({
      ...baseReport,
      equipment: [],
      summary: { equipmentWithDriverReadings: 0, equipmentComparable: 0, intervalsComparable: 0 },
    })

    expect(wrapper.text()).toContain('No hay lecturas de chofer ni muestras de telemetría')
  })
})
