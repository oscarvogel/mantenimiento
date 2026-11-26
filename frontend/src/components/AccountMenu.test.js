import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import AccountMenu from './AccountMenu.vue'

const logout = { method: 'post', href: '/cerrar-sesion', csrfName: 'csrf_test', csrfValue: 'abc' }

describe('AccountMenu', () => {
  it('abre con el botón Mi cuenta y contiene tema y cerrar sesión', async () => {
    const wrapper = mount(AccountMenu, {
      attachTo: document.body,
      props: { user: { name: 'Juan Díaz', initials: 'JD' }, logout },
      global: { stubs: { ThemeToggle: { template: '<button data-test="theme-toggle" />' } } },
    })
    const button = wrapper.get('button[aria-haspopup="true"]')
    expect(button.text()).toContain('Mi cuenta')
    expect(button.attributes('aria-expanded')).toBe('false')

    await button.trigger('click')
    expect(button.attributes('aria-expanded')).toBe('true')
    expect(wrapper.find('[data-test="theme-toggle"]').exists()).toBe(true)
    const form = wrapper.get('form')
    expect(form.attributes('method')).toBe('post')
    expect(form.attributes('action')).toBe('/cerrar-sesion')
    expect(form.get('input[name="csrf_test"]').element.value).toBe('abc')
    expect(form.get('button[type="submit"]').text()).toContain('Cerrar sesión')

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
    await nextTick()
    expect(button.attributes('aria-expanded')).toBe('false')
    expect(document.activeElement).toBe(button.element)
    wrapper.unmount()
  })
})
