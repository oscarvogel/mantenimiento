import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import BrandLogo from './BrandLogo.vue'

describe('BrandLogo', () => {
  it('en la barra va sin fondo; en el login, sobre superficie clara, conserva el azul marino', () => {
    const sidebar = mount(BrandLogo, { props: { size: 'small' } }).get('[role="img"]')
    expect(sidebar.attributes('style')).toContain('vogel-consultoria-sin-fondo.png')
    expect(sidebar.classes()).not.toContain('bg-secondary-900')

    const login = mount(BrandLogo, { props: { size: 'login' } }).get('[role="img"]')
    expect(login.attributes('style')).toContain('vogel-consultoria.png')
    expect(login.classes()).toContain('bg-secondary-900')
  })
})
