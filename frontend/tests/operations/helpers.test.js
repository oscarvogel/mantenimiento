import { describe, expect, it } from 'vitest'
import { localDateValue } from '../../src/pages/operations/helpers.js'

describe('helpers de fechas operativas', () => {
  it('conserva la fecha calendario local sin convertirla a UTC', () => {
    const localNight = new Date(2026, 7, 10, 22, 30)

    expect(localDateValue(localNight)).toBe('2026-08-10')
  })
})
