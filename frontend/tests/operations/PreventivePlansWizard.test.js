import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import PreventivePlansPage from '../../src/pages/operations/PreventivePlansPage.vue'
import { preventivePlansData } from './fixtures.js'

describe('administración compacta de planes preventivos', () => {
  it('elimina la asignación por plantilla de esta pantalla y deriva a la biblioteca', () => {
    const wrapper = mount(PreventivePlansPage, { props: { data: preventivePlansData } })

    expect(wrapper.find('form[action="/mantenimiento/planes/desde-plantilla"]').exists()).toBe(false)
    expect(wrapper.find('a[href="/mantenimiento/importaciones/biblioteca"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Planes asignados')
    expect(wrapper.text()).toContain('Biblioteca preventiva')
  })

  it('mantiene el alta manual como excepción y no como flujo principal', async () => {
    const wrapper = mount(PreventivePlansPage, { props: { data: preventivePlansData } })

    expect(wrapper.find('form[action="/mantenimiento/planes"][method="post"]').exists()).toBe(false)
    await wrapper.get('[data-testid="open-manual-plan"]').trigger('click')
    expect(wrapper.find('form[action="/mantenimiento/planes"][method="post"]').exists()).toBe(true)
  })
})
