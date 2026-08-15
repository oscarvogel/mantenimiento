import { describe, expect, it } from 'vitest'
import { formatHours, formatKilometers, formatReadingOrigin, kilometersDelta, localDateValue, normalizeDecimalInput, parseFlexibleNumber, parseKilometers, readingDelta } from '../../src/pages/operations/helpers.js'

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
