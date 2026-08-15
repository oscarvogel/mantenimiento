import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import Swal from 'sweetalert2'
import PreventiveLibraryPage from './PreventiveLibraryPage.vue'

vi.mock('sweetalert2', () => ({
  default: {
    fire: vi.fn(),
    isLoading: vi.fn(() => false),
  },
}))

const baseData = () => ({
  routes: {
    back: '/mantenimiento/importaciones',
    downloadTemplate: '/mantenimiento/importaciones/plantilla/BIBLIOTECA_PREVENTIVA',
  },
  csrf: { name: 'csrf_test', hash: 'HASH123' },
  canEdit: true,
  templates: [
    { id: 1, code: 'CAM-GENERAL', name: 'General Camiones', equipmentType: 'Camión', itemCount: 2 },
    { id: 2, code: 'GEN', name: 'Genérica', equipmentType: 'Genérica', itemCount: 0 },
  ],
  services: [],
  items: [
    {
      id: 100,
      templateId: 1,
      templateCode: 'CAM-GENERAL',
      templateName: 'General Camiones',
      equipmentType: 'Camión',
      serviceTypeId: 11,
      serviceCode: 'ACEITE-MOTOR',
      serviceName: 'Cambio de aceite de motor',
      intervalKm: 20000,
      intervalHours: null,
      intervalDays: 365,
      warningKm: 2000,
      warningHours: null,
      warningDays: 30,
      priority: 'ALTA',
      active: true,
      notes: null,
      updateUrl: '/mantenimiento/importaciones/biblioteca/items/100',
      tasks: [
        {
          id: 500,
          code: 'T-500',
          name: 'Cambiar aceite motor',
          description: null,
          procedure: null,
          durationMinutes: 30,
          order: 1,
          mandatory: true,
          active: true,
          requiresPart: true,
          requiresControl: false,
          requiresPhoto: false,
          observations: null,
          serviceTypeId: 11,
          updateUrl: '/mantenimiento/importaciones/biblioteca/tareas/500',
        },
        {
          id: 501,
          code: 'T-501',
          name: 'Cambiar filtro de aceite',
          description: null,
          procedure: null,
          durationMinutes: 10,
          order: 2,
          mandatory: true,
          active: true,
          requiresPart: true,
          requiresControl: false,
          requiresPhoto: false,
          observations: null,
          serviceTypeId: 11,
          updateUrl: '/mantenimiento/importaciones/biblioteca/tareas/501',
        },
      ],
    },
    {
      id: 101,
      templateId: 1,
      templateCode: 'CAM-GENERAL',
      templateName: 'General Camiones',
      equipmentType: 'Camión',
      serviceTypeId: 12,
      serviceCode: 'FILTRO-AIRE',
      serviceName: 'Filtro de aire',
      intervalKm: 40000,
      intervalHours: null,
      intervalDays: 365,
      warningKm: 5000,
      warningHours: null,
      warningDays: 30,
      priority: 'MEDIA',
      active: true,
      notes: null,
      updateUrl: '/mantenimiento/importaciones/biblioteca/items/101',
      tasks: [
        {
          id: 502,
          code: 'T-502',
          name: 'Reemplazar filtro',
          description: null,
          procedure: null,
          durationMinutes: 15,
          order: 1,
          mandatory: false,
          active: true,
          requiresPart: true,
          requiresControl: false,
          requiresPhoto: false,
          observations: null,
          serviceTypeId: 12,
          updateUrl: '/mantenimiento/importaciones/biblioteca/tareas/502',
        },
      ],
    },
  ],
})

beforeEach(() => {
  window.localStorage.clear()
  Swal.fire.mockResolvedValue({ isConfirmed: false })
})

afterEach(() => {
  vi.restoreAllMocks()
  window.localStorage.clear()
})

