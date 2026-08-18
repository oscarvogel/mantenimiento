import { describe, expect, it } from 'vitest'
import { assignmentContextFromUrl, availableManualServices, compatibleTemplates, matchesTemplateQuery, templateSpecificity } from './quickPlanAssignment.js'

const equipment = {
  id: 28,
  typeId: 4,
  brandName: 'TSAARG',
  modelName: 'TSA Argentina',
  assignedServiceTypeIds: [20],
}

const templates = [
  { id: 1, templateId: 1, serviceTypeId: 10, serviceName: 'Aceite motor', equipmentTypeId: null, templateName: 'General' },
  { id: 2, templateId: 2, serviceTypeId: 10, serviceName: 'Aceite motor específico', equipmentTypeId: 4, brand: 'TSAARG', templateName: 'Camión TSA' },
  { id: 3, templateId: 3, serviceTypeId: 20, serviceName: 'Filtro de aire', equipmentTypeId: 4, templateName: 'Camión' },
  { id: 4, templateId: 4, serviceTypeId: 30, serviceName: 'Frenos', equipmentTypeId: 7, templateName: 'Máquinas' },
  { id: 5, templateId: 5, serviceTypeId: 40, serviceName: 'Transmisión', equipmentTypeId: 4, model: 'TSA Argentina', templateName: 'Modelo exacto', notes: 'Aceite y filtro' },
]

describe('quick plan assignment', () => {
  it('prioriza la plantilla más específica y evita servicios ya asignados o incompatibles', () => {
    const result = compatibleTemplates(equipment, templates)

    expect(result.map((item) => item.id)).toEqual([5, 2])
    expect(result.some((item) => item.serviceTypeId === 20)).toBe(false)
    expect(result.some((item) => item.serviceTypeId === 30)).toBe(false)
  })

  it('ofrece para creación directa sólo servicios todavía no asignados', () => {
    const services = [
      { id: 10, code: 'ACEITE', name: 'Cambio de aceite' },
      { id: 20, code: 'FILTRO', name: 'Filtro de aire' },
      { id: 30, code: 'FRENOS', name: 'Frenos' },
    ]

    expect(availableManualServices(equipment, services).map((item) => item.id)).toEqual([10, 30])
  })

  it('busca por servicio, plantilla, notas, marca o modelo', () => {
    expect(matchesTemplateQuery(templates[4], 'transmisión')).toBe(true)
    expect(matchesTemplateQuery(templates[4], 'aceite')).toBe(true)
    expect(matchesTemplateQuery(templates[4], 'modelo exacto')).toBe(true)
    expect(matchesTemplateQuery(templates[1], 'tsaarg')).toBe(true)
    expect(matchesTemplateQuery(templates[4], 'inexistente')).toBe(false)
  })

  it('ordena especificidad modelo > marca/tipo > tipo > genérica', () => {
    expect(templateSpecificity({ model: 'X' })).toBe(4)
    expect(templateSpecificity({ brand: 'Y', equipmentTypeId: 1 })).toBe(3)
    expect(templateSpecificity({ equipmentTypeId: 1 })).toBe(2)
    expect(templateSpecificity({})).toBe(1)
  })

  it('obtiene el equipo correcto desde el enlace Asignar plan del listado', () => {
    const context = assignmentContextFromUrl(
      '/mantenimiento/planes?equipo_id=28#planes-desde-plantilla',
      'https://vogelconsultoria.com.ar/mantenimiento/equipos',
    )

    expect(context?.equipmentId).toBe(28)
    expect(context?.sourceUrl).toContain('equipo_id=28')
  })

  it('no intercepta enlaces ajenos a planes', () => {
    expect(assignmentContextFromUrl('/mantenimiento/equipos/28', 'https://example.com/')).toBeNull()
  })
})
