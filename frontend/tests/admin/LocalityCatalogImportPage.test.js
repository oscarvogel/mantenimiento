import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import LocalityCatalogImportPage from '../../src/pages/admin/LocalityCatalogImportPage.vue'

const baseData = {
  mode: 'preview',
  csrf: { name: 'csrf_test', hash: 'token' },
  flash: {},
  actions: { superadmin: '/superadmin', portal: '/inicio', index: '/importar', preview: '/importar', confirm: '/confirmar' },
  token: 'a'.repeat(32),
  preview: { country: 'BRASIL', source: 'IBGE_2022', version: '2022', total: 5, valid: 4, errors: 1, canConfirm: false, errorSamples: [] },
}

describe('LocalityCatalogImportPage', () => {
  it('blocks confirmation when the preview contains errors', () => {
    const wrapper = mount(LocalityCatalogImportPage, { props: { data: baseData } })
    expect(wrapper.text()).toContain('Corregí el archivo y volvé a subirlo')
    expect(wrapper.find('form[action="/confirmar"]').exists()).toBe(false)
  })

  it('offers confirmation for a valid catalog and explains its shared scope', () => {
    const wrapper = mount(LocalityCatalogImportPage, {
      props: { data: { ...baseData, preview: { ...baseData.preview, valid: 5, errors: 0, canConfirm: true } } },
    })
    expect(wrapper.text()).toContain('disponibles para todas las empresas')
    expect(wrapper.find('form[action="/confirmar"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Confirmar importación')
  })
})
