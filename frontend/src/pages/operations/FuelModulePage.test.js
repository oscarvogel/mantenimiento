import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import FuelModulePage from './FuelModulePage.vue'

const urls = {
  index: '/viajes/combustible',
  portal: '/inicio',
  pending: '/viajes/operativos/combustible-sin-precio',
  trips: '/viajes/operativos',
  fleet: '/gerencial/flota?vista=combustible',
}

const panel = (overrides = {}) => ({
  periodo: { dias: 30, desde: '2026-10-25 15:00', hasta: '2026-11-24 15:00' },
  sin_alcance: false,
  totales: {
    viajes: 3,
    viajes_con_combustible: 2,
    viajes_sin_combustible: 1,
    cargas: 10,
    litros: 3312,
    importe_ars: 4010061,
    cargas_sin_precio: 5,
    litros_sin_precio: 1431,
  },
  viajes: [
    {
      numero: 21,
      estado: 'FINALIZADO',
      sucursal: 'Posadas',
      patente: 'ONN137',
      fecha_salida: '2026-10-30',
      fecha_regreso: '2026-11-17',
      litros: 2812,
      cargas: 8,
      importe_ars: 3110061,
      km: 7127,
      cargas_sin_precio: 5,
      litros_sin_precio: 1431,
      motivo: 'Falta el precio de 5 de las 8 cargas: 1.431 L sin valorar.',
    },
    {
      numero: 22,
      estado: 'EN_CURSO',
      sucursal: 'Corrientes',
      patente: null,
      fecha_salida: '2026-11-20',
      fecha_regreso: null,
      litros: 500,
      cargas: 2,
      importe_ars: 900000,
      km: null,
      cargas_sin_precio: 0,
      litros_sin_precio: 0,
      motivo: null,
    },
    {
      numero: 23,
      estado: 'EN_CURSO',
      sucursal: 'Posadas',
      patente: 'AB499OK',
      fecha_salida: '2026-11-21',
      fecha_regreso: null,
      litros: 0,
      cargas: 0,
      importe_ars: 0,
      km: null,
      cargas_sin_precio: 0,
      litros_sin_precio: 0,
      motivo: 'Todavía no se registraron cargas de combustible en este viaje.',
    },
  ],
  sin_salida_real: [
    { numero: 30, estado: 'EN_CURSO', motivo: 'El viaje no tiene fecha de salida real cargada.' },
  ],
  ...overrides,
})

const mountPage = (data) => mount(FuelModulePage, { props: { data: { panel: panel(), conciliacion: null, urls } } })

describe('FuelModulePage', () => {
  it('muestra los totales del combustible declarado', () => {
    const wrapper = mountPage()

    expect(wrapper.text()).toContain('Combustible')
    expect(wrapper.text()).toContain('2.812 L')
    expect(wrapper.text()).toContain('1.431 L')
    expect(wrapper.text()).toContain('Sin combustible')
  })

  it('dice por qué un viaje no tiene datos en lugar de mostrar un cero', () => {
    const wrapper = mountPage()

    expect(wrapper.text()).toContain('Todavía no se registraron cargas de combustible en este viaje.')
    expect(wrapper.text()).toContain('Falta el precio de 5 de las 8 cargas')
    expect(wrapper.text()).toContain('Sin datos')
  })

  it('separa los viajes sin salida real y enlaza la bandeja de precios', () => {
    const wrapper = mountPage()

    expect(wrapper.text()).toContain('Viajes sin salida real cargada')
    expect(wrapper.text()).toContain('El viaje no tiene fecha de salida real cargada.')
    expect(wrapper.find('a[href="/viajes/operativos/combustible-sin-precio"]').exists()).toBe(true)
    expect(wrapper.find('a[href="/inicio"]').exists()).toBe(true)
  })

  it('no inventa la conciliación cuando el usuario no tiene el dato gerencial', () => {
    const wrapper = mountPage()

    expect(wrapper.text()).not.toContain('Conciliación con el sensor del tanque')
    expect(wrapper.text()).toContain('tu cuenta no tiene ese acceso')
  })

  it('muestra la conciliación contra el sensor cuando el servidor la envía', () => {
    const wrapper = mount(FuelModulePage, {
      props: {
        data: {
          panel: panel(),
          conciliacion: {
            viajes: [
              {
                viajeId: 1,
                numero: 21,
                patente: 'ONN137',
                desde: '2026-10-30 00:00',
                hasta: '2026-11-17 18:00',
                estado: 'SIN_DATOS_SUFICIENTES',
                estadoEtiqueta: 'Sin datos suficientes',
                motivo: 'El sensor del tanque solo reportó en el 47% de las lecturas del viaje.',
                declarado: { litros: 2812, cargas: 8 },
                medido: { litros: null, cargas: 0, coberturaSensor: 47 },
                diferencia: { litros: null, porcentaje: null },
              },
            ],
          },
          urls,
        },
      },
    })

    expect(wrapper.text()).toContain('Conciliación con el sensor del tanque')
    expect(wrapper.text()).toContain('Declarado por el chofer contra el sensor del tanque')
    expect(wrapper.text()).toContain('el 47% de las lecturas')
  })
})
