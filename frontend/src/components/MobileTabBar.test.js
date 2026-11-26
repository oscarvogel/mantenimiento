import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import MobileTabBar from './MobileTabBar.vue'

const item = (key, extra = {}) => ({ key, label: key, href: `/${key}`, ...extra })

describe('MobileTabBar', () => {
  it('muestra solo los accesos que el usuario tiene, más el menú', () => {
    // A driver who can only load readings.
    const wrapper = mount(MobileTabBar, { props: { navigation: [item('quick-readings', { active: true })] } })
    expect(wrapper.findAll('a').map((link) => link.text())).toEqual(['Km/horas'])
    expect(wrapper.get('a').attributes('aria-current')).toBe('page')
    expect(wrapper.get('button').text()).toContain('Menú')
  })

  it('abre el menú completo', async () => {
    const wrapper = mount(MobileTabBar, { props: { navigation: [item('dashboard')] } })
    await wrapper.get('button').trigger('click')
    expect(wrapper.emitted('open-menu')).toHaveLength(1)
  })

  it('en Viajes el tercer acceso son los viajes operativos', () => {
    const wrapper = mount(MobileTabBar, { props: { navigation: [item('trips-operations-dashboard'), item('trips-operations')] } })
    expect(wrapper.findAll('a').map((link) => link.text())).toEqual(['Resumen', 'Viajes'])
  })

  it('no aparece si no hay ningún acceso diario', () => {
    const wrapper = mount(MobileTabBar, { props: { navigation: [item('users')] } })
    expect(wrapper.find('nav').exists()).toBe(false)
  })

  it('al técnico le muestra Falla y Mi trabajo en lugar de todas las órdenes', () => {
    const navigation = [item('dashboard'), item('quick-readings'), item('report-failure'), item('work-orders'), item('my-work')]
    const wrapper = mount(MobileTabBar, { props: { navigation } })
    expect(wrapper.findAll('a').map((link) => link.text())).toEqual(['Resumen', 'Km/horas', 'Falla', 'Mi trabajo'])
  })
})
