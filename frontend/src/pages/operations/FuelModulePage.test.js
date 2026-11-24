import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import FuelModulePage from './FuelModulePage.vue'

if (typeof HTMLDialogElement !== 'undefined' && !HTMLDialogElement.prototype.showModal) {
  HTMLDialogElement.prototype.showModal = vi.fn(function () { this.open = true })
  HTMLDialogElement.prototype.close = vi.fn(function () { this.open = false })
}

const urls = {
  index: '/viajes/combustible',
  portal: '/inicio',
  pending: '/viajes/operativos/combustible-sin-precio',
  trips: '/viajes/operativos',
  fleet: '/gerencial/flota?vista=combustible',
  precios: '/viajes/combustible/precios',
}

const panel = (overrides = {}) => ({
  periodo: { desde: '2026-10-25', hasta: '2026-11-24' },
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
    consumo: { litros_por_100km: 39.5, viajes_medidos: 1, litros: 2812, km: 7127 },
  },
  viajes: [
    {
      id: 1,
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
      consumo_l100: 39.5,
      cargas_sin_precio: 5,
      litros_sin_precio: 1431,
      motivo: 'Falta el precio de 5 de las 8 cargas: 1.431 L sin valorar.',
      cargas_pendientes: [{ id: 90, litros: 193, fecha: '2026-11-13 10:00:00', estacion: 'YPF', comprobante: null }],
      puede_cargar: false,
      puede_completar_precio: true,
      url_viaje: '/viajes/operativos/1',
      url_cargar: '/viajes/combustible/1/carga',
    },
    {
      id: 2,
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
      consumo_l100: null,
      cargas_sin_precio: 0,
      litros_sin_precio: 0,
      motivo: null,
      cargas_pendientes: [],
      puede_cargar: true,
      puede_completar_precio: false,
      url_viaje: '/viajes/operativos/2',
      url_cargar: '/viajes/combustible/2/carga',
    },
    {
      id: 3,
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
      consumo_l100: null,
      cargas_sin_precio: 0,
      litros_sin_precio: 0,
      motivo: 'Todavía no se registraron cargas de combustible en este viaje.',
      cargas_pendientes: [],
      puede_cargar: true,
      puede_completar_precio: false,
      url_viaje: '/viajes/operativos/3',
      url_cargar: '/viajes/combustible/3/carga',
    },
  ],
  sin_salida_real: [
    { numero: 30, estado: 'EN_CURSO', motivo: 'El viaje no tiene fecha de salida real cargada.' },
  ],
  ...overrides,
})

const data = (overrides = {}) => ({
  panel: panel(),
  conciliacion: null,
  filtros: { desde: '2026-10-25', hasta: '2026-11-24', camion: null, sucursal: null },
  opciones: {
    camiones: [{ id: 7, label: 'ONN137 · T07', patente: 'ONN137' }],
    monedas: [{ code: 'ARS', label: 'Peso argentino', rate: 1 }],
  },
  puedeEditar: true,
  puedeConciliar: false,
  urls,
  csrf: { name: 'csrf_test_name', hash: 'abc123' },
  ...overrides,
})

const mountPage = (overrides = {}) => mount(FuelModulePage, { props: { data: data(overrides) } })

describe('FuelModulePage', () => {
  it('muestra los totales del combustible cargado', () => {
    const wrapper = mountPage()

    expect(wrapper.text()).toContain('Combustible')
    expect(wrapper.text()).toContain('3.312 L')
    expect(wrapper.text()).toContain('1.431 L')
    expect(wrapper.text()).toContain('Viajes sin combustible')
  })

  it('muestra el consumo promedio con la base de viajes medidos', () => {
    const wrapper = mountPage()

    const tarjeta = wrapper.find('[data-testid="fuel-kpi-consumo"]')
    expect(tarjeta.text()).toContain('39,5 L/100 km')
    expect(tarjeta.text()).toContain('1 viajes medidos')
    expect(tarjeta.text()).toContain('7.127 km')
  })

  it('dice por qué un viaje no tiene datos en lugar de mostrar un cero', () => {
    const wrapper = mountPage()

    expect(wrapper.text()).toContain('Todavía no se registraron cargas de combustible en este viaje.')
    expect(wrapper.text()).toContain('Falta el precio de 5 de las 8 cargas')
    expect(wrapper.text()).toContain('Sin datos')
  })

  it('filtra por fecha y por camion', () => {
    const wrapper = mountPage()

    expect(wrapper.find('input[name="desde"]').attributes('value')).toBe('2026-10-25')
    expect(wrapper.find('input[name="hasta"]').attributes('value')).toBe('2026-11-24')
    expect(wrapper.find('input[name="camion"]').exists()).toBe(true)
    expect(wrapper.find('[data-fuel-filters]').attributes('action')).toBe('/viajes/combustible')
  })

  it('permite cargar el combustible que falta en un viaje en curso', async () => {
    const wrapper = mountPage()

    await wrapper.find('[data-testid="fuel-cargar-22"]').trigger('click')

    const form = wrapper.find('[data-fuel-cargar-form]')
    expect(form.exists()).toBe(true)
    expect(form.attributes('action')).toBe('/viajes/combustible/2/carga')
    expect(form.find('input[name="litros"]').exists()).toBe(true)
    expect(form.find('input[name="importe"]').exists()).toBe(true)
  })

  it('no ofrece cargar en un viaje que ya termino', () => {
    const wrapper = mountPage()

    expect(wrapper.find('[data-testid="fuel-cargar-21"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="fuel-cargar-23"]').exists()).toBe(true)
  })

  it('permite completar el importe de las cargas que esperan su precio', async () => {
    const wrapper = mountPage()

    await wrapper.find('[data-testid="fuel-precios-21"]').trigger('click')

    const form = wrapper.find('[data-fuel-precios-form]')
    expect(form.attributes('action')).toBe('/viajes/combustible/precios')
    expect(form.find('input[name="importe[90]"]').exists()).toBe(true)
  })

  it('es de solo lectura cuando el usuario no puede operar viajes', () => {
    const wrapper = mountPage({ puedeEditar: false })

    expect(wrapper.find('[data-testid="fuel-cargar-22"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="fuel-precios-21"]').exists()).toBe(false)
  })

  it('separa los viajes sin salida real y enlaza la bandeja de precios', () => {
    const wrapper = mountPage()

    expect(wrapper.text()).toContain('Viajes sin salida real cargada')
    expect(wrapper.text()).toContain('El viaje no tiene fecha de salida real cargada.')
    expect(wrapper.find('a[href="/viajes/operativos/combustible-sin-precio"]').exists()).toBe(true)
    expect(wrapper.find('a[href="/inicio"]').exists()).toBe(true)
  })

  it('no inventa la conciliacion cuando el usuario no tiene el dato gerencial', () => {
    const wrapper = mountPage()

    expect(wrapper.find('[data-fuel-tab-conciliacion]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('Declarado por el chofer contra el sensor del tanque')
  })

  it('muestra la conciliacion contra el sensor en su propia pestana', async () => {
    const wrapper = mountPage({
      puedeConciliar: true,
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
    })

    expect(wrapper.find('[data-fuel-tab-conciliacion]').exists()).toBe(true)

    await wrapper.find('[data-fuel-tab-conciliacion]').trigger('click')

    expect(wrapper.text()).toContain('Conciliación con el sensor del tanque')
    expect(wrapper.text()).toContain('Declarado por el chofer contra el sensor del tanque')
    expect(wrapper.text()).toContain('el 47% de las lecturas')
  })
})
