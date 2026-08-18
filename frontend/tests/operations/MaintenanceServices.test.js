import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import MaintenanceServicesPage from '../../src/pages/operations/MaintenanceServicesPage.vue'

const data = {
  canEdit: true,
  csrf: { name: 'csrf_test_name', hash: 'csrf-hash' },
  urls: { create: '/mantenimiento/servicios', base: '/mantenimiento/servicios' },
  services: [{
    id: 40,
    codigo: 'PR77',
    nombre: 'Servicio PR77',
    descripcion: 'Servicio de prueba',
    categoria: 'Preventivo',
    prioridad: 'MEDIA',
    intervalo_km: 1500,
    intervalo_horas: null,
    intervalo_dias: null,
    anticipacion_km: 100,
    anticipacion_horas: null,
    anticipacion_dias: null,
    tareas_count: 1,
    materiales_count: 0,
    activo: true,
    tasks: [{ id: 91, code: 'TAR-CAMBIAR-ACEITE', name: 'Cambiar aceite', active: true, order: 1, mandatory: true, materials: [] }],
  }],
}

function editButton(wrapper) {
  return wrapper.findAll('button').find((button) => button.text().includes('Editar'))
}

describe('configuración de tareas del servicio', () => {
  it('permite agregar una tarea sin pedir código técnico', async () => {
    globalThis.fetch = vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ ok: true, task: { id: 92, code: 'TAR-FILTRO', name: 'Cambiar filtro', active: true, order: 2, mandatory: true } }),
    })

    const wrapper = mount(MaintenanceServicesPage, { props: { data } })
    await editButton(wrapper).trigger('click')

    expect(wrapper.text()).toContain('Tareas del servicio')
    expect(wrapper.text()).not.toContain('Código')
    const taskInput = wrapper.find('input[placeholder="Cambiar filtro de aceite"]')
    await taskInput.setValue('Cambiar filtro')
    await wrapper.get('form[data-task-form]').trigger('submit')

    expect(globalThis.fetch).toHaveBeenCalledWith('/mantenimiento/servicios/40/tareas/nueva', expect.objectContaining({ method: 'POST' }))
    expect(wrapper.text()).toContain('Cambiar filtro')
  })

  it('carga repuesto y cantidad dentro de la fila de la tarea', async () => {
    globalThis.fetch = vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ ok: true, material: { id: 7, taskId: 91, description: 'Filtro aceite', quantity: '1.000', unit: 'UN', type: 'REPUESTO', active: true } }),
    })

    const wrapper = mount(MaintenanceServicesPage, { props: { data } })
    await editButton(wrapper).trigger('click')
    await wrapper.find('input[placeholder="Filtro de aceite"]').setValue('Filtro aceite')
    const materialForm = wrapper.findAll('form').find((form) => form.text().includes('Repuesto / insumo'))
    await materialForm.trigger('submit')

    expect(globalThis.fetch).toHaveBeenCalledWith('/mantenimiento/servicios/40/materiales', expect.objectContaining({ method: 'POST' }))
    expect(wrapper.text()).toContain('Filtro aceite')
    expect(wrapper.text()).toContain('1 UN')
  })

  it('no pide código al crear un servicio', async () => {
    const wrapper = mount(MaintenanceServicesPage, { props: { data } })
    const newService = wrapper.findAll('button').find((button) => button.text().includes('Nuevo servicio'))
    await newService.trigger('click')
    expect(wrapper.find('input[name="codigo"]').exists()).toBe(false)
  })
})
