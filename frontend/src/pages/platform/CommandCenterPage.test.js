import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const { gsapFrom, matchMediaAdd, matchMediaRevert } = vi.hoisted(() => ({
  gsapFrom: vi.fn(),
  matchMediaAdd: vi.fn(),
  matchMediaRevert: vi.fn(),
}))

vi.mock('gsap', () => ({
  gsap: {
    from: gsapFrom,
    matchMedia: () => ({ add: matchMediaAdd, revert: matchMediaRevert }),
  },
}))

import CommandCenterPage from './CommandCenterPage.vue'

describe('CommandCenterPage', () => {
  beforeEach(() => {
    gsapFrom.mockClear()
    matchMediaAdd.mockReset()
    matchMediaRevert.mockClear()
  })

  it('animates module cards with GSAP and cleans up the media context', async () => {
    matchMediaAdd.mockImplementation((_query, callback) => callback())
    const wrapper = mount(CommandCenterPage, {
      props: {
        data: {
          modules: [
            { key: 'maintenance', label: 'Mantenimiento', href: '/dashboard', status: 'Operativo', state: 'available' },
            { key: 'trips', label: 'Viajes', href: null, status: 'Sin implementación aún', state: 'not_implemented' },
          ],
        },
      },
    })
    await flushPromises()

    expect(matchMediaAdd).toHaveBeenCalledWith('(prefers-reduced-motion: no-preference)', expect.any(Function))
    expect(gsapFrom).toHaveBeenCalledWith(expect.arrayContaining(wrapper.findAll('[data-module-card]').map((card) => card.element)), expect.objectContaining({
      stagger: expect.any(Number),
      autoAlpha: 0,
    }))

    wrapper.unmount()
    expect(matchMediaRevert).toHaveBeenCalledOnce()
  })

  it('skips card motion when the reduced-motion media query is active', async () => {
    matchMediaAdd.mockImplementation(() => {})
    const wrapper = mount(CommandCenterPage, {
      props: {
        data: {
          modules: [{ key: 'maintenance', label: 'Mantenimiento', href: '/dashboard', status: 'Operativo', state: 'available' }],
        },
      },
    })
    await flushPromises()

    expect(matchMediaAdd).toHaveBeenCalledWith('(prefers-reduced-motion: no-preference)', expect.any(Function))
    expect(gsapFrom).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('shows every platform module while leaving unimplemented modules unavailable', () => {
    const wrapper = mount(CommandCenterPage, {
      props: {
        data: {
          userName: 'Ana Pérez',
          modules: [
            { key: 'maintenance', label: 'Mantenimiento', href: '/dashboard', status: 'Operativo', state: 'available' },
            { key: 'trips', label: 'Viajes', href: null, status: 'Sin implementación aún', state: 'not_implemented' },
            { key: 'fuel', label: 'Combustible', href: null, status: 'Sin implementación aún', state: 'not_implemented' },
            { key: 'tires', label: 'Neumáticos', href: null, status: 'Sin implementación aún', state: 'not_implemented' },
            { key: 'billing', label: 'Facturación', href: null, status: 'Sin implementación aún', state: 'not_implemented' },
            { key: 'management', label: 'Gerencial', href: null, status: 'Sin implementación aún', state: 'not_implemented' },
            { key: 'reports', label: 'Reportes', href: null, status: 'Sin implementación aún', state: 'not_implemented' },
            { key: 'automations', label: 'Automatizaciones', href: null, status: 'Sin implementación aún', state: 'not_implemented' },
            { key: 'ai', label: 'Inteligencia Artificial', href: null, status: 'Sin implementación aún', state: 'not_implemented' },
          ],
        },
      },
    })

    expect(wrapper.findAll('a[href="/dashboard"]')).toHaveLength(1)
    expect(wrapper.findAll('[data-module-card]')).toHaveLength(9)
    expect(wrapper.get('h1').text()).toContain('Ana')
    expect(wrapper.text()).toContain('Mantenimiento')
    expect(wrapper.text()).toContain('Operativo')
    expect(wrapper.text()).toContain('Viajes')
    expect(wrapper.text()).toContain('Combustible')
    expect(wrapper.text()).toContain('Neumáticos')
    expect(wrapper.text()).toContain('Facturación')
    expect(wrapper.text()).toContain('Gerencial')
    expect(wrapper.text()).toContain('Reportes')
    expect(wrapper.text()).toContain('Automatizaciones')
    expect(wrapper.text()).toContain('Inteligencia Artificial')
    expect(wrapper.findAll('button[disabled]')).toHaveLength(8)
    expect(wrapper.findAll('a[href^="/"]')).toHaveLength(1)
    expect(wrapper.findAll('.module-status--pending')).toHaveLength(8)
    expect(wrapper.findAll('button[data-module-card]').every((card) => card.attributes('style')?.includes('url('))).toBe(true)
  })

  it('does not invent metrics or activity when the module catalog is empty', () => {
    const wrapper = mount(CommandCenterPage, { props: { data: { modules: [] } } })

    expect(wrapper.text()).toContain('Sin módulos habilitados para tu cuenta')
    expect(wrapper.text()).not.toMatch(/\b\d+%|\$\s?\d|alertas recientes/i)
    expect(wrapper.findAll('a[href="/dashboard"]')).toHaveLength(0)
  })

  it('keeps global administration separate from tenant modules', () => {
    const wrapper = mount(CommandCenterPage, {
      props: { data: { modules: [], globalAdminUrl: '/superadmin' } },
    })

    expect(wrapper.find('a[href="/superadmin"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Administración global')
    expect(wrapper.find('a[href="/dashboard"]').exists()).toBe(false)
  })

  it('keeps Mantenimiento visible but non-interactive when the account lacks entry permission', () => {
    const wrapper = mount(CommandCenterPage, {
      props: {
        data: {
          modules: [{
            key: 'maintenance',
            label: 'Mantenimiento',
            description: 'Control de servicios.',
            href: null,
            status: 'No habilitado para tu cuenta',
            state: 'restricted',
            icon: 'wrench',
          }],
        },
      },
    })

    expect(wrapper.find('a[href="/dashboard"]').exists()).toBe(false)
    expect(wrapper.find('button[disabled][aria-label="Mantenimiento: No habilitado para tu cuenta"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('No habilitado para tu cuenta')
    expect(wrapper.text()).toContain('No habilitados para tu cuenta')
  })
})
