import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import SuperAdminPage from '../src/pages/admin/SuperAdminPage.vue'
import { superAdminData } from './admin/fixtures.js'

describe('SuperAdmin company presentation profiles', () => {
  it('shows an allowlisted selector with the current profile, a POST action, and CSRF protection', async () => {
    const wrapper = mount(SuperAdminPage, {
      props: { data: {
        ...superAdminData,
        presentationProfiles: [{ key: 'base', label: 'Base' }, { key: 'vogel', label: 'Vogel' }],
        companies: superAdminData.companies.map((company) => ({
          ...company,
          presentationProfile: 'vogel',
          actions: { ...company.actions, assignPresentationProfile: `/superadmin/empresas/${company.id}/presentacion` },
        })),
      } },
    })

    await wrapper.findAll('nav button')[1].trigger('click')
    const form = wrapper.find('form[data-testid="presentation-profile-form"]')
    expect(form.attributes('method')).toBe('post')
    expect(form.attributes('action')).toBe('/superadmin/empresas/1/presentacion')
    expect(form.find('select[name="frontend_profile"]').element.value).toBe('vogel')
    expect(form.findAll('option').map((option) => option.attributes('value'))).toEqual(['base', 'vogel'])
    expect(form.find('input[name="csrf_test_name"]').attributes('value')).toBe('secure-token')
  })
})
