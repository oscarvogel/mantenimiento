import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import TripOperationsDetailPage from '../../src/pages/operations/TripOperationsDetailPage.vue'

const data = {
  backUrl: '/mantenimiento/viajes/operativos',
  links: { detail: '/mantenimiento/viajes/operativos/42' },
  csrf: { name: 'csrf_test_name', hash: 'abc123' },
  canOperate: true,
  canStart: true,
  canCancel: true,
  trip: {
    id: 42, numero: 7, estado: 'PLANIFICADO', estado_label: 'Planificado', sucursal: 'Argentina',
    fecha_salida_planificada: '2026-10-20 08:00:00', fecha_regreso_planificada: '2026-10-21 18:00:00',
    fecha_salida_real: null, km_salida: null, observaciones: 'Carga programada', motivo_cancelacion: null,
  },
  assignments: [
    { rol: 'TRACTOR', rol_label: 'Tractor / camión', label: 'AA111AA · T01', desde: '2026-10-20 08:00:00', hasta: '2026-10-21 18:00:00' },
    { rol: 'CHOFER', rol_label: 'Chofer', label: 'Pérez, Juan', desde: '2026-10-20 08:00:00', hasta: '2026-10-21 18:00:00' },
  ],
  stops: [
    { orden: 1, tipo: 'CARGA', tipo_label: 'Carga', ubicacion: 'Rosario', cliente: 'Cliente Sur', domicilio: 'Planta · Ruta 9', fecha_estimada: '2026-10-20 08:30:00', descripcion_carga: 'Soja', cantidad: 30, unidad: 'Tonelada tn', observaciones: null },
    { orden: 2, tipo: 'DESCARGA', tipo_label: 'Descarga', ubicacion: 'Córdoba', cliente: null, domicilio: null, fecha_estimada: null, descripcion_carga: null, cantidad: null, unidad: null, observaciones: null },
  ],
}

describe('detalle de viaje operativo', () => {
  it('muestra planificación, equipo, choferes y recorrido', () => {
    const wrapper = mount(TripOperationsDetailPage, { props: { data } })

    expect(wrapper.text()).toContain('Viaje #7')
    expect(wrapper.text()).toContain('Planificado')
    expect(wrapper.text()).toContain('Tractor / camión')
    expect(wrapper.text()).toContain('AA111AA · T01')
    expect(wrapper.text()).toContain('Pérez, Juan')
    expect(wrapper.text()).toContain('Rosario')
    expect(wrapper.text()).toContain('Córdoba')
    expect(wrapper.text()).toContain('Soja')
    wrapper.unmount()
  })

  it('ofrece iniciar y cancelar mientras el viaje está planificado', () => {
    const wrapper = mount(TripOperationsDetailPage, { props: { data } })

    const forms = wrapper.findAll('form')
    expect(forms.map((form) => form.attributes('action'))).toEqual([
      '/mantenimiento/viajes/operativos/42/iniciar',
      '/mantenimiento/viajes/operativos/42/cancelar',
    ])
    wrapper.unmount()
  })

  it('oculta las acciones cuando el viaje ya avanzó', () => {
    const wrapper = mount(TripOperationsDetailPage, {
      props: { data: { ...data, canStart: false, canCancel: false, trip: { ...data.trip, estado: 'EN_CURSO', estado_label: 'En curso' } } },
    })

    expect(wrapper.findAll('form')).toHaveLength(0)
    expect(wrapper.text()).toContain('En curso')
    wrapper.unmount()
  })
})
