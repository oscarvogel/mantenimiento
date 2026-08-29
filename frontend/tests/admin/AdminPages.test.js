import { nextTick } from 'vue'
import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import {
  BranchesAdminPage,
  SuperAdminDemoPage,
  SuperAdminPage,
  UsersAdminPage,
  UsersAdminWithAuditPage,
  adminPagesByType,
} from '../../src/pages/admin/index.js'
import { branchesAdminData, superAdminData, usersAdminData } from './fixtures.js'

const wrappers = []

const mountPage = (component, data) => {
  const wrapper = mount(component, { props: { data }, attachTo: document.body })
  wrappers.push(wrapper)
  return wrapper
}

afterEach(() => {
  wrappers.splice(0).forEach((wrapper) => wrapper.unmount())
})

describe('registro de páginas administrativas', () => {
  it('exporta los componentes por pageType', () => {
    expect(adminPagesByType.superadmin).toBe(SuperAdminDemoPage)
    expect(adminPagesByType['branches-admin']).toBe(BranchesAdminPage)
    expect(adminPagesByType['users-admin']).toBe(UsersAdminWithAuditPage)
  })
})

describe('SuperAdminDemoPage', () => {
  it('conserva la administración global y abre el generador por evento del sidebar', async () => {
    const wrapper = mountPage(SuperAdminDemoPage, superAdminData)

    expect(wrapper.text()).toContain('Empresas y acceso de usuarios')
    expect(document.body.textContent).not.toContain('Crear empresa demo')

    window.dispatchEvent(new CustomEvent('maintenance:open-demo-company'))
    await nextTick()

    expect(document.body.textContent).toContain('Empresa demo')
    expect(document.body.textContent).toContain('Crear empresa demo')
    expect(document.body.querySelector('form[action$="/superadmin/demo"]')).not.toBeNull()
  })
})

describe('SuperAdminPage', () => {
  it('conserva acciones POST, CSRF, estados y asignaciones', () => {
    const wrapper = mountPage(SuperAdminPage, superAdminData)

    expect(wrapper.get('h1').text()).toBe('Empresas y acceso de usuarios')
    expect(wrapper.text()).toContain('Transportes Sur')
    expect(wrapper.text()).toContain('Inactiva')

    const createForm = wrapper.get('form[action="/superadmin/empresas"]')
    expect(createForm.attributes('method')).toBe('post')
    expect(createForm.get('input[name="csrf_test_name"]').attributes('value')).toBe('secure-token')

    expect(wrapper.find('form[action="/superadmin/empresas/1"]').exists()).toBe(true)
    const administratorForm = wrapper.get('form[action="/superadmin/administradores"]')
    expect(administratorForm.attributes('method')).toBe('post')
    expect(administratorForm.get('input[name="csrf_test_name"]').attributes('value')).toBe('secure-token')
    expect(administratorForm.get('select[name="admin_empresa_id"]').element.value).toBe('1')
    expect(administratorForm.get('input[name="admin_nombre"]').attributes('value')).toBe('Nueva administradora')
    expect(administratorForm.get('input[name="admin_password"]').attributes('type')).toBe('password')
    expect(wrapper.find('form[action="/superadmin/usuarios/2/empresa"]').exists()).toBe(true)
    expect(wrapper.find('form[action="/superadmin/usuarios/2/roles"]').exists()).toBe(true)
    expect(wrapper.get('input[name="roles[]"][value="1"]').element.checked).toBe(true)
    const pagers = wrapper.findAll('nav[aria-label="Paginación"]')
    expect(pagers).toHaveLength(2)
    expect(pagers[0].get('select').element.value).toBe('10')
    expect(pagers[1].get('select').element.value).toBe('25')
  })

  it('oculta mutaciones cuando el servidor niega permisos', () => {
    const data = {
      ...superAdminData,
      permissions: { companiesEdit: false, createCompanyAdministrators: false, assignCompanies: false, assignRoles: false },
    }
    const wrapper = mountPage(SuperAdminPage, data)

    expect(wrapper.find('form').exists()).toBe(false)
    expect(wrapper.text()).toContain('No tenés permisos para modificar este acceso')
  })
})

describe('BranchesAdminPage', () => {
  it('mantiene alta y edición dentro de la empresa recibida', () => {
    const wrapper = mountPage(BranchesAdminPage, branchesAdminData)

    expect(wrapper.get('h1').text()).toBe('Sucursales')
    expect(wrapper.text()).toContain('Transportes Sur SA')
    expect(wrapper.get('form[action="/administracion/sucursales"] input[name="codigo"]').attributes('value')).toBe('ROS')
    expect(wrapper.find('form[action="/administracion/sucursales/4"]').exists()).toBe(true)
    expect(wrapper.get('form[action="/administracion/sucursales/4"] input[name="csrf_test_name"]').attributes('value')).toBe('secure-token')
    expect(wrapper.text()).toContain('Recibe alertas en alertas@transportes.test')
    expect(wrapper.get('nav[aria-label="Paginación"] select').element.value).toBe('10')
  })

  it('ofrece una lectura responsive sin formularios si no puede editar', () => {
    const wrapper = mountPage(BranchesAdminPage, {
      ...branchesAdminData,
      permissions: { edit: false },
    })

    expect(wrapper.find('form').exists()).toBe(false)
    expect(wrapper.text()).toContain('Calle 123')
    expect(wrapper.text()).toContain('Sin dirección')
  })
})

describe('UsersAdminPage', () => {
  it('preserva alta, cuenta, acceso y restablecimiento como formularios POST con CSRF', () => {
    const wrapper = mountPage(UsersAdminPage, usersAdminData)

    const expectedActions = [
      '/administracion/usuarios',
      '/administracion/usuarios/2',
      '/administracion/usuarios/2/password',
      '/administracion/usuarios/3',
      '/administracion/usuarios/3/acceso',
      '/administracion/usuarios/3/password',
    ]
    for (const action of expectedActions) {
      const form = wrapper.get(`form[action="${action}"]`)
      expect(form.attributes('method')).toBe('post')
      expect(form.get('input[name="csrf_test_name"]').attributes('value')).toBe('secure-token')
    }

    expect(wrapper.get('form[action="/administracion/usuarios"] input[name="roles[]"][value="2"]').element.checked).toBe(true)
    expect(wrapper.get('form[action="/administracion/usuarios/3/acceso"] input[name="sucursales[]"][value="4"]').element.checked).toBe(true)
    expect(wrapper.get('nav[aria-label="Paginación"] select').element.value).toBe('5')
  })

  it('protege visualmente el acceso propio y no ofrece auto-desactivación', () => {
    const wrapper = mountPage(UsersAdminPage, usersAdminData)
    const selfAccount = wrapper.get('form[action="/administracion/usuarios/2"]')

    expect(wrapper.text()).toContain('Tu propio acceso está protegido')
    expect(wrapper.find('form[action="/administracion/usuarios/2/acceso"]').exists()).toBe(false)
    expect(selfAccount.find('option[value="0"]').exists()).toBe(false)
    expect(wrapper.get('form[action="/administracion/usuarios/3"] option[value="0"]').exists()).toBe(true)
  })

  it('muestra el estado vacío de usuarios de forma accesible', () => {
    const wrapper = mountPage(UsersAdminPage, {
      ...usersAdminData,
      users: [],
      metrics: { total: 0, active: 0, inactive: 0 },
    })

    expect(wrapper.text()).toContain('No hay usuarios para mostrar')
  })
})
