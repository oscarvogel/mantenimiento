import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import AssetsIndexPage from '../../src/pages/operations/AssetsIndexPage.vue'
import EquipmentDetailPage from '../../src/pages/operations/EquipmentDetailPage.vue'
import ImportsIndexPage from '../../src/pages/operations/ImportsIndexPage.vue'
import ImportsShowPage from '../../src/pages/operations/ImportsShowPage.vue'
import MaintenanceOverviewPage from '../../src/pages/operations/MaintenanceOverviewPage.vue'
import PreventiveLibraryPage from '../../src/pages/operations/PreventiveLibraryPage.vue'
import PreventivePlansPage from '../../src/pages/operations/PreventivePlansPage.vue'
import { resolveOperationPage } from '../../src/pages/operations/index.js'
import { assetsData, equipmentData, importsData, importShowData, maintenanceData, preventiveLibraryData, preventivePlansData } from './fixtures.js'

const wrappers = []
const render = (component, data, options = {}) => {
  const wrapper = mount(component, { props: { data }, attachTo: options.attachTo ?? document.body })
  wrappers.push(wrapper)
  return wrapper
}

afterEach(() => {
  wrappers.splice(0).forEach((wrapper) => wrapper.unmount())
  vi.restoreAllMocks()
})

describe('registro de componentes operativos', () => {
  it.each([
    ['maintenance-overview', MaintenanceOverviewPage],
    ['preventive-plans', PreventivePlansPage],
    ['equipment-detail', EquipmentDetailPage],
    ['assets-index', AssetsIndexPage],
    ['imports-index', ImportsIndexPage],
    ['imports-show', ImportsShowPage],
    ['preventive-library', PreventiveLibraryPage],
  ])('resuelve %s', (pageType, component) => {
    expect(resolveOperationPage(pageType)).toBe(component)
  })

  it('rechaza un pageType desconocido', () => {
    expect(resolveOperationPage('admin-users')).toBeNull()
  })
})

