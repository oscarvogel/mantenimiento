import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import FleetConsumptionPage from './FleetConsumptionPage.vue'

const reporte = (overrides = {}) => ({
  sinCalibrar: false,
  muestrasLeidas: 8,
  cobertura: {
    porcentaje: 92,
    suficiente: true,
    motivo: null,
    minutosCubiertos: 8280,
    minutosEsperados: 9000,
  },
  umbrales: { deltaMinimoLitros: 2, umbralCargaLitros: 20 },
  totales: { kilometraje: 1400, litros: 500, viajes: 2, conPorHora: 1, sinConsumo: 0 },
  distribucion: { mediana: 25, maximo: 30, intervalosConLectura: 7 },
  viajes: [
    {
      inicio: '2026-10-10 06:00:00',
      fin: '2026-10-10 09:15:00',
      duracionMinutos: 195,
      minutosUtilizables: 195,
      coberturaPeriodo: 100,
      kilometraje: 800,
      litros: 296,
      litrosCargados: 0,
      litrosPor100Km: 37,
      litrosPorHoraMotor: 4.9,
      motivoSinPor100Km: null,
      motivoSinPorHora: null,
      huboCarga: false,
      reconstruible: true,
      motivoNoReconstruible: null,
      fueraDeBanda: false,
    },
    {
      inicio: '2026-10-10 10:00:00',
      fin: '2026-10-10 12:00:00',
      duracionMinutos: 120,
      minutosUtilizables: 120,
      coberturaPeriodo: 100,
      kilometraje: 600,
      litros: 204,
      litrosCargados: 0,
      litrosPor100Km: 34,
      litrosPorHoraMotor: null,
      motivoSinPor100Km: null,
      motivoSinPorHora: 'Las horas de motor no son creíbles: implican 900 km/h.',
      huboCarga: false,
      reconstruible: true,
      motivoNoReconstruible: null,
      fueraDeBanda: false,
    },
  ],
  ...overrides,
})

const datos = (overrides = {}) => ({
  empresaId: 4,
  equipo: { id: 42, plate: 'AB499OK', branchName: 'Casa central' },
  equipos: [
    { id: 42, plate: 'AB499OK', branchName: 'Casa central' },
    { id: 43, plate: 'GZD587', branchName: 'Corrientes' },
  ],
  branches: [{ id: 1, name: 'Casa central' }],
  window: { dias: 7, desde: '2026-10-03 12:00', hasta: '2026-10-10 12:00' },
  bandaFlota: { minima: 33, maxima: 41 },
  reporte: reporte(),
  ...overrides,
})

describe('FleetConsumptionPage', () => {
  it('avisa que los numeros son provisorios mientras los umbrales no esten calibrados', () => {
    const wrapper = mount(FleetConsumptionPage, {
      props: { data: datos({ reporte: reporte({ sinCalibrar: true }) }) },
    })

    const aviso = wrapper.find('[data-test="aviso-calibracion"]')

    expect(aviso.exists()).toBe(true)
    expect(aviso.text()).toContain('Estos números son provisorios')
    expect(aviso.text()).toContain('valores de fábrica')
  })

  it('no muestra el aviso cuando la empresa configuro sus umbrales', () => {
    const wrapper = mount(FleetConsumptionPage, { props: { data: datos() } })

    expect(wrapper.find('[data-test="aviso-calibracion"]').exists()).toBe(false)
  })

  it('una celda sin medicion muestra el motivo y no un cero', () => {
    const sinConsumo = reporte({
      viajes: [{
        ...reporte().viajes[0],
        litros: 0,
        litrosPor100Km: null,
        motivoSinPor100Km: 'Ninguna captura del viaje trae sensor de combustible.',
      }],
    })
    const wrapper = mount(FleetConsumptionPage, { props: { data: datos({ reporte: sinConsumo }) } })

    const fila = wrapper.find('[data-test="fila-viaje"]')
    const celda = fila.find('[data-test="celda-100km"]')

    expect(celda.text()).toContain('Sin dato')
    expect(celda.text()).toContain('Ninguna captura del viaje trae sensor de combustible.')
    // La celda no puede mostrar un 0: parecería un camión que no gastó nada.
    expect(celda.text()).not.toContain('0,0')
  })

  it('un indicador por hora que no se puede calcular dice por que', () => {
    const wrapper = mount(FleetConsumptionPage, { props: { data: datos() } })
    const filas = wrapper.findAll('[data-test="fila-viaje"]')

    expect(filas).toHaveLength(2)
    expect(filas[1].text()).toContain('No calculable')
    expect(filas[1].find('[title]').attributes('title')).toContain('900 km/h')
  })

  it('la fila de total no inventa una hora', () => {
    const wrapper = mount(FleetConsumptionPage, { props: { data: datos() } })
    const total = wrapper.find('tfoot')

    expect(total.exists()).toBe(true)
    expect(total.text()).toContain('Total de 2 viajes')
    expect(total.text()).toContain('1.400')
    expect(total.text()).not.toContain('06:00')
    expect(total.text()).not.toContain('12:00')
  })

  it('un viaje fuera de la banda de la flota queda marcado', () => {
    const fuera = reporte({
      viajes: [{ ...reporte().viajes[0], litrosPor100Km: 58, fueraDeBanda: true }],
    })
    const wrapper = mount(FleetConsumptionPage, { props: { data: datos({ reporte: fuera }) } })

    expect(wrapper.find('[data-test="fila-viaje"]').text()).toContain('58,0')
  })

  it('sin datos muestra "Sin datos suficientes" y no una tabla vacia', () => {
    const wrapper = mount(FleetConsumptionPage, { props: { data: datos({ reporte: null }) } })

    expect(wrapper.text()).toContain('Sin datos suficientes')
    expect(wrapper.find('[data-test="fila-viaje"]').exists()).toBe(false)
  })

  it('avisa cuando la cobertura del periodo es insuficiente y dice cuanto se sabe', () => {
    const baja = reporte({
      cobertura: {
        porcentaje: 12,
        suficiente: false,
        motivo: 'Cobertura del 12% por debajo del 70% necesario.',
        minutosCubiertos: 1080,
        minutosEsperados: 9000,
      },
    })
    const wrapper = mount(FleetConsumptionPage, { props: { data: datos({ reporte: baja }) } })

    const aviso = wrapper.find('[data-test="aviso-cobertura"]')

    expect(aviso.exists()).toBe(true)
    expect(aviso.text()).toContain('Cobertura insuficiente')
    expect(aviso.text()).toContain('12%')
  })

  it('un viaje no reconstruible lo dice en la fila', () => {
    const corto = reporte({
      viajes: [{
        ...reporte().viajes[0],
        reconstruible: false,
        motivoNoReconstruible: 'El viaje no se puede reconstruir: quedó en una sola captura.',
      }],
    })
    const wrapper = mount(FleetConsumptionPage, { props: { data: datos({ reporte: corto }) } })

    expect(wrapper.find('[data-test="fila-viaje"]').text()).toContain('No reconstruible')
  })

  it('aclara que el odometro es de telemetria y no sirve para facturar', () => {
    const wrapper = mount(FleetConsumptionPage, { props: { data: datos() } })

    expect(wrapper.text()).toContain('no el odómetro físico')
    expect(wrapper.text()).toContain('diagnóstico')
  })
})