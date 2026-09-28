import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import ReadingControlPage from './ReadingControlPage.vue'

const data = (overrides = {}) => ({
  readOnly: true,
  total: 3,
  summary: { total: 3, sinLectura: 1, antiguos: 1, alDia: 1 },
  filters: { q: '', branchId: '', typeId: '', filter: 'all', perPage: 25, page: 1, sort: 'age_asc' },
  catalogs: {
    branches: [{ id: 7, name: 'Central' }],
    types: [{ id: 3, name: 'Camión' }],
    statusOptions: [
      { key: 'all', label: 'Todos' },
      { key: 'today', label: 'Cargaron hoy' },
      { key: 'not_today', label: 'Sin cargar hoy' },
      { key: 'gt_3', label: 'Más de 3 días' },
      { key: 'gt_7', label: 'Más de 7 días' },
    ],
    sortOptions: [
      { key: 'age_asc', label: 'Más antiguos primero' },
      { key: 'age_desc', label: 'Más recientes primero' },
      { key: 'code', label: 'Por código de equipo' },
    ],
  },
  routes: {
    index: '/mantenimiento/lecturas/control',
    equipment: '/mantenimiento/equipos',
    quickReadings: '/mantenimiento/lecturas/rapidas',
  },
  pagination: {
    page: 1, perPage: 25, total: 3, totalPages: 1,
    previousUrl: null, nextUrl: null,
    perPageOptions: [10, 25, 50, 100], perPageKey: 'per_page', pageKey: 'page',
  },
  results: [
    {
      equipmentId: 10, equipmentCode: 'CAM-01', equipmentPlate: 'AA123BB',
      typeName: 'Camión', branchId: 7, branchName: 'Central', controlsKm: true,
      driverEmployeeId: 55, driverName: 'Pérez, Juan', driverPhone: '3514449999',
      lastKm: 185000, lastReadingAt: '2026-09-28 08:30:00', daysSinceLastReading: 0,
      equipmentUrl: '/mantenimiento/equipos/10', hasDriver: true, hasValidPhone: true, hasReading: true,
    },
    {
      equipmentId: 11, equipmentCode: 'CAM-02', equipmentPlate: null,
      typeName: 'Camión', branchId: 7, branchName: 'Central', controlsKm: true,
      driverEmployeeId: null, driverName: '(sin chofer)', driverPhone: null,
      lastKm: 120000, lastReadingAt: '2026-09-01 10:00:00', daysSinceLastReading: 27,
      equipmentUrl: '/mantenimiento/equipos/11', hasDriver: false, hasValidPhone: false, hasReading: true,
    },
    {
      equipmentId: 12, equipmentCode: 'CAM-03', equipmentPlate: 'ZZ999XX',
      typeName: 'Camión', branchId: 7, branchName: 'Central', controlsKm: true,
      driverEmployeeId: 56, driverName: 'García, Ana', driverPhone: '3515558888',
      lastKm: null, lastReadingAt: null, daysSinceLastReading: null,
      equipmentUrl: '/mantenimiento/equipos/12', hasDriver: true, hasValidPhone: true, hasReading: false,
    },
  ],
  ...overrides,
})

const mountPage = (overrides) => mount(ReadingControlPage, { props: { data: data(overrides) } })

describe('ReadingControlPage', () => {
  it('se presenta explícitamente como pantalla de solo consulta', () => {
    const wrapper = mountPage()

    expect(wrapper.text()).toContain('Control de lecturas de kilometraje')
    expect(wrapper.text()).toContain('Solo consulta')
    expect(wrapper.text()).toContain('no envía mensajes ni genera reclamos')
  })

  it('no ofrece ninguna acción de reclamo, envío o notificación', () => {
    const wrapper = mountPage()
    const html = wrapper.html()

    expect(html).not.toContain('Reclamar')
    expect(html).not.toContain('WhatsApp')
    expect(html).not.toContain('Enviar')
    expect(html).not.toContain('Notificar')
  })

  it('muestra los equipos sin lectura como "Sin lectura"', () => {
    const wrapper = mountPage()
    const rows = wrapper.findAll('tbody tr')

    expect(rows).toHaveLength(3)
    expect(rows[2].text()).toContain('Sin lectura')
    expect(rows[2].text()).toContain('Nunca registrado')
    expect(rows[2].text()).toContain('—')
  })

  it('distingue la antigüedad de cada equipo', () => {
    const wrapper = mountPage()
    const rows = wrapper.findAll('tbody tr')

    expect(rows[0].text()).toContain('Hoy')
    expect(rows[0].text()).toContain('185.000')
    expect(rows[1].text()).toContain('hace 27 días')
  })

  it('expone los filtros de antigüedad y el orden por antigüedad', () => {
    const wrapper = mountPage()

    const filters = wrapper.find('#reading-control-filter').findAll('option').map((option) => option.text())
    expect(filters).toEqual(['Todos', 'Cargaron hoy', 'Sin cargar hoy', 'Más de 3 días', 'Más de 7 días'])

    const sorts = wrapper.find('#reading-control-sort').findAll('option').map((option) => option.text())
    expect(sorts).toContain('Más antiguos primero')
  })

  it('envía el formulario de consulta por GET a la propia pantalla', () => {
    const form = mountPage().find('form')

    expect(form.attributes('method')?.toUpperCase()).toBe('GET')
    expect(form.attributes('action')).toBe('/mantenimiento/lecturas/control')
  })

  it('muestra un estado vacío cuando no hay equipos para los filtros', () => {
    const wrapper = mountPage({ results: [], total: 0, summary: { total: 0, sinLectura: 0, antiguos: 0, alDia: 0 } })

    expect(wrapper.text()).toContain('No hay equipos para estos filtros')
  })
})