describe('preventive-plans', () => {
  it('crea planes con CSRF y muestra criterios por camión', async () => {
    const wrapper = render(PreventivePlansPage, preventivePlansData)
    const create = wrapper.get('form[action="/mantenimiento/planes"][method="post"]')
    expect(create.get('input[name="csrf_test_name"]').attributes('value')).toBe('secure-token')
    await create.get('select[name="equipo_id"]').setValue('9')
    expect(create.get('input[name="intervalo_km"]').exists()).toBe(true)
    expect(create.get('input[name="intervalo_km"]').element.value).toBe('10000')
    expect(create.get('input[name="anticipacion_km"]').element.value).toBe('1000')
    expect(create.get('input[name="intervalo_dias"]').element.value).toBe('180')
    expect(wrapper.text()).toContain('Preventivo camiones')
    expect(create.find('input[name="intervalo_horas"]').exists()).toBe(false)
    expect(wrapper.get('form[action="/mantenimiento/planes"][method="get"]').attributes('method')).toBe('get')
    const perPage = wrapper.get('select[aria-label="Registros por página"]')
    expect(perPage.element.value).toBe('10')
    expect(perPage.findAll('option').map((option) => option.element.value)).toEqual(['5', '10', '25'])
    expect(wrapper.text()).toContain('CAM-01')
    expect(wrapper.text()).toContain('Preventivo camiones')
    expect(wrapper.text()).toContain('Cada 1000 km')
    expect(wrapper.text()).toContain('próximo 10000 km')
  })

  it('conserva filtros y tamaño en los enlaces de paginación recibidos', () => {
    const nextUrl = '/mantenimiento/planes?q=CAM&sucursal_id=1&equipo_id=9&estado=PROXIMO&por_pagina=5&page=2'
    const data = { ...preventivePlansData, plans: { ...preventivePlansData.plans, pagination: { ...preventivePlansData.plans.pagination, totalPages: 2, perPage: 5, nextUrl } } }
    const wrapper = render(PreventivePlansPage, data)

    expect(wrapper.get(`a[href="${nextUrl}"]`).text()).toContain('Siguiente')
  })

  it('oculta el alta sin permiso y conserva el empty state', () => {
    const wrapper = render(PreventivePlansPage, { ...preventivePlansData, canEdit: false, plans: { ...preventivePlansData.plans, total: 0, items: [] } })
    expect(wrapper.find('form[action="/mantenimiento/planes"][method="post"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('No hay planes preventivos')
  })

  it('permite editar un plan activo con valores precargados', () => {
    const wrapper = render(PreventivePlansPage, preventivePlansData)
    const form = wrapper.get('form[action="/mantenimiento/planes/2/editar"][method="post"]')

    expect(form.get('input[name="csrf_test_name"]').attributes('value')).toBe('secure-token')
    expect(form.get('input[name="intervalo_km"]').element.value).toBe('1000')
    expect(form.get('input[name="anticipacion_km"]').element.value).toBe('200')
    expect(form.get('input[name="base_km"]').element.value).toBe('9000')
    expect(form.get('select[name="prioridad"]').element.value).toBe('MEDIA')
  })

  it('oculta el formulario de edición de plan sin permiso', () => {
    const data = { ...preventivePlansData, plans: { ...preventivePlansData.plans, items: [{ ...preventivePlansData.plans.items[0], editUrl: null }] } }
    const wrapper = render(PreventivePlansPage, data)

    expect(wrapper.find('form[action="/mantenimiento/planes/2/editar"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('Editar plan')
  })
})

describe('preventive-library', () => {
  it('muestra los servicios de la plantilla seleccionada con su jerarquía y botones', async () => {
    const wrapper = render(PreventiveLibraryPage, preventiveLibraryData)

    expect(wrapper.text()).toContain('Biblioteca preventiva')
    expect(wrapper.text()).toContain('Preventivo camiones')
    expect(wrapper.text()).toContain('Service motor')
    expect(wrapper.text()).toContain('Inspección frenos')
    expect(wrapper.text()).toContain('ACEITE')
    expect(wrapper.text()).toContain('FR')
    expect(wrapper.text()).toContain('Obligatoria')
    expect(wrapper.text()).toContain('Repuesto')
    expect(wrapper.text()).toContain('Control')

    const serviceToggles = wrapper.findAll('button[aria-expanded]')
    expect(serviceToggles).toHaveLength(2)
    expect(wrapper.find('form[action="/mantenimiento/importaciones/biblioteca/items/15"]').exists()).toBe(false)
    expect(wrapper.find('form[action="/mantenimiento/importaciones/biblioteca/tareas/1"]').exists()).toBe(false)

    await serviceToggles[0].trigger('click')
    const editButtons = wrapper.findAll('button').filter((b) => b.text().trim() === 'Editar')
    expect(editButtons.length).toBe(3)
    const frequencyButtons = wrapper.findAll('button').filter((b) => b.text().includes('Editar frecuencia'))
    expect(frequencyButtons.length).toBe(2)
  })

  it('filtra los servicios por el buscador y conserva el resto del contrato', async () => {
    const wrapper = render(PreventiveLibraryPage, preventiveLibraryData)

    await wrapper.get('input[type="search"][name="q"]').setValue('frenos')
    expect(wrapper.text()).toContain('Inspección frenos')
    expect(wrapper.text()).not.toContain('Service motor')

    const serviceToggles = wrapper.findAll('button[aria-expanded]')
    expect(serviceToggles).toHaveLength(1)
  })

  it('deja la biblioteca en modo lectura cuando no hay permiso de carga', async () => {
    const wrapper = render(PreventiveLibraryPage, { ...preventiveLibraryData, canEdit: false })

    const serviceToggles = wrapper.findAll('button[aria-expanded]')
    await serviceToggles[0].trigger('click')

    const editButtons = wrapper.findAll('button').filter((b) => b.text().trim() === 'Editar')
    expect(editButtons.length).toBe(0)
    const frequencyButtons = wrapper.findAll('button').filter((b) => b.text().includes('Editar frecuencia'))
    expect(frequencyButtons.length).toBeGreaterThanOrEqual(1)
    expect(frequencyButtons[0].attributes('disabled')).toBeDefined()
    expect(document.querySelector('[role="dialog"][aria-modal="true"]')).toBeNull()
  })

  it('abre el modal de edición con los valores actuales de la tarea al pulsar Editar', async () => {
    const wrapper = render(PreventiveLibraryPage, preventiveLibraryData)
    const serviceToggles = wrapper.findAll('button[aria-expanded]')
    await serviceToggles[0].trigger('click')

    const editButtons = wrapper.findAll('button').filter((b) => b.text().trim() === 'Editar')
    expect(editButtons.length).toBeGreaterThan(0)
    await editButtons[0].trigger('click')
    await flushPromises()

    const modal = document.querySelector('[role="dialog"][aria-modal="true"]')
    expect(modal).not.toBeNull()
    const form = modal.querySelector('form')
    expect(form).not.toBeNull()
    expect(form.getAttribute('action')).toBeNull()
    expect(form.querySelector('input[name="csrf_test_name"]').getAttribute('value')).toBe('secure-token')
    expect(form.querySelector('input[name="tipo_servicio_id"]').getAttribute('value')).toBe('3')
    expect(form.querySelector('input[name="nombre"]').value).toBe('Cambiar aceite de motor')
    expect(form.querySelector('input[name="orden"]').value).toBe('1')
    expect(form.querySelector('input[name="duracion_estimada_min"]').value).toBe('45')
    expect(form.querySelector('textarea[name="procedimiento"]').value).toBe('Drenar y reemplazar')
    expect(form.querySelector('input[name="requiere_repuesto"]').checked).toBe(true)
    expect(form.querySelector('input[name="requiere_control"]').checked).toBe(true)
    expect(form.querySelector('input[name="obligatoria"]').checked).toBe(true)
    expect(form.querySelector('input[name="activo"]').checked).toBe(true)
  })

  it('cierra el modal con Cancelar y deja la biblioteca como estaba', async () => {
    const wrapper = render(PreventiveLibraryPage, preventiveLibraryData)
    const serviceToggles = wrapper.findAll('button[aria-expanded]')
    await serviceToggles[0].trigger('click')

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
    expect(wrapper.text()).not.toContain('Edición de tarea')
  })

  it('no muestra los paneles "Plantillas de la empresa" ni "Catálogo de servicios"', () => {
    const wrapper = render(PreventiveLibraryPage, preventiveLibraryData)
    const html = wrapper.html()
    expect(html).not.toContain('Plantillas de la empresa')
    expect(html).not.toContain('Catálogo de servicios')
  })
})

describe('maintenance-overview', () => {
  it('conserva rutas POST, CSRF y formularios del circuito', () => {
    const wrapper = render(MaintenanceOverviewPage, maintenanceData)

    expect(wrapper.get('form[action="/mantenimiento/equipos"]').attributes('method')).toBe('post')
    expect(wrapper.get('form[action="/mantenimiento/equipos/9/lecturas"]').attributes('method')).toBe('post')
    expect(wrapper.get('form[action="/mantenimiento/equipos/9/planes"]').attributes('method')).toBe('post')
    const assignPlan = wrapper.get('form[action="/mantenimiento/equipos/9/planes"]')
    expect(assignPlan.get('input[name="intervalo_km"]').element.value).toBe('10000')
    expect(assignPlan.get('input[name="anticipacion_km"]').element.value).toBe('1000')
    expect(assignPlan.get('input[name="intervalo_horas"]').element.value).toBe('250.0')
    expect(assignPlan.get('input[name="observaciones"]').element.value).toBe('Aceite y filtros')
    expect(wrapper.get('form[action="/mantenimiento/vencimientos/detectar"]').attributes('method')).toBe('post')
    expect(wrapper.get('form[action="/mantenimiento/avisos/3/orden"]').attributes('method')).toBe('post')
    expect(wrapper.get('form[action="/mantenimiento/ordenes/4/cerrar"]').attributes('method')).toBe('post')
    expect(wrapper.findAll('input[name="csrf_test_name"]').every((input) => input.attributes('value') === 'secure-token')).toBe(true)
    expect(wrapper.findAll('select[aria-label="Registros por página"]')).toHaveLength(5)
    expect(wrapper.get('a[href*="equipos_page=2"]').attributes('href')).toContain('lecturas_per_page=10')
  })

  it('respeta permisos y estados vacíos', () => {
    const data = { ...maintenanceData, can: Object.fromEntries(Object.keys(maintenanceData.can).map((key) => [key, false])), equipments: [], plans: [], notices: [], orders: [], readings: [] }
    const wrapper = render(MaintenanceOverviewPage, data)

    expect(wrapper.find('form[action="/mantenimiento/equipos"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Todavía no hay equipos')
    expect(wrapper.text()).toContain('No hay planes activos')
    expect(wrapper.text()).toContain('Todavía no hay órdenes')
  })
})

describe('assets-index', () => {
  it('presenta filtros GET, fichas, QR y mutaciones de catálogos', () => {
    const wrapper = render(AssetsIndexPage, assetsData)

    expect(wrapper.get('form[action="/mantenimiento/equipos"][method="get"]').attributes('method')).toBe('get')
    expect(wrapper.find('a[href="/mantenimiento/equipos/9"]').exists()).toBe(true)
    expect(wrapper.find('a[href="/mantenimiento/equipos/9/qr.svg"][target="_blank"]').exists()).toBe(true)
    expect(wrapper.get('form[action="/mantenimiento/catalogos/marcas/2/inactivar"]').attributes('method')).toBe('post')
    expect(wrapper.get('form[action="/mantenimiento/catalogos/modelos/3/inactivar"]').attributes('method')).toBe('post')
    const create = wrapper.get('form[action="/mantenimiento/equipos"][method="post"]')
    for (const name of ['sucursal_id', 'tipo_equipo_id', 'codigo', 'patente', 'marca_id', 'modelo_id', 'fecha_alta', 'anio', 'chasis', 'motor', 'observaciones']) {
      expect(create.find(`[name="${name}"]`).exists()).toBe(true)
    }
    expect(create.get('input[name="csrf_test_name"]').attributes('value')).toBe('secure-token')
  })

  it('permite asignar planes desde el listado cuando hay permiso', () => {
    const wrapper = render(AssetsIndexPage, assetsData)
    const links = wrapper.findAll('a[href="/mantenimiento/planes?equipo_id=9#planes-desde-plantilla"]')

    expect(links).toHaveLength(2)
    expect(links[0].text()).toContain('Asignar plan')
    expect(links[1].text()).toContain('Asignar plan')
  })

  it('oculta el botón de asignar plan sin permiso', () => {
    const data = { ...assetsData, equipment: { ...assetsData.equipment, items: [{ ...assetsData.equipment.items[0], assignPlanUrl: null }] } }
    const wrapper = render(AssetsIndexPage, data)

    expect(wrapper.find('a[href="/mantenimiento/planes?equipo_id=9#planes-desde-plantilla"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('Asignar plan')
  })

  it('oculta edición de catálogos a usuarios de consulta', () => {
    const wrapper = render(AssetsIndexPage, { ...assetsData, canEdit: false })
    expect(wrapper.find('form[action="/mantenimiento/catalogos/marcas"]').exists()).toBe(false)
    expect(wrapper.find('form[action="/mantenimiento/equipos"][method="post"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('Crear marca')
  })

  it('filtra modelos por la marca y el tipo elegidos en el alta directa', async () => {
    const wrapper = render(AssetsIndexPage, assetsData)
    const brand = wrapper.get('#new-equipment-brand')
    const type = wrapper.get('#new-equipment-type')

    await brand.setValue('2')
    expect(wrapper.get('#new-equipment-model').text()).toContain('R450')
    expect(wrapper.get('#new-equipment-model').text()).not.toContain('FH')

    await brand.setValue('4')
    expect(wrapper.get('#new-equipment-model').text()).toContain('FH')
    expect(wrapper.get('#new-equipment-model').text()).not.toContain('R450')

    await type.setValue('2')
    expect(wrapper.get('#new-equipment-model option[value=""]').exists()).toBe(true)
    expect(wrapper.findAll('#new-equipment-model option')).toHaveLength(1)
  })

  it('pagina equipos, marcas y modelos de forma independiente sin recortar los catalogos de alta', () => {
    const wrapper = render(AssetsIndexPage, assetsData)
    const selectors = wrapper.findAll('select[aria-label="Registros por página"]')

    expect(selectors).toHaveLength(3)
    expect(selectors.map((selector) => selector.element.value)).toEqual(['10', '5', '10'])
    expect(selectors.every((selector) => selector.findAll('option').map((option) => option.text()).join(',') === '5,10,25')).toBe(true)
    expect(wrapper.findAll('input[id^="brand-"]')).toHaveLength(1)
    expect(wrapper.findAll('input[id^="model-"]')).toHaveLength(1)
    expect(wrapper.get('#new-equipment-brand').findAll('option')).toHaveLength(3)
    expect(wrapper.get('#new-equipment-model').findAll('option')).toHaveLength(1)
    expect(wrapper.find('a[href="?brand_page=2&brand_per_page=5&model_page=1&model_per_page=10"]').exists()).toBe(true)
    expect(wrapper.find('a[href="?brand_page=1&brand_per_page=5&model_page=2&model_per_page=10"]').exists()).toBe(true)
  })
})

describe('equipment-detail', () => {
  it('conserva todas las operaciones sensibles y descarga privada', () => {
    const wrapper = render(EquipmentDetailPage, equipmentData)

    for (const action of ['/mantenimiento/equipos/9/editar', '/mantenimiento/equipos/9/trasladar', '/mantenimiento/equipos/9/baja', '/mantenimiento/equipos/9/relaciones', '/mantenimiento/equipos/9/relaciones/1/finalizar', '/mantenimiento/equipos/9/adjuntos', '/mantenimiento/equipos/9/adjuntos/2/retirar', '/mantenimiento/equipos/9/lecturas/4/corregir']) {
      expect(wrapper.get(`form[action="${action}"]`).attributes('method')).toBe('post')
    }
    expect(wrapper.find('a[href="/mantenimiento/equipos/9/adjuntos/2/descargar"]').exists()).toBe(true)
    expect(wrapper.get('select[name="tipo_equipo_id"]').element.value).toBe('1')
    expect(wrapper.get('input[name="fecha_alta"]').element.value).toBe('2025-01-02')
  })

  it('mantiene modo consulta para baja y sin permiso de lecturas', () => {
    const data = { ...equipmentData, equipment: { ...equipmentData.equipment, status: 'BAJA' }, can: { edit: false, correctReadings: false }, readings: null }
    const wrapper = render(EquipmentDetailPage, data)
    expect(wrapper.text()).toContain('No tenés permiso para consultar lecturas')
    expect(wrapper.find('form[action="/mantenimiento/equipos/9/editar"]').exists()).toBe(false)
    expect(wrapper.find('form[action="/mantenimiento/equipos/9/baja"]').exists()).toBe(false)
  })

  it('marca la baja lógica con confirmación declarativa destructiva', () => {
    const wrapper = render(EquipmentDetailPage, equipmentData)
    const form = wrapper.get('form[action="/mantenimiento/equipos/9/baja"]')

    expect(form.attributes('data-confirm')).toBeDefined()
    expect(form.attributes('data-confirm-danger')).toBe('true')
    expect(form.attributes('data-confirm-title')).toContain('Dar de baja')
  })

  it('muestra un selector de tamaño para cada listado paginable de la ficha', () => {
    const wrapper = render(EquipmentDetailPage, equipmentData)
    const selectors = wrapper.findAll('select[aria-label="Registros por página"]')
    expect(selectors).toHaveLength(4)
    expect(selectors.every((selector) => selector.element.value === '10')).toBe(true)
  })
})

describe('imports-index', () => {
  it('conserva multipart, plantillas, historial y paginación', () => {
    const wrapper = render(ImportsIndexPage, importsData)
    const upload = wrapper.get('form[action="/mantenimiento/importaciones"]')
    expect(upload.attributes('method')).toBe('post')
    expect(upload.attributes('enctype')).toBe('multipart/form-data')
    expect(wrapper.find('a[href="/mantenimiento/importaciones/plantilla/EQUIPOS"]').exists()).toBe(true)
    expect(wrapper.find('a[href="/mantenimiento/importaciones/8"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Página 1 de 2')
  })

  it('oculta la carga sin permiso y muestra historial vacío', () => {
    const wrapper = render(ImportsIndexPage, { ...importsData, canUpload: false, imports: { ...importsData.imports, total: 0, items: [] } })
    expect(wrapper.find('form[action="/mantenimiento/importaciones"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Todavía no hay importaciones')
  })
})

describe('imports-show', () => {
  it('conserva confirmación, cancelación, CSRF y resultados por fila', () => {
    const wrapper = render(ImportsShowPage, importShowData)
    expect(wrapper.get('form[action="/mantenimiento/importaciones/8/confirmar"]').attributes('method')).toBe('post')
    expect(wrapper.get('form[action="/mantenimiento/importaciones/8/cancelar"]').attributes('method')).toBe('post')
    expect(wrapper.text()).toContain('CAM-01')
    expect(wrapper.text()).toContain('VALIDA')
  })

  it('marca confirmar y cancelar con confirmación declarativa destructiva', () => {
    const wrapper = render(ImportsShowPage, importShowData)
    const confirmar = wrapper.get('form[action="/mantenimiento/importaciones/8/confirmar"]')
    const cancelar = wrapper.get('form[action="/mantenimiento/importaciones/8/cancelar"]')

    expect(confirmar.attributes('data-confirm')).toBeDefined()
    expect(cancelar.attributes('data-confirm')).toBeDefined()
    expect(cancelar.attributes('data-confirm-danger')).toBe('true')
  })

  it('deshabilita confirmar cuando no hay filas válidas', () => {
    const data = { ...importShowData, header: { ...importShowData.header, validRows: 0 } }
    const wrapper = render(ImportsShowPage, data)
    expect(wrapper.get('form[action="/mantenimiento/importaciones/8/confirmar"] button').attributes()).toHaveProperty('disabled')
  })
})
