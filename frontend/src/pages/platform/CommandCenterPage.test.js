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

const managementData = {
  profile: 'management',
  userName: 'Ana Pérez',
  modules: [
    { key: 'trips', label: 'Viajes', href: null, status: 'Próximamente', state: 'not_implemented', icon: 'truck' },
    { key: 'maintenance', label: 'Mantenimiento', href: '/dashboard', status: 'Operativo', state: 'available', icon: 'wrench' },
  ],
  indicators: [
    { key: 'overdue', label: 'Mantenimientos vencidos', value: 2, unit: null, tone: 'danger', href: '/mantenimiento/planes?estado=VENCIDO' },
    { key: 'compliance', label: 'Preventivo al día', value: null, unit: '%', tone: 'neutral', href: '/mantenimiento/planes' },
  ],
  attention: [
    { title: 'TR-01 · Service', detail: 'Vencido hace 300 km', status: 'VENCIDO', statusLabel: 'Vencido', href: '/mantenimiento/planes?estado=VENCIDO' },
    { title: 'TR-02', detail: 'Hace 104 días que no registra km/horas · Casa Central', status: 'DESACTUALIZADA', statusLabel: 'Lectura antigua', href: '/mantenimiento/equipos/1' },
    { title: 'AC-07', detail: 'Nunca registró km/horas', status: 'SIN_LECTURA', statusLabel: 'Sin lectura', href: null },
  ],
  shortcuts: [],
}

describe('CommandCenterPage', () => {
  beforeEach(() => {
    gsapFrom.mockClear()
    matchMediaAdd.mockReset()
    matchMediaRevert.mockClear()
  })

  it('animates module cards with GSAP and cleans up the media context', async () => {
    matchMediaAdd.mockImplementation((_query, callback) => callback())
    const wrapper = mount(CommandCenterPage, { props: { data: managementData } })
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
    const wrapper = mount(CommandCenterPage, { props: { data: managementData } })
    await flushPromises()

    expect(gsapFrom).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('shows management indicators with drill-down links and never invents missing data', () => {
    const wrapper = mount(CommandCenterPage, { props: { data: managementData } })

    const indicators = wrapper.findAll('[data-indicator]')
    expect(indicators).toHaveLength(2)
    expect(indicators[0].attributes('href')).toBe('/mantenimiento/planes?estado=VENCIDO')
    expect(indicators[0].text()).toContain('2')
    expect(indicators[1].text()).toContain('Planes preventivos al día')
    expect(indicators[1].text()).toContain('Sin datos suficientes')
    expect(indicators[1].text()).not.toMatch(/\d+\s?%/)
    expect(wrapper.get('h1').text()).toBe('Hola, Ana')
  })

  it('puts attention before indicators and names the destinations it can open', () => {
    const wrapper = mount(CommandCenterPage, { props: { data: managementData } })

    const attention = wrapper.get('section[aria-labelledby="attention-heading"]')
    const indicators = wrapper.get('section[aria-labelledby="indicators-heading"]')
    expect(attention.element.nextElementSibling).toBe(indicators.element)

    const plans = attention.get('a[href="/mantenimiento/planes?estado=VENCIDO"]')
    expect(plans.text()).toContain('TR-01 · Service')
    expect(plans.text()).toContain('Ver planes vencidos')

    const equipment = attention.get('a[href="/mantenimiento/equipos/1"]')
    expect(equipment.text()).toContain('Hace 104 días que no registra km/horas · Casa Central')
    expect(equipment.text()).toContain('Ver ficha del equipo')

    const unavailable = attention.get('li:last-child')
    expect(unavailable.text()).toContain('Sin lectura')
    expect(unavailable.find('a').exists()).toBe(false)
  })

  it('lists available modules first and marks the rest without internal wording', () => {
    const wrapper = mount(CommandCenterPage, { props: { data: managementData } })

    const cards = wrapper.findAll('[data-module-card]')
    expect(cards[0].text()).toContain('Mantenimiento')
    expect(cards[0].attributes('href')).toBe('/dashboard')
    expect(cards[0].find('.module-status--pending').exists()).toBe(false)
    expect(cards[1].attributes('aria-disabled')).toBe('true')
    expect(cards[1].text()).toContain('Próximamente')
    expect(wrapper.text()).toContain('1 disponible de 2')
    expect(wrapper.text()).not.toContain('Sin implementación')
  })

  it('shows field shortcuts instead of indicators for field users', () => {
    const wrapper = mount(CommandCenterPage, {
      props: {
        data: {
          profile: 'field',
          modules: [],
          shortcuts: [{ key: 'my-work', label: 'Mi trabajo', description: 'Tus órdenes.', href: '/mantenimiento/ordenes?vista=mi-trabajo' }],
        },
      },
    })

    expect(wrapper.find('a[href="/mantenimiento/ordenes?vista=mi-trabajo"]').text()).toContain('Mi trabajo')
    expect(wrapper.findAll('[data-indicator]')).toHaveLength(0)
    expect(wrapper.text()).not.toContain('Requiere atención hoy')
  })

  it('does not invent metrics or activity when the module catalog is empty', () => {
    const wrapper = mount(CommandCenterPage, { props: { data: { modules: [] } } })

    expect(wrapper.text()).toContain('Sin módulos habilitados para tu cuenta')
    expect(wrapper.text()).not.toMatch(/\b\d+%|\$\s?\d|alertas recientes/i)
  })

  it('keeps global administration separate from tenant modules', () => {
    const wrapper = mount(CommandCenterPage, {
      props: { data: { modules: [], globalAdminUrl: '/superadmin' } },
    })

    expect(wrapper.find('a[href="/superadmin"]').text()).toContain('Administración global')
    expect(wrapper.find('a[href="/dashboard"]').exists()).toBe(false)
  })

  it('keeps Mantenimiento visible but non-interactive when the account lacks entry permission', () => {
    const wrapper = mount(CommandCenterPage, {
      props: {
        data: {
          modules: [{ key: 'maintenance', label: 'Mantenimiento', description: 'Control de servicios.', href: null, status: 'No habilitado para tu cuenta', state: 'restricted', icon: 'wrench' }],
        },
      },
    })

    expect(wrapper.find('a[href="/dashboard"]').exists()).toBe(false)
    expect(wrapper.find('[aria-disabled="true"][aria-label="Mantenimiento: No habilitado para tu cuenta"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Consultá a tu administrador')
  })
})
