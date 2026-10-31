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

  it('shows valid rows in the success palette and zero errors as a neutral state', () => {
    const wrapper = mount(LocalityCatalogImportPage, {
      props: { data: { ...baseData, preview: { ...baseData.preview, valid: 5, errors: 0, canConfirm: true } } },
    })
    const cards = wrapper.findAll('.rounded-lg.border.p-4')
    const validCard = cards.find((card) => card.text().includes('Filas válidas'))
    const errorCard = cards.find((card) => card.text().includes('Filas con errores'))

    expect(validCard.classes()).toContain('bg-success-subtle')
    expect(validCard.find('p').classes()).toContain('text-success-strong')
    expect(errorCard.classes()).toContain('bg-surface-muted')
    expect(errorCard.classes()).not.toContain('bg-danger-subtle')
    expect(errorCard.findAll('p')[0].classes()).toContain('text-ink-muted')
  })

  it('uses danger colors for error rows only when errors exist', () => {
    const wrapper = mount(LocalityCatalogImportPage, { props: { data: baseData } })
    const errorCard = wrapper.findAll('.rounded-lg.border.p-4').find((card) => card.text().includes('Filas con errores'))

    expect(errorCard.classes()).toContain('bg-danger-subtle')
    expect(errorCard.find('p').classes()).toContain('text-danger-strong')
  })
})
