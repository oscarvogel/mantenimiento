import { describe, expect, it } from 'vitest'
import { assignedPlansForEquipment, planFrequencySummary, planNextSummary } from './equipmentAssignedPlans.js'

const plan = (equipmentId, overrides = {}) => ({
  id: equipmentId,
  equipment: { id: equipmentId, code: `EQ-${equipmentId}` },
  serviceName: 'Cambio de aceite',
  priority: 'MEDIA',
  state: 'PROXIMO',
  criteria: {
    kilometers: { interval: 10000, next: 20000 },
    hours: null,
    date: { interval: 180, next: '2027-01-15' },
  },
  ...overrides,
})

describe('equipment assigned plans', () => {
  it('muestra únicamente los planes del equipo solicitado', () => {
    const payload = { data: { plans: { items: [plan(28), plan(29), plan(28, { id: 3 })] } } }
    expect(assignedPlansForEquipment(payload, 28).map((item) => item.id)).toEqual([28, 3])
  })

  it('resume frecuencia combinando los criterios activos', () => {
    expect(planFrequencySummary(plan(28))).toBe('Cada 10000 km · Cada 180 días')
    expect(planFrequencySummary({ criteria: {} })).toBe('Sin frecuencia')
  })

  it('resume el próximo vencimiento sin inventar datos faltantes', () => {
    expect(planNextSummary(plan(28))).toBe('20000 km · 2027-01-15')
    expect(planNextSummary({ criteria: { kilometers: { interval: 10000, next: null } } })).toBe('Sin datos')
  })
})
