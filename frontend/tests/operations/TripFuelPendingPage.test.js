import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import TripFuelPendingPage from '../../src/pages/operations/TripFuelPendingPage.vue'

const data = () => ({
  csrf: { name: 'csrf_test_name', hash: 'abc123' },
  links: { save: '/mantenimiento/viajes/operativos/combustible-sin-precio', trips: '/mantenimiento/viajes/operativos' },
  summary: { loads: 3, trips: 2, liters: 650 },
  currencies: [{ code: 'ARS', label: 'Peso argentino', rate: 1 }, { code: 'USD', label: 'Dólar', rate: 1490 }],
  loads: [
    { id: 11, viaje_id: 4, viaje_numero: 2, viaje_estado: 'FINALIZADO', sucursal: 'Central', fecha: '2026-11-15 08:30:00', litros: 300, kilometraje: null, estacion: 'Playa propia', comprobante: null, observaciones: null, tractor: 'AA111AA · T01', url: '/mantenimiento/viajes/operativos/4' },
    { id: 12, viaje_id: 4, viaje_numero: 2, viaje_estado: 'FINALIZADO', sucursal: 'Central', fecha: '2026-11-15 09:10:00', litros: 200, kilometraje: null, estacion: null, comprobante: 'R-7', observaciones: null, tractor: 'AA111AA · T01', url: '/mantenimiento/viajes/operativos/4' },
    { id: 13, viaje_id: 5, viaje_numero: 3, viaje_estado: 'EN_CURSO', sucursal: 'Central', fecha: '2026-11-16 07:00:00', litros: 150, kilometraje: null, estacion: null, comprobante: null, observaciones: null, tractor: null, url: '/mantenimiento/viajes/operativos/5' },
  ],
})

describe('combustible sin precio', () => {
  it('lista las cargas pendientes con su viaje y arranca sin nada para guardar', () => {
    const wrapper = mount(TripFuelPendingPage, { props: { data: data() } })

    expect(wrapper.findAll('[data-fuel-row]')).toHaveLength(3)
    expect(wrapper.text()).toContain('Viaje #2')
    expect(wrapper.text()).toContain('Finalizado')
    expect(wrapper.text()).toContain('AA111AA · T01')
    expect(wrapper.get('[data-fuel-save]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('form[data-fuel-prices]').attributes('action')).toBe('/mantenimiento/viajes/operativos/combustible-sin-precio')
    expect(wrapper.get('input[name="csrf_test_name"]').element.value).toBe('abc123')
    // El botón Guardar no queda debajo del asistente flotante.
    expect(wrapper.get('[data-fuel-save-bar]').classes()).toContain('pr-24')
  })

  it('calcula el importe desde el precio por litro y al revés', async () => {
    const wrapper = mount(TripFuelPendingPage, { props: { data: data() } })
    const [first, second] = wrapper.findAll('[data-fuel-row]')

    await first.get('[data-price]').setValue('1450')
    expect(first.get('input[name="importe[11]"]').element.value).toBe('435000')

    await second.get('input[name="importe[12]"]').setValue('290000')
    expect(second.get('[data-price]').element.value).toBe('1450')

    expect(wrapper.get('[data-fuel-total]').text()).toContain('2 cargas con precio')
    expect(wrapper.get('[data-fuel-total]').text()).toContain('725.000,00')
    expect(wrapper.get('[data-fuel-save]').attributes('disabled')).toBeUndefined()
  })

  it('aplica el mismo precio por litro sólo a las filas vacías', async () => {
    const wrapper = mount(TripFuelPendingPage, { props: { data: data() } })
    const rows = wrapper.findAll('[data-fuel-row]')
    await rows[0].get('input[name="importe[11]"]').setValue('400000')

    await wrapper.get('[data-bulk-price]').setValue('1500')
    await wrapper.get('[data-bulk-apply]').trigger('click')

    expect(rows[0].get('input[name="importe[11]"]').element.value).toBe('400000')
    expect(rows[1].get('input[name="importe[12]"]').element.value).toBe('300000')
    expect(rows[2].get('input[name="importe[13]"]').element.value).toBe('225000')
  })

  it('pide cotización sólo en moneda extranjera y propone la de la empresa', async () => {
    const wrapper = mount(TripFuelPendingPage, { props: { data: data() } })
    const row = wrapper.findAll('[data-fuel-row]')[0]
    expect(row.find('input[name="cotizacion[11]"]').exists()).toBe(false)

    await row.get('select[name="moneda[11]"]').setValue('USD')
    expect(row.get('input[name="cotizacion[11]"]').element.value).toBe('1490')
  })

  it('muestra el estado vacío cuando no hay nada pendiente', () => {
    const payload = data()
    payload.loads = []
    payload.summary = { loads: 0, trips: 0, liters: 0 }
    const wrapper = mount(TripFuelPendingPage, { props: { data: payload } })

    expect(wrapper.text()).toContain('No hay combustible sin precio')
    expect(wrapper.find('form').exists()).toBe(false)
  })
})