describe('PreventiveLibraryPage', () => {
  it('renderiza la jerarquía plantilla -> servicios y selecciona la primera plantilla', () => {
    const wrapper = mount(PreventiveLibraryPage, {
      props: { data: baseData() },
    })

    const templateOptions = wrapper.findAll('#library-template option')
    expect(templateOptions).toHaveLength(2)
    expect(templateOptions[0].text()).toContain('CAM-GENERAL')

    const serviceToggles = wrapper.findAll('button[aria-expanded]')
    expect(serviceToggles).toHaveLength(2)
    expect(serviceToggles[0].text()).toContain('Cambio de aceite de motor')
    expect(serviceToggles[1].text()).toContain('Filtro de aire')
  })

  it('expone un botón Editar por tarea y un botón Editar frecuencia por servicio tras expandir', async () => {
    const wrapper = mount(PreventiveLibraryPage, {
      props: { data: baseData() },
    })
    const firstServiceToggle = wrapper.findAll('button[aria-expanded]')[0]
    await firstServiceToggle.trigger('click')

    const editButtons = wrapper.findAll('button').filter((b) => b.text().trim() === 'Editar')
    expect(editButtons.length).toBe(3)

    const frequencyButtons = wrapper.findAll('button').filter((b) => b.text().includes('Editar frecuencia'))
    expect(frequencyButtons.length).toBe(2)
  })

  it('no muestra los paneles "Plantillas de la empresa" ni "Catálogo de servicios"', () => {
    const wrapper = mount(PreventiveLibraryPage, {
      props: { data: baseData() },
    })
    const html = wrapper.html()
    expect(html).not.toContain('Plantillas de la empresa')
    expect(html).not.toContain('Catálogo de servicios')
  })

  it('abre el modal de edición al pulsar Editar sobre una tarea y lo precarga', async () => {
    const wrapper = mount(PreventiveLibraryPage, {
      props: { data: baseData() },
      attachTo: document.body,
    })

    const firstServiceToggle = wrapper.findAll('button[aria-expanded]')[0]
    await firstServiceToggle.trigger('click')

    const editButtons = wrapper.findAll('button').filter((b) => b.text().trim() === 'Editar')
    expect(editButtons.length).toBeGreaterThan(0)
    await editButtons[0].trigger('click')
    await flushPromises()

    const modal = document.querySelector('[role="dialog"][aria-modal="true"]')
    expect(modal).not.toBeNull()
    const nameInput = modal.querySelector('input[name="nombre"]')
    expect(nameInput).not.toBeNull()
    expect(nameInput.value).toBe('Cambiar aceite motor')
    const orderInput = modal.querySelector('input[name="orden"]')
    expect(Number(orderInput.value)).toBe(1)
    const durationInput = modal.querySelector('input[name="duracion_estimada_min"]')
    expect(durationInput.value).toBe('30')

    wrapper.unmount()
  })

  it('cierra el modal con Cancelar y no persiste cambios', async () => {
    const wrapper = mount(PreventiveLibraryPage, {
      props: { data: baseData() },
      attachTo: document.body,
    })
    const firstServiceToggle = wrapper.findAll('button[aria-expanded]')[0]
    await firstServiceToggle.trigger('click')
    const editButtons = wrapper.findAll('button').filter((b) => b.text().trim() === 'Editar')
    await editButtons[0].trigger('click')
    await flushPromises()

    const modal = document.querySelector('[role="dialog"][aria-modal="true"]')
    const cancelButton = Array.from(modal.querySelectorAll('button')).find(
      (b) => b.textContent.trim() === 'Cancelar',
    )
    expect(cancelButton).toBeDefined()
    cancelButton.click()
    await flushPromises()

    expect(document.querySelector('[role="dialog"][aria-modal="true"]')).toBeNull()

    wrapper.unmount()
  })

  it('confirma quitar una tarea mediante SweetAlert y no usa el confirm nativo', async () => {
    const wrapper = mount(PreventiveLibraryPage, {
      props: { data: baseData() },
    })
    const nativeConfirm = vi.spyOn(window, 'confirm')
    const firstServiceToggle = wrapper.findAll('button[aria-expanded]')[0]
    await firstServiceToggle.trigger('click')

    const removeButton = wrapper.findAll('button').find((button) => button.text().trim() === 'Quitar')
    expect(removeButton).toBeDefined()
    await removeButton.trigger('click')
    await flushPromises()

    expect(nativeConfirm).not.toHaveBeenCalled()
    expect(Swal.fire).toHaveBeenCalledWith(expect.objectContaining({
      title: '¿Quitar “Cambiar aceite motor” de “Cambio de aceite de motor”?',
      text: 'La tarea seguirá existiendo en el catálogo y podrá volver a utilizarse.',
      confirmButtonText: 'Quitar',
      cancelButtonText: 'Cancelar',
      showCancelButton: true,
      icon: 'warning',
    }))

    wrapper.unmount()
  })

  it('omite los botones Editar de tareas cuando el usuario no tiene permiso', async () => {
    const data = baseData()
    data.canEdit = false
    const wrapper = mount(PreventiveLibraryPage, {
      props: { data },
    })
    const firstServiceToggle = wrapper.findAll('button[aria-expanded]')[0]
    await firstServiceToggle.trigger('click')
    const editButtons = wrapper.findAll('button').filter((b) => b.text().trim() === 'Editar')
    expect(editButtons.length).toBe(0)
    const frequencyButtons = wrapper.findAll('button').filter((b) => b.text().includes('Editar frecuencia'))
    expect(frequencyButtons.length).toBeGreaterThanOrEqual(1)
    expect(frequencyButtons[0].attributes('disabled')).toBeDefined()
  })

  it('filtra servicios con el buscador', async () => {
    const wrapper = mount(PreventiveLibraryPage, {
      props: { data: baseData() },
    })
    const search = wrapper.find('input[type="search"]')
    await search.setValue('reemplazar')
    await flushPromises()
    const serviceToggles = wrapper.findAll('button[aria-expanded]')
    expect(serviceToggles).toHaveLength(1)
    expect(serviceToggles[0].text()).toContain('Filtro de aire')
  })

  it('cambia la selección de plantilla y muestra solo los ítems correspondientes', async () => {
    const wrapper = mount(PreventiveLibraryPage, {
      props: { data: baseData() },
    })
    const select = wrapper.find('#library-template')
    await select.setValue(2)
    await flushPromises()
    const serviceToggles = wrapper.findAll('button[aria-expanded]')
    expect(serviceToggles).toHaveLength(0)
    expect(wrapper.html()).toContain('Esta plantilla no tiene servicios')
  })

  it('persiste el item expandido en localStorage al abrir el modal de edición', async () => {
    const wrapper = mount(PreventiveLibraryPage, {
      props: { data: baseData() },
      attachTo: document.body,
    })
    const firstServiceToggle = wrapper.findAll('button[aria-expanded]')[0]
    await firstServiceToggle.trigger('click')
    const editButtons = wrapper.findAll('button').filter((b) => b.text().trim() === 'Editar')
    await editButtons[0].trigger('click')
    await flushPromises()

    const stored = window.localStorage.getItem('biblioteca:edit:lastItem:1:100')
    expect(stored).toBe('1')

    wrapper.unmount()
  })

  it('no contiene formularios inline largos en la vista principal', () => {
    const wrapper = mount(PreventiveLibraryPage, {
      props: { data: baseData() },
    })
    const forms = wrapper.findAll('form')
    expect(forms.length).toBe(0)
  })
})
