import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import LoginPage from '../../src/pages/login/LoginPage.vue'

const data = {
  action: 'http://example.test/login/authenticate',
  backgroundUrl: 'http://example.test/assets/login/maintenance-workshop.webp',
  csrf: { name: 'csrf_test_name', hash: 'secure-token' },
  email: 'usuario@example.test',
  errors: {},
  alert: null,
}

describe('LoginPage', () => {
  it('conserva el formulario nativo, CSRF y credenciales autocompletables', () => {
    const wrapper = mount(LoginPage, { props: { data } })

    expect(wrapper.get('form').attributes('method')).toBe('post')
    expect(wrapper.get('form').attributes('action')).toBe(data.action)
    expect(wrapper.get('input[type="hidden"]').attributes()).toMatchObject({
      name: 'csrf_test_name',
      value: 'secure-token',
    })
    expect(wrapper.get('input[name="email"]').attributes('autocomplete')).toBe('username')
    expect(wrapper.get('input[name="password"]').attributes('autocomplete')).toBe('current-password')
  })

  it('permite mostrar y ocultar la contraseña sin enviar el formulario', async () => {
    const wrapper = mount(LoginPage, { props: { data } })
    const password = wrapper.get('input[name="password"]')

    expect(password.attributes('type')).toBe('password')
    await wrapper.get('button[aria-label="Mostrar contraseña"]').trigger('click')
    expect(password.attributes('type')).toBe('text')
    expect(wrapper.get('button[aria-label="Ocultar contraseña"]').attributes('aria-pressed')).toBe('true')
  })

  it('presenta los errores del servidor como alertas accesibles', () => {
    const wrapper = mount(LoginPage, {
      props: {
        data: {
          ...data,
          errors: { email: 'Ingresá un email válido.' },
          alert: { type: 'error', message: 'Email o contraseña incorrectos.' },
        },
      },
    })

    expect(wrapper.get('[role="alert"]').text()).toContain('Email o contraseña incorrectos')
    expect(wrapper.get('#login-email-error').text()).toContain('email válido')
    expect(wrapper.get('input[name="email"]').attributes('aria-invalid')).toBe('true')
  })
})
