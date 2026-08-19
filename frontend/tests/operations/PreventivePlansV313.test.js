import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import PreventivePlansPage from '../../src/pages/operations/PreventivePlansPage.vue'
import { preventivePlansData } from './fixtures.js'

const wrappers = []

const mountPage = () => {
  const wrapper = mount(PreventivePlansPage, { props: { data: preventivePlansData }, attachTo: document.body })
  wrappers.push(wrapper)
  return wrapper
}

afterEach(() => wrappers.splice(0).forEach((wrapper) => wrapper.unmount()))

describe('V3.1.3 · planes preventivos operativos', () => {
  it('presenta asignaciones y deriva la definición de frecuencia al Servicio', () => {
    const wrapper = mountPage()

    expect(wrapper.find('form[action="/mantenimiento/planes"][method="post"]').exists()).toBe(false)
    expect(wrapper.find('a[href="/mantenimiento/servicios"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Servicios asignados')
    expect(wrapper.text()).toContain('Cada 1000 km')
    expect(wrapper.text()).toContain('próximo 10000 km')
  })

  it('muestra la definición vigente sin permitir redefinirla desde el equipo', async () => {
    const wrapper = mountPage()

    await wrapper.get('[data-testid="edit-plan-2"]').trigger('click')
    const modal = document.body.querySelector('[data-testid="edit-plan-modal"]')

    expect(modal).not.toBeNull()
    expect(modal.textContent).toContain('Definición del Servicio')
    expect(modal.querySelector('input[name="base_km"]').value).toBe('9000')
    expect(modal.querySelector('input[name="intervalo_km"]')).toBeNull()
    expect(modal.querySelector('input[name="anticipacion_km"]')).toBeNull()
  })

  it('oculta la acción de última realización cuando no hay permiso de edición', () => {
    const data = { ...preventivePlansData, canEdit: false }
    const wrapper = mount(PreventivePlansPage, { props: { data } })
    wrappers.push(wrapper)

    expect(wrapper.find('[data-testid="edit-plan-2"]').exists()).toBe(false)
  })
})
