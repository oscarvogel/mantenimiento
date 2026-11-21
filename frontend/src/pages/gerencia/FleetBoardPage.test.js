import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import FleetBoardPage from './FleetBoardPage.vue'

const urls = { index: '/gerencial/flota', portal: '/inicio', equipment: '/mantenimiento/equipos' }
const base = {
  filtros: { dias: 15, sucursalId: 9 },
  opciones: { vistas: ['uso', 'combustible', 'services'], dias: [7, 15, 30] },
  branches: [{ id: 9, name: 'Posadas' }],
}
const mountPage = (vista, resultado) => mount(FleetBoardPage, {
  props: { data: { tablero: { ...base, vista, resultado }, urls } },
})

describe('FleetBoardPage', () => {
  it('keeps the filters in the tab links and marks the active tab', () => {
    const wrapper = mountPage('uso', { totales: {}, unidades: [], excesos: [], cortes: [] })
    const tabs = wrapper.findAll('nav a')

    expect(tabs.map((tab) => tab.text())).toEqual(['Uso y excesos', 'Combustible', 'Services por GPS'])
    expect(tabs[1].attributes('href')).toBe('/gerencial/flota?vista=combustible&dias=15&sucursal_id=9')
    expect(tabs[0].attributes('aria-current')).toBe('page')
    expect(wrapper.text()).toContain('Posadas · últimos 15 días')
    expect(wrapper.text()).toContain('No hay unidades con telemetría vinculada')
  })

  it('shows usage with missing data as "Sin datos" and the provider cuts', () => {
    const wrapper = mountPage('uso', {
      umbrales: { velocidadExcesoKmh: 90, sinReportarHoras: 24 },
      totales: { unidades: 2, km: 5876, unidadesConKm: 1, horasMotor: null, excesos: 1, cortes: 1, cortesAbiertos: 0 },
      unidades: [
        { equipoId: 1, patente: 'AF081MJ', sucursal: 'Posadas', proveedor: 'wialon', estadoSenal: 'OK', ultimoDato: '2026-11-20 20:00', km: 5876, kmPorDia: 196, horasMotor: 75, porcentajeMotorDetenido: 30.5, porcentajeRalenti: 19.8, velocidadMaximaKmh: 97, excesos: 1, detalleUrl: '/mantenimiento/equipos/1?tab=telemetria' },
        { equipoId: 2, patente: 'RHB1C77', sucursal: 'Posadas', proveedor: 'autotrac', estadoSenal: 'CORTE_INTEGRACION', ultimoDato: null, km: null, kmPorDia: null, horasMotor: null, porcentajeMotorDetenido: null, porcentajeRalenti: null, velocidadMaximaKmh: null, excesos: 0 },
      ],
      excesos: [{ patente: 'AF081MJ', inicio: '2026-11-18 10:00', fin: '2026-11-18 10:15', velocidadMaximaKmh: 97 }],
      cortes: [{ integracion: 'Obersat', desde: '2026-11-08 10:30', hasta: '2026-11-19 08:30', horas: 262, unidadesAfectadas: 14, unidadesTotales: 14, sigueAbierto: false }],
    })

    expect(wrapper.text()).toContain('Cortes del proveedor de telemetría')
    expect(wrapper.text()).toContain('14 de 14 unidades')
    expect(wrapper.text()).toContain('0 siguen abiertos')
    expect(wrapper.text()).toContain('Corte del proveedor')
    expect(wrapper.text()).toContain('5.876')
    expect(wrapper.text()).toContain('19,8%')
    expect(wrapper.text()).toContain('Sin datos')
    expect(wrapper.text()).not.toMatch(/RHB1C77[^]*\b0%/)
    expect(wrapper.find('a[href="/mantenimiento/equipos/1?tab=telemetria"]').exists()).toBe(true)
  })

  it('does not repeat "Sin datos" in the difference hint and agrees the open cuts in number', () => {
    const fuel = mountPage('combustible', { tolerancia: 15, totales: { viajes: 2, conVeredicto: 0, diferenciaLitros: null, diferenciaPorcentaje: null }, viajes: [] })
    expect(fuel.text()).toContain('Tolerancia 15%')
    expect(fuel.text()).not.toContain('Sin datos · tolerancia')

    const usage = mountPage('uso', { totales: { cortes: 1, cortesAbiertos: 1 }, unidades: [], excesos: [], cortes: [] })
    expect(usage.text()).toContain('1 sigue abierto')
  })

  it('shows the fuel reconciliation verdict and the reason when there is none', () => {
    const wrapper = mountPage('combustible', {
      tolerancia: 15,
      totales: { viajes: 2, conVeredicto: 1, litrosDeclarados: 1200, litrosDetectados: 1134, diferenciaLitros: 66, diferenciaPorcentaje: 5.8 },
      viajes: [
        { viajeId: 1, numero: 21, patente: 'JLH877', desde: '2026-10-30 00:00', hasta: '2026-11-04 18:00', estado: 'SIN_DATOS_SUFICIENTES', estadoEtiqueta: 'Sin datos suficientes', motivo: 'El sensor del tanque solo reportó en el 47% de las lecturas del viaje.', declarado: { litros: 2812, cargas: 8 }, medido: { litros: null, cargas: 0, coberturaSensor: 47 }, diferencia: { litros: null, porcentaje: null } },
        { viajeId: 2, numero: 22, patente: 'AB499OK', desde: '2026-10-26 00:00', hasta: '2026-11-03 18:00', estado: 'CONCILIADO', estadoEtiqueta: 'Conciliado', motivo: null, declarado: { litros: 1200, cargas: 3, litrosPor100Km: 33 }, medido: { litros: 1134, cargas: 3, litrosPor100Km: 31.2, coberturaSensor: 98 }, diferencia: { litros: 66, porcentaje: 5.8 } },
      ],
    })

    expect(wrapper.text()).toContain('Declarado por el chofer contra el sensor del tanque')
    expect(wrapper.text()).toContain('el 47% de las lecturas')
    expect(wrapper.text()).toContain('Conciliado')
    expect(wrapper.text()).toContain('+5,8%')
    expect(wrapper.text()).toContain('Últimos 15 días')
  })

  it('shows real and estimated service state and hides the period filter', () => {
    const wrapper = mountPage('services', {
      totales: { planes: 2, conEstimacion: 1, seAdelantan: 1, porEstadoEstimado: { PROXIMO: 1, VENCIDO: 0 } },
      planes: [
        { planId: 1, equipoId: 1, patente: 'AB499OK', servicio: 'Service motor', sucursal: 'Posadas', proximoKm: 1020624, real: { estado: 'AL_DIA', kmRestantes: 7119 }, lectura: { km: 1013505, fecha: '2026-11-16 12:48' }, gps: { kmDesdeLectura: 4388, hasta: '2026-11-20 20:00' }, estimado: { km: 1017893, kmRestantes: 2731, estado: 'PROXIMO' }, motivo: null, seAdelanta: true },
        { planId: 3, equipoId: 3, patente: 'AF117ZK', servicio: 'Service motor', sucursal: 'Posadas', proximoKm: 490444, real: { estado: 'VENCIDO', kmRestantes: -5906 }, lectura: { km: 496350, fecha: '2026-11-20 00:00' }, gps: { kmDesdeLectura: null, hasta: null }, estimado: null, motivo: 'El equipo no tiene odómetro de GPS.', seAdelanta: false },
        { planId: 2, equipoId: 2, patente: 'GZD587', servicio: 'Service motor', sucursal: 'Posadas', proximoKm: 200000, real: { estado: 'SIN_DATOS', kmRestantes: null }, lectura: null, gps: { kmDesdeLectura: null, hasta: null }, estimado: null, motivo: 'No hay una lectura vigente del odómetro.', seAdelanta: false },
      ],
    })

    expect(wrapper.text()).toContain('El GPS adelanta el service')
    expect(wrapper.text()).toContain('+4.388 km')
    expect(wrapper.text()).toContain('Faltan 2.731 km')
    expect(wrapper.text()).toContain('Sin estimación: No hay una lectura vigente del odómetro.')
    expect(wrapper.text()).toContain('Pasado por 5.906 km')
    expect(wrapper.find('select[name="dias"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('Faltan -')
    expect(wrapper.find('a[href="/mantenimiento/equipos/1"]').exists()).toBe(true)
  })
})
