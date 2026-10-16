import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import CustomerImportsPage from '../../src/pages/admin/CustomerImportsPage.vue'

const base = {
  companies: [{ id: 1, name: 'Empresa Uno', cuit: '123' }],
  csrf: { name: 'csrf_token', hash: 'token' },
  actions: { preview: '/preview', template: '/template', show: '/batch/1', confirm: '/confirm/1', cancel: '/cancel/1', issues: '/issues/1', index: '/imports', portal: '/inicio', superadmin: '/superadmin' },
}
const batch = {
  id: 1, companyName: 'Empresa Uno', estado: 'PENDIENTE', page: 1, pages: 2,
  summary: { total: 51, new: 50, existing: 1, errors: 1, warnings: 1 },
  rows: [{ number: 2, data: { nombre: '<script>alert(1)</script>', codigo_origen: '001' }, errors: ['Nombre obligatorio'], warnings: ['Ubicación pendiente'], existing: false }],
}

describe('importación de clientes', () => {
  it('exige seleccionar empresa y archivo y conserva CSRF y multipart', async () => {
    const wrapper = mount(CustomerImportsPage, { props: { data: base } })
    expect(wrapper.get('select[name="empresa_id"]').element.value).toBe('')
    expect(wrapper.get('select').attributes('required')).toBeDefined()
    expect(wrapper.get('input[name="archivo_csv"]').attributes('required')).toBeDefined()
    expect(wrapper.get('form').attributes('enctype')).toBe('multipart/form-data')
    expect(wrapper.get('input[name="csrf_token"]').element.value).toBe('token')
    expect(wrapper.get('button[type="submit"]').element.disabled).toBe(true)
    wrapper.unmount()
  })
  it('bloquea confirmación con errores, escapa contenido y pagina las filas', () => {
    const wrapper = mount(CustomerImportsPage, { props: { data: { ...base, batch } } })
    const form = wrapper.get('form[action="/confirm/1"]')
    expect(form.get('button').element.disabled).toBe(true)
    expect(form.find('[name="empresa_id"]').exists()).toBe(false)
    expect(wrapper.find('script').exists()).toBe(false)
    expect(wrapper.text()).toContain('<script>alert(1)</script>')
    expect(wrapper.get('a[href="/batch/1?page=2"]').exists()).toBe(true)
    wrapper.unmount()
  })
  it('informa resultado y retira las acciones de un lote confirmado', () => {
    const wrapper = mount(CustomerImportsPage, { props: { data: { ...base, batch: { ...batch, estado: 'CONFIRMADO', resultado: { inserted: 50, skipped: 1 } } } } })
    expect(wrapper.text()).toContain('50 clientes importados')
    expect(wrapper.text()).toContain('1 ya existentes omitidos')
    expect(wrapper.find('form[action="/confirm/1"]').exists()).toBe(false)
    wrapper.unmount()
  })
})
