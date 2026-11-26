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
    { key: 'due-soon', label: 'Próximos a vencer', value: 5, unit: null, tone: 'warning', href: '/mantenimiento/planes?estado=PROXIMO' },
    { key: 'open-orders', label: 'Órdenes abiertas', value: 12, unit: null, tone: 'neutral', href: '/mantenimiento/ordenes' },
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

  it('vencidos y próximos son tarjetas grandes y el resto va en la fila compacta', () => {
    const wrapper = mount(CommandCenterPage, { props: { data: managementData } })

    const heroes = wrapper.findAll('[data-test="hero-indicator"]')
    expect(heroes).toHaveLength(2)
    expect(heroes[0].attributes('href')).toBe('/mantenimiento/planes?estado=VENCIDO')
    expect(heroes[0].classes()).toContain('bg-surface-inverse')
    expect(heroes[0].text()).toContain('Mantenimientos vencidos')
    expect(heroes[0].text()).toContain('2')
    expect(heroes[1].text()).toContain('Próximos a vencer')
    expect(heroes[1].classes()).not.toContain('bg-surface-inverse')

    const minis = wrapper.findAll('[data-test="mini-indicator"]')
    expect(minis.map((mini) => mini.attributes('href'))).toEqual(['/mantenimiento/ordenes', '/mantenimiento/planes'])
    expect(minis[0].text()).toContain('12')
  })

  it('preventivo sin datos: nunca inventa un porcentaje', () => {
    const wrapper = mount(CommandCenterPage, { props: { data: managementData } })

    const compliance = wrapper.findAll('[data-test="mini-indicator"]')[1]
    expect(compliance.text()).toContain('Planes preventivos al día')
    expect(compliance.text()).toContain('Sin datos')
    expect(compliance.text()).not.toMatch(/\d+\s?%/)
  })

  it('el saludo vive en el encabezado: la página solo tiene un título accesible', () => {
    const wrapper = mount(CommandCenterPage, { props: { data: managementData } })

    expect(wrapper.get('h1').text()).toBe('Inicio')
    expect(wrapper.get('h1').classes()).toContain('sr-only')
    expect(wrapper.text()).not.toContain('Hola, Ana')
  })

  it('atención como filas con estado en píldora, después de los indicadores', () => {
    const wrapper = mount(CommandCenterPage, { props: { data: managementData } })

    const html = wrapper.html()
    expect(html.indexOf('data-test="hero-indicator"')).toBeLessThan(html.indexOf('attention-heading'))

    const rows = wrapper.findAll('[data-test="attention-row"]')
    expect(rows).toHaveLength(3)
    expect(rows[0].element.tagName).toBe('A')
    expect(rows[0].attributes('href')).toBe('/mantenimiento/planes?estado=VENCIDO')
    expect(rows[0].text()).toContain('TR-01 · Service')
    expect(rows[0].get('[data-test="status-pill"]').text()).toBe('Vencido')
    expect(rows[0].text()).toContain('Ver planes vencidos')
    expect(rows[1].text()).toContain('Hace 104 días que no registra km/horas · Casa Central')
    expect(rows[1].text()).toContain('Ver ficha del equipo')
    expect(rows[2].element.tagName).not.toBe('A')
    expect(rows[2].get('[data-test="status-pill"]').text()).toBe('Sin lectura')
  })

  it('gerencia no tiene píldoras de acceso', () => {
    const wrapper = mount(CommandCenterPage, { props: { data: managementData } })

    expect(wrapper.findAll('[data-test="shortcut-pill"]')).toHaveLength(0)
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

  it('campo: accesos como píldoras, la primera destacada', () => {
    const wrapper = mount(CommandCenterPage, {
      props: {
        data: {
          profile: 'field',
          modules: [],
          shortcuts: [
            { key: 'my-work', label: 'Mi trabajo', description: 'Tus órdenes.', href: '/mantenimiento/ordenes?vista=mi-trabajo' },
            { key: 'readings', label: 'Registrar km/horas', description: 'Cargá km.', href: '/mantenimiento/lecturas/rapidas' },
          ],
        },
      },
    })

    const pills = wrapper.findAll('[data-test="shortcut-pill"]')
    expect(pills).toHaveLength(2)
    expect(pills[0].attributes('href')).toBe('/mantenimiento/ordenes?vista=mi-trabajo')
    expect(pills[0].text()).toContain('Mi trabajo')
    expect(pills[0].classes()).toContain('bg-primary-300')
    expect(pills[1].classes()).not.toContain('bg-primary-300')
    expect(wrapper.findAll('[data-test="hero-indicator"]')).toHaveLength(0)
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
