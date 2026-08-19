import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import PreventivePlansPage from '../../src/pages/operations/PreventivePlansPage.vue'
import { preventivePlansData } from './fixtures.js'

describe('administración compacta de planes preventivos', () => {
  it('elimina el alta por plantilla de esta pantalla y deriva a Servicios', () => {
    const wrapper = mount(PreventivePlansPage, { props: { data: preventivePlansData } })

    expect(wrapper.find('form[action="/mantenimiento/planes/desde-plantilla"]').exists()).toBe(false)
    expect(wrapper.find('a[href="/mantenimiento/servicios"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Servicios asignados')
    expect(wrapper.text()).toContain('Servicios de mantenimiento')
  })

  it('no muestra ningún alta manual de plan en esta pantalla', async () => {
    const wrapper = mount(PreventivePlansPage, { props: { data: preventivePlansData } })

    expect(wrapper.find('form[action="/mantenimiento/planes"][method="post"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="open-manual-plan"]').exists()).toBe(false)
  })
})
