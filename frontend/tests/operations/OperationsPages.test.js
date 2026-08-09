import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import AssetsIndexPage from '../../src/pages/operations/AssetsIndexPage.vue'
import EquipmentDetailPage from '../../src/pages/operations/EquipmentDetailPage.vue'
import ImportsIndexPage from '../../src/pages/operations/ImportsIndexPage.vue'
import ImportsShowPage from '../../src/pages/operations/ImportsShowPage.vue'
import MaintenanceOverviewPage from '../../src/pages/operations/MaintenanceOverviewPage.vue'
import { resolveOperationPage } from '../../src/pages/operations/index.js'
import { assetsData, equipmentData, importsData, importShowData, maintenanceData } from './fixtures.js'

const wrappers = []
const render = (component, data) => {
  const wrapper = mount(component, { props: { data }, attachTo: document.body })
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
    ['equipment-detail', EquipmentDetailPage],
    ['assets-index', AssetsIndexPage],
    ['imports-index', ImportsIndexPage],
    ['imports-show', ImportsShowPage],
  ])('resuelve %s', (pageType, component) => {
    expect(resolveOperationPage(pageType)).toBe(component)
  })

  it('rechaza un pageType desconocido', () => {
    expect(resolveOperationPage('admin-users')).toBeNull()
  })
})

describe('maintenance-overview', () => {
  it('conserva rutas POST, CSRF y formularios del circuito', () => {
    const wrapper = render(MaintenanceOverviewPage, maintenanceData)

    expect(wrapper.get('form[action="/mantenimiento/equipos"]').attributes('method')).toBe('post')
    expect(wrapper.get('form[action="/mantenimiento/equipos/9/lecturas"]').attributes('method')).toBe('post')
    expect(wrapper.get('form[action="/mantenimiento/equipos/9/planes"]').attributes('method')).toBe('post')
    expect(wrapper.get('form[action="/mantenimiento/vencimientos/detectar"]').attributes('method')).toBe('post')
    expect(wrapper.get('form[action="/mantenimiento/avisos/3/orden"]').attributes('method')).toBe('post')
    expect(wrapper.get('form[action="/mantenimiento/ordenes/4/cerrar"]').attributes('method')).toBe('post')
    expect(wrapper.findAll('input[name="csrf_test_name"]').every((input) => input.attributes('value') === 'secure-token')).toBe(true)
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

    expect(wrapper.get('form[action="/mantenimiento/equipos"]').attributes('method')).toBe('get')
    expect(wrapper.find('a[href="/mantenimiento/equipos/9"]').exists()).toBe(true)
    expect(wrapper.find('a[href="/mantenimiento/equipos/9/qr.svg"][target="_blank"]').exists()).toBe(true)
    expect(wrapper.get('form[action="/mantenimiento/catalogos/marcas/2/inactivar"]').attributes('method')).toBe('post')
    expect(wrapper.get('form[action="/mantenimiento/catalogos/modelos/3/inactivar"]').attributes('method')).toBe('post')
  })

  it('oculta edición de catálogos a usuarios de consulta', () => {
    const wrapper = render(AssetsIndexPage, { ...assetsData, canEdit: false })
    expect(wrapper.find('form[action="/mantenimiento/catalogos/marcas"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('Crear marca')
  })
})

describe('equipment-detail', () => {
  it('conserva todas las operaciones sensibles y descarga privada', () => {
    const wrapper = render(EquipmentDetailPage, equipmentData)

    for (const action of ['/mantenimiento/equipos/9/editar', '/mantenimiento/equipos/9/trasladar', '/mantenimiento/equipos/9/baja', '/mantenimiento/equipos/9/relaciones', '/mantenimiento/equipos/9/relaciones/1/finalizar', '/mantenimiento/equipos/9/adjuntos', '/mantenimiento/equipos/9/adjuntos/2/retirar', '/mantenimiento/equipos/9/lecturas/4/corregir']) {
      expect(wrapper.get(`form[action="${action}"]`).attributes('method')).toBe('post')
    }
    expect(wrapper.find('a[href="/mantenimiento/equipos/9/adjuntos/2/descargar"]').exists()).toBe(true)
  })

  it('mantiene modo consulta para baja y sin permiso de lecturas', () => {
    const data = { ...equipmentData, equipment: { ...equipmentData.equipment, status: 'BAJA' }, can: { edit: false, correctReadings: false }, readings: null }
    const wrapper = render(EquipmentDetailPage, data)
    expect(wrapper.text()).toContain('No tenés permiso para consultar lecturas')
    expect(wrapper.find('form[action="/mantenimiento/equipos/9/editar"]').exists()).toBe(false)
    expect(wrapper.find('form[action="/mantenimiento/equipos/9/baja"]').exists()).toBe(false)
  })

  it('permite cancelar la confirmación de baja lógica', () => {
    vi.spyOn(window, 'confirm').mockReturnValue(false)
    const wrapper = render(EquipmentDetailPage, equipmentData)
    const form = wrapper.get('form[action="/mantenimiento/equipos/9/baja"]').element
    const event = new Event('submit', { bubbles: true, cancelable: true })
    form.dispatchEvent(event)
    expect(event.defaultPrevented).toBe(true)
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

  it('cancela submit cuando el usuario no confirma persistencia', () => {
    vi.spyOn(window, 'confirm').mockReturnValue(false)
    const wrapper = render(ImportsShowPage, importShowData)
    const form = wrapper.get('form[action="/mantenimiento/importaciones/8/confirmar"]').element
    const event = new Event('submit', { bubbles: true, cancelable: true })
    form.dispatchEvent(event)
    expect(event.defaultPrevented).toBe(true)
  })

  it('deshabilita confirmar cuando no hay filas válidas', () => {
    const data = { ...importShowData, header: { ...importShowData.header, validRows: 0 } }
    const wrapper = render(ImportsShowPage, data)
    expect(wrapper.get('form[action="/mantenimiento/importaciones/8/confirmar"] button').attributes()).toHaveProperty('disabled')
  })
})
