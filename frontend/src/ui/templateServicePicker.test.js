import { describe, expect, it } from 'vitest'
import { compactFrequency, filterServiceCandidates } from './templateServicePicker.js'

const candidates = [
  {
    name: 'Cambio de aceite de motor',
    code: 'ACEITE-MOTOR',
    template: 'General Camiones',
    scope: 'General Camiones · Tipo de equipo',
    frequency: 'Cada 20000 km / Cada 365 días',
  },
  {
    name: 'Filtros de combustible',
    code: 'FILTRO-COMB',
    template: 'General Camiones',
    scope: 'General Camiones · Tipo de equipo',
    frequency: 'Cada 40000 km',
  },
]

describe('filterServiceCandidates', () => {
  it('busca por nombre sin distinguir mayúsculas ni acentos', () => {
    expect(filterServiceCandidates(candidates, 'aceite')).toEqual([candidates[0]])
  })

  it('busca por código de servicio', () => {
    expect(filterServiceCandidates(candidates, 'FILTRO-COMB')).toEqual([candidates[1]])
  })

  it('permite buscar por plantilla y frecuencia', () => {
    expect(filterServiceCandidates(candidates, '365')).toEqual([candidates[0]])
    expect(filterServiceCandidates(candidates, 'general camiones')).toHaveLength(2)
  })

  it('devuelve toda la biblioteca compatible cuando no hay término', () => {
    expect(filterServiceCandidates(candidates, '')).toEqual(candidates)
  })
})

describe('compactFrequency', () => {
  it('elimina la anticipación para mostrar una descripción compacta', () => {
    expect(compactFrequency([
      'Cada 20000 km · anticipación 2000 km',
      'Cada 365 días · anticipación 30 días',
    ])).toBe('Cada 20000 km / Cada 365 días')
  })

  it('ignora valores vacíos', () => {
    expect(compactFrequency(['', null, 'Cada 500 h · anticipación 50 h'])).toBe('Cada 500 h')
  })
})
