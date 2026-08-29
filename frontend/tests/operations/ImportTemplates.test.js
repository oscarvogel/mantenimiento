import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import ImportsIndexPage from '../../src/pages/operations/ImportsIndexPage.vue'
import { importsData } from './fixtures.js'

describe('plantillas de importación operativa', () => {
  it('ofrece plantillas y tipos para unidades TSA y vencimientos', () => {
    const data = {
      ...importsData,
      routes: {
        ...importsData.routes,
        templates: {
          ...importsData.routes.templates,
          transportUnits: '/mantenimiento/importaciones/plantilla/UNIDADES_TRANSPORTE',
          expirations: '/mantenimiento/importaciones/plantilla/VENCIMIENTOS',
        },
      },
    }
    const wrapper = mount(ImportsIndexPage, { props: { data } })

    expect(wrapper.find('a[href="/mantenimiento/importaciones/plantilla/UNIDADES_TRANSPORTE"]').text()).toContain('unidades de transporte')
    expect(wrapper.find('a[href="/mantenimiento/importaciones/plantilla/VENCIMIENTOS"]').text()).toContain('vencimientos')
    expect(wrapper.find('option[value="UNIDADES_TRANSPORTE"]').text()).toContain('Unidades')
    expect(wrapper.find('option[value="VENCIMIENTOS"]').text()).toContain('Vencimientos')
  })
})
