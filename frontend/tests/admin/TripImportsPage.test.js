import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import TripImportsPage from '../../src/pages/admin/TripImportsPage.vue'

const base = {
  companies: [{ id: 1, name: 'TSA', cuit: '23-28059786-4', branches: [{ id: 10, code: 'AR', name: 'Argentina' }] }],
  csrf: { name: 'csrf_token', hash: 'token' },
  maxSizeMb: 10,
  actions: {
    index: '/superadmin/viajes/importar',
    preview: '/superadmin/viajes/importar',
    show: '/superadmin/viajes/importaciones/1',
    branches: '/superadmin/viajes/importaciones/1/sucursales',
    confirm: '/superadmin/viajes/importaciones/1/confirmar',
    cancel: '/superadmin/viajes/importaciones/1/cancelar',
    superadmin: '/superadmin',
    portal: '/inicio',
  },
}

const summary = {
  source: { file: 'transporte.zip', source_company: 'PAWLUK DUBLIN MARIELA ELISA' },
  counts: { trips_total: 1740, trips_new: 1740, trips_existing: 0, trips_error: 0, warnings: 14, blocking_errors: 0 },
  classification: {
    AR: { trips: 1237, new: 1237, domains: ['AC 532 DD'] },
    BR: { trips: 501, new: 501, domains: ['IUU5G88'] },
    UNCLASSIFIED: { trips: 2, new: 2, domains: ['AA PRUEBA'] },
  },
  branch_requirements: { AR: true, BR: true, UNCLASSIFIED: true },
  branch_map: { AR: 10, BR: 20, UNCLASSIFIED: 30 },
  branch_names: { AR: 'Argentina', BR: 'Brasil', UNCLASSIFIED: 'Sin clasificar' },
  children: [{ table: 't06_origenes_destinos_por_viaje', destination: 'viaje_tramos', declared: 4000, valid: 3998, orphan: 2, error: 0 }],
  issues_total: 1,
  page: 1,
  pages: 1,
  issues_page: [{ severity: 'WARNING', table: 't05_viajes', key: 'x', field: 'fecha_regreso', message: '<script>alert(1)</script> anterior a la salida' }],
  can_confirm: true,
}

const batch = {
  id: 1,
  estado: 'BORRADOR_VALIDADO',
  archivo_original: 'transporte.zip',
  fecha: '2026-10-18 10:00:00',
  company: { id: 1, name: 'TSA', cuit: '23-28059786-4' },
  branches: [
    { id: 10, code: 'AR', name: 'Argentina' },
    { id: 20, code: 'BR', name: 'Brasil' },
    { id: 30, code: 'SC', name: 'Sin clasificar' },
  ],
  summary,
}

describe('importación de viajes', () => {
  it('exige empresa y archivo y conserva CSRF y multipart', () => {
    const wrapper = mount(TripImportsPage, { props: { data: { ...base, mode: 'form', batch: null } } })

    expect(wrapper.get('select[name="empresa_id"]').attributes('required')).toBeDefined()
    expect(wrapper.get('input[name="archivo"]').attributes('accept')).toContain('.zip')
    expect(wrapper.get('form').attributes('enctype')).toBe('multipart/form-data')
    expect(wrapper.get('input[name="csrf_token"]').element.value).toBe('token')
    expect(wrapper.get('button[type="submit"]').element.disabled).toBe(true)
    wrapper.unmount()
  })

  it('muestra la clasificación, permite asignar sucursales y escapa observaciones', () => {
    const wrapper = mount(TripImportsPage, { props: { data: { ...base, mode: 'preview', batch } } })

    expect(wrapper.text()).toContain('Argentina')
    expect(wrapper.text()).toContain('1.237')
    expect(wrapper.get('select[name="sucursal_ar"]').element.value).toBe('10')
    expect(wrapper.get('select[name="sucursal_br"]').element.value).toBe('20')
    expect(wrapper.get('form[action="/superadmin/viajes/importaciones/1/sucursales"]').exists()).toBe(true)
    expect(wrapper.get('form[action="/superadmin/viajes/importaciones/1/confirmar"] button').element.disabled).toBe(false)
    expect(wrapper.find('script').exists()).toBe(false)
    expect(wrapper.text()).toContain('<script>alert(1)</script>')
    wrapper.unmount()
  })

  it('explica la decisión y agrupa las advertencias por tipo', () => {
    const wrapper = mount(TripImportsPage, { props: { data: { ...base, mode: 'preview', batch } } })

    expect(wrapper.text()).toContain('Listo para confirmar')
    expect(wrapper.text()).toContain('No se vinculan equipos ni choferes')
    expect(wrapper.text()).toContain('No se generan lecturas')
    expect(wrapper.text()).toContain('Fechas de regreso incoherentes')
    wrapper.unmount()
  })

  it('bloquea la confirmación mientras haya errores bloqueantes', () => {
    const blocked = { ...batch, summary: { ...summary, can_confirm: false, counts: { ...summary.counts, blocking_errors: 1 } } }
    const wrapper = mount(TripImportsPage, { props: { data: { ...base, mode: 'preview', batch: blocked } } })

    expect(wrapper.get('form[action="/superadmin/viajes/importaciones/1/confirmar"] button').element.disabled).toBe(true)
    expect(wrapper.text()).toContain('errores bloqueantes')
    wrapper.unmount()
  })

  it('informa el resultado y retira las acciones al confirmar', () => {
    const confirmed = {
      ...batch,
      estado: 'CONFIRMADO',
      summary: { ...summary, can_confirm: false, result: { inserted: 1740, skipped: 0, children_inserted: 36558, orphans: 6 } },
    }
    const wrapper = mount(TripImportsPage, { props: { data: { ...base, mode: 'preview', batch: confirmed } } })

    expect(wrapper.text()).toContain('1.740')
    expect(wrapper.find('form[action="/superadmin/viajes/importaciones/1/confirmar"]').exists()).toBe(false)
    wrapper.unmount()
  })
})
