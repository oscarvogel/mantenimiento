import { describe, expect, it } from 'vitest'
import {
  assignedPlansForEquipment,
  buildPlanUpdateParams,
  planFrequencySummary,
  planHasLastData,
  planLastSummary,
  planNextSummary,
} from './equipmentAssignedPlans.js'

const plan = (equipmentId, overrides = {}) => ({
  id: equipmentId,
  equipment: { id: equipmentId, code: `EQ-${equipmentId}` },
  serviceName: 'Cambio de aceite',
  priority: 'MEDIA',
  state: 'PROXIMO',
  editUrl: `/mantenimiento/planes/${equipmentId}/editar`,
  criteria: {
    kilometers: { interval: 10000, warning: 1000, base: 10000, next: 20000 },
    hours: null,
    date: { interval: 180, warning: 15, base: '2026-07-19', next: '2027-01-15' },
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

  it('resume la última realización usando solo las bases disponibles', () => {
    expect(planLastSummary(plan(28))).toBe('10000 km · 2026-07-19')
    expect(planLastSummary({ criteria: { kilometers: { interval: 10000, base: null } } })).toBe('Sin datos')
    expect(planHasLastData(plan(28))).toBe(true)
    expect(planHasLastData({ criteria: {} })).toBe(false)
  })

  it('resume el próximo vencimiento sin inventar datos faltantes', () => {
    expect(planNextSummary(plan(28))).toBe('20000 km · 2027-01-15')
    expect(planNextSummary({ criteria: { kilometers: { interval: 10000, next: null } } })).toBe('Sin datos')
  })

  it('arma la actualización preservando frecuencia, anticipación, prioridad y csrf', () => {
    const params = buildPlanUpdateParams(
      plan(28, { notes: 'Usar aceite homologado' }),
      { baseKm: '125000', baseHours: '', baseDate: '2026-08-10' },
      { name: 'csrf_test', hash: 'token-123' },
    )

    expect(params.get('intervalo_km')).toBe('10000')
    expect(params.get('anticipacion_km')).toBe('1000')
    expect(params.get('intervalo_dias')).toBe('180')
    expect(params.get('anticipacion_dias')).toBe('15')
    expect(params.get('base_km')).toBe('125000')
    expect(params.get('base_fecha')).toBe('2026-08-10')
    expect(params.get('prioridad')).toBe('MEDIA')
    expect(params.get('observaciones')).toBe('Usar aceite homologado')
    expect(params.get('csrf_test')).toBe('token-123')
  })
})
