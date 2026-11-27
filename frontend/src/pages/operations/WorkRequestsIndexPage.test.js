import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import WorkRequestsIndexPage from './WorkRequestsIndexPage.vue'

const data = (filters = { q: '', status: '' }) => ({
  items: [],
  total: 0,
  statusCounts: { PENDIENTE: 3, APROBADA: 1, RECHAZADA: 2 },
  filters,
  canReview: true,
  routes: { index: '/mantenimiento/solicitudes' },
  csrf: { name: 'csrf', hash: 'x' },
  pagination: { page: 1, totalPages: 1, total: 0, perPage: 25 },
})

describe('WorkRequestsIndexPage', () => {
  it('las píldoras muestran los conteos reales del servidor y filtran por estado', () => {
    const nav = mount(WorkRequestsIndexPage, { props: { data: data() } }).get('nav[aria-label="Solicitudes por estado"]')

    expect(nav.findAll('a').map((chip) => chip.text())).toEqual([
      'Todas 6', 'Pendientes 3', 'Postergadas 0', 'Aprobadas 1', 'Agrupadas 0', 'Rechazadas 2',
    ])
    expect(nav.get('a[href="/mantenimiento/solicitudes"]').attributes('aria-current')).toBe('true')
    expect(nav.find('a[href="/mantenimiento/solicitudes?estado=PENDIENTE"]').exists()).toBe(true)
  })

  it('marca el estado filtrado y conserva la búsqueda al cambiar de píldora', () => {
    const nav = mount(WorkRequestsIndexPage, { props: { data: data({ q: 'frenos', status: 'APROBADA' }) } }).get('nav[aria-label="Solicitudes por estado"]')

    expect(nav.get('a[href="/mantenimiento/solicitudes?q=frenos&estado=APROBADA"]').attributes('aria-current')).toBe('true')
    expect(nav.find('a[href="/mantenimiento/solicitudes?q=frenos"]').exists()).toBe(true)
  })
})
