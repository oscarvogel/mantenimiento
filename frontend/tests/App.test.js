import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import App from '../src/App.vue'
import { normalizeDashboardPayload } from '../src/adapters/dashboardPayload.js'
import { dashboardPayload } from './fixtures.js'

const wrappers = []

const mountDashboard = (payload = dashboardPayload, props = {}) => {
  const wrapper = mount(App, {
    props: {
      dashboard: normalizeDashboardPayload(payload),
      ...props,
    },
    attachTo: document.body,
  })
  wrappers.push(wrapper)
  return wrapper
}

afterEach(() => {
  wrappers.splice(0).forEach((wrapper) => wrapper.unmount())
})

describe('Dashboard', () => {
  it('muestra resumen, navegación real y mantenimiento próximo', () => {
    const wrapper = mountDashboard()

    expect(wrapper.get('h1').text()).toContain('Ana')
    expect(wrapper.text()).toContain('24')
    expect(wrapper.text()).toContain('5')
    expect(wrapper.text()).toContain('2')
    expect(wrapper.text()).toContain('Scania R450')
    expect(wrapper.text()).toContain('Próximo')
    expect(wrapper.find('a[href="/mantenimiento/equipos"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Sucursal Centro')
  })

  it('mantiene el cierre de sesión como POST con CSRF', () => {
    const wrapper = mountDashboard()
    const form = wrapper.get('form[action="/logout"]')

    expect(form.attributes('method')).toBe('post')
    expect(form.get('input[name="csrf_test_name"]').attributes('value')).toBe('secure-token')
  })

  it('abre y cierra la navegación móvil de forma accesible', async () => {
    const wrapper = mountDashboard()

    await wrapper.get('button[aria-label="Abrir menú principal"]').trigger('click')
    expect(wrapper.find('button[aria-label="Cerrar menú principal"]').exists()).toBe(true)
    expect(document.body.classList.contains('overflow-hidden')).toBe(true)

    await wrapper.get('button[aria-label="Cerrar menú principal"]').trigger('click')
    expect(document.body.classList.contains('overflow-hidden')).toBe(false)
  })

  it('expone estados de carga y vacío', () => {
    const loading = mountDashboard(dashboardPayload, { loading: true })
    expect(loading.get('[role="status"]').attributes('aria-label')).toBe('Cargando panel')
    loading.unmount()

    const empty = mountDashboard({ ...dashboardPayload, upcomingMaintenance: [] })
    expect(empty.text()).toContain('No hay mantenimientos próximos')
  })

  it('informa cuando el servidor no entrega datos sin usar el demo', () => {
    const wrapper = mountDashboard(null)

    expect(wrapper.text()).toContain('todavía no recibió información del servidor')
    expect(wrapper.text()).toContain('No hay mantenimientos próximos')
  })
})
