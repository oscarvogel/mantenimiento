import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import WorkOrderDocumentImportPage from './WorkOrderDocumentImportPage.vue'

const payload = () => ({
  mode: 'review',
  can: { closePreventive: true, registerReading: true },
  csrf: { name: 'csrf_test', hash: 'SECURE' },
  routes: {
    orders: '/mantenimiento/ordenes',
    newImport: '/mantenimiento/ordenes/importar',
    reanalyze: '/mantenimiento/ordenes/importar/7/analizar',
    download: '/mantenimiento/ordenes/importar/7/documento',
    confirm: '/mantenimiento/ordenes/importar/7/confirmar',
    equipmentContext: '/mantenimiento/ordenes/importar/7',
  },
  equipmentOptions: [
    { id: 10, code: 'CAM-01', plate: 'OIU270', currentKm: 1185000, currentHours: null },
    { id: 11, code: 'CAM-02', plate: 'AE123ZZ', currentKm: 900000, currentHours: null },
  ],
  import: {
    id: 7,
    originalName: 'orden-tony.jpg',
    mimeType: 'image/jpeg',
    status: 'ANALIZADO',
    error: null,
    analysis: {
      plate: 'OIU 270',
      readingType: 'km',
      readingValue: 1180076,
      totalAmount: 813382,
      currency: 'ARS',
      confidence: { plate: 0.99, total_amount: 0.93 },
    },
    proposal: {
      selectedEquipmentId: 10,
      serviceDate: '2026-08-06',
      readingType: 'km',
      readingValue: 1180076,
      supplier: 'Mecánica Tony',
      concept: 'Reparación',
      totalAmount: 813382,
      currency: 'ARS',
      correctiveAmount: null,
      preventiveAmount: null,
      works: [
        { description: 'Cambiar filtros de aceite', classification: 'preventivo', included: true, confidence: 0.95 },
        { description: 'Reparar pérdida de aire', classification: 'correctivo', included: true, confidence: 0.92 },
      ],
      materials: [{ description: 'Filtro de aceite', quantity: 3, unit: 'u' }],
      selectedPlanId: 5,
      preventivePlans: [{
        id: 5,
        servicio_nombre: 'Service motor',
        matchScore: 50,
        evidencedTaskCount: 1,
        requiredTasksEvidenced: false,
        taskMatches: [
          { taskId: 101, taskName: 'Cambiar filtro de aceite', required: true, evidenced: true, matchedDescription: 'Filtro de aceite' },
          { taskId: 102, taskName: 'Controlar refrigerante', required: true, evidenced: false, matchedDescription: null },
        ],
      }],
    },
  },
})

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
  document.body.innerHTML = ''
})

describe('WorkOrderDocumentImportPage', () => {
  it('bloquea lectura regresiva hasta confirmacion explicita', async () => {
    const wrapper = mount(WorkOrderDocumentImportPage, { props: { data: payload() } })

    expect(wrapper.text()).toContain('La lectura ingresada es menor que la lectura actual del equipo')
    const corrective = wrapper.findAll('button').find((button) => button.text().includes('Crear OT correctiva'))
    expect(corrective.attributes('disabled')).toBeDefined()

    const rollbackCheckbox = wrapper.findAll('input[type="checkbox"]').find((input) => input.element.parentElement?.textContent.includes('Revisé la lectura'))
    await rollbackCheckbox.setValue(true)
    expect(corrective.attributes('disabled')).toBeUndefined()
  })

  it('deja tareas obligatorias sin evidencia como pendientes y exige confirmacion parcial', async () => {
    const data = payload()
    data.import.proposal.readingValue = 1186000
    const wrapper = mount(WorkOrderDocumentImportPage, { props: { data } })

    expect(wrapper.text()).toContain('Controlar refrigerante')
    expect(wrapper.text()).toContain('Sin evidencia')
    expect(wrapper.text()).toContain('Tarea obligatoria del plan')

    const preventive = wrapper.findAll('button').find((button) => button.text().includes('Crear OT preventiva'))
    expect(preventive.attributes('disabled')).toBeDefined()

    const partialCheckbox = wrapper.findAll('input[type="checkbox"]').find((input) => input.element.parentElement?.textContent.includes('realización preventiva parcial'))
    await partialCheckbox.setValue(true)
    expect(preventive.attributes('disabled')).toBeUndefined()
  })

  it('recarga planes al cambiar manualmente el equipo', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({
        preventivePlans: [{ id: 8, servicio_nombre: 'Service 40.000 km', matchScore: 80, requiredTasksEvidenced: true, taskMatches: [] }],
        selectedPlanId: 8,
      }),
    }))
    const data = payload()
    data.import.proposal.readingValue = 950000
    const wrapper = mount(WorkOrderDocumentImportPage, { props: { data } })

    const equipmentSelect = wrapper.findAll('select').find((select) => select.text().includes('CAM-01') && select.text().includes('CAM-02'))
    await equipmentSelect.setValue('11')
    await flushPromises()

    expect(fetch).toHaveBeenCalledWith('/mantenimiento/ordenes/importar/7?equipment_id=11', expect.any(Object))
    expect(wrapper.text()).toContain('Service 40.000 km')
    expect(wrapper.findAll('option').some((option) => option.text().includes('coincidencia 80%'))).toBe(true)
  })

  it('usa un modal integrado en lugar del confirm nativo al crear una OT', async () => {
    const nativeConfirm = vi.spyOn(window, 'confirm')
    const data = payload()
    data.import.proposal.readingValue = 1186000
    const wrapper = mount(WorkOrderDocumentImportPage, { props: { data } })

    const corrective = wrapper.findAll('button').find((button) => button.text().includes('Crear OT correctiva'))
    await corrective.trigger('click')

    expect(nativeConfirm).not.toHaveBeenCalled()
    expect(wrapper.find('[role="dialog"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Confirmar creación')
    expect(wrapper.text()).toContain('CAM-01 · OIU270')
    expect(wrapper.text()).toContain('1.186.000 km')
    expect(wrapper.text()).toContain('ARS 813.382,00')
    expect(wrapper.find('input[name="action"]').element.value).toBe('corrective')

    const cancel = wrapper.findAll('button').find((button) => button.text() === 'Cancelar')
    await cancel.trigger('click')
    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
  })

  it('exige distribuir el total para crear correctiva y preventiva sin duplicar costos', async () => {
    const data = payload()
    data.import.proposal.readingValue = 1186000
    data.import.proposal.confirmPartialPreventive = true
    const wrapper = mount(WorkOrderDocumentImportPage, { props: { data } })

    expect(wrapper.text()).toContain('Importe total')
    expect(wrapper.text()).toContain('ARS 813.382,00')

    const both = wrapper.findAll('button').find((button) => button.text().includes('Crear ambas OT'))
    await both.trigger('click')

    expect(wrapper.text()).toContain('Distribución del importe')
    expect(wrapper.text()).toContain('La suma debe coincidir con el total del documento')
    const confirm = wrapper.findAll('button').find((button) => button.text() === 'Confirmar y crear')
    expect(confirm.attributes('disabled')).toBeDefined()

    const allocationInputs = wrapper.findAll('[role="dialog"] input[inputmode="decimal"]')
    await allocationInputs[0].setValue('500000')
    await allocationInputs[1].setValue('313382')

    expect(wrapper.text()).not.toContain('La suma debe coincidir con el total del documento')
    expect(confirm.attributes('disabled')).toBeUndefined()
  })
})
