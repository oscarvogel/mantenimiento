import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import MaintenanceServicesPage from '../../src/pages/operations/MaintenanceServicesPage.vue'

const data = {
  canEdit: true,
  csrf: { name: 'csrf_test_name', hash: 'csrf-hash' },
  urls: {
    create: '/mantenimiento/servicios',
    base: '/mantenimiento/servicios',
  },
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
    tareas_count: 0,
    materiales_count: 0,
    activo: true,
    tasks: [],
  }],
}

describe('configuración de tareas del servicio', () => {
  it('permite agregar una tarea al editar un servicio', async () => {
    globalThis.fetch = vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({
        ok: true,
        task: { id: 91, code: 'CAMBIO-ACEITE', name: 'Cambiar aceite', active: true, order: 1, mandatory: true },
      }),
    })

    const wrapper = mount(MaintenanceServicesPage, { props: { data } })
    await wrapper.get('button[aria-label="Editar Servicio PR77"]').trigger('click')

    expect(wrapper.text()).toContain('Tareas del servicio')
    await wrapper.get('input[name="tarea_codigo"]').setValue('CAMBIO-ACEITE')
    await wrapper.get('input[name="tarea_nombre"]').setValue('Cambiar aceite')
    await wrapper.get('form[data-task-form]').trigger('submit')

    expect(globalThis.fetch).toHaveBeenCalledWith(
      '/mantenimiento/servicios/40/tareas/nueva',
      expect.objectContaining({ method: 'POST' }),
    )
    expect(wrapper.text()).toContain('Cambiar aceite')
  })
})
