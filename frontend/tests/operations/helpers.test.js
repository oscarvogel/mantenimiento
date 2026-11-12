import { describe, expect, it } from 'vitest'
import { formatHours, formatKilometers, formatReadingOrigin, kilometersDelta, localDateValue, normalizeDecimalInput, parseFlexibleNumber, parseKilometers, readingDelta, tripRouteCounts, tripRouteLabel } from '../../src/pages/operations/helpers.js'

describe('helpers de fechas operativas', () => {
  it('conserva la fecha calendario local sin convertirla a UTC', () => {
    const localNight = new Date(2026, 7, 10, 22, 30)

    expect(localDateValue(localNight)).toBe('2026-08-10')
  })

  it('formatea lecturas con separador local y origen humano', () => {
    expect(formatKilometers(12500)).toBe('12.500 km')
    expect(formatHours('1250.5')).toBe('1.250,5 h')
    expect(readingDelta(100, '108')).toBe(8)
    expect(formatReadingOrigin('CARGA_RAPIDA')).toBe('Carga rápida')
    expect(parseFlexibleNumber('1250,5')).toBe(1250.5)
    expect(normalizeDecimalInput('1250,5')).toBe('1250.5')
    expect(parseFlexibleNumber('1250.55')).toBeNull()
    expect(parseFlexibleNumber('1250,55')).toBeNull()
    expect(parseFlexibleNumber('1.250,5')).toBeNull()
    expect(parseFlexibleNumber('1,250.5')).toBeNull()
    expect(parseKilometers('12500')).toBe(12500)
    expect(parseKilometers('12500,5')).toBeNull()
    expect(parseKilometers('12500.5')).toBeNull()
    expect(kilometersDelta(12000, '12500')).toBe(500)
  })
})

describe('helpers del recorrido del viaje', () => {
  it('muestra el recorrido que sale de los tramos', () => {
    expect(tripRouteLabel({ origen: 'Rosario', destino: 'Córdoba' })).toBe('Rosario → Córdoba')
  })

  it('informa que el viaje no tiene recorrido cuando no hay tramos ni paradas', () => {
    expect(tripRouteLabel({ origen: null, destino: null })).toBe('Sin recorrido')
    expect(tripRouteLabel({})).toBe('Sin recorrido')
    expect(tripRouteCounts({ tramos: 0, paradas: 0 })).toBe('Sin tramos')
  })

  it('cuenta tramos y paradas por separado', () => {
    expect(tripRouteCounts({ tramos: 2, paradas: 3 })).toBe('2 tramos · 3 paradas')
    expect(tripRouteCounts({ tramos: 1, paradas: 0 })).toBe('1 tramo')
    expect(tripRouteCounts({ tramos: 0, paradas: 1 })).toBe('1 parada')
  })
})
