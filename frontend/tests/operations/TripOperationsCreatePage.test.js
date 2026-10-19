import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import TripOperationsCreatePage from '../../src/pages/operations/TripOperationsCreatePage.vue'

const data = {
  links: { index: '/mantenimiento/viajes/operativos', store: '/mantenimiento/viajes/operativos' },
  csrf: { name: 'csrf_test_name', hash: 'abc123' },
  stopTypes: [
    { value: 'CARGA', label: 'Carga' },
    { value: 'DESCARGA', label: 'Descarga' },
    { value: 'HITO', label: 'Hito operativo' },
  ],
  branches: [{ id: 10, code: 'AR', name: 'Argentina' }],
  tractors: [{ id: 7, label: 'AA111AA · T01', type: 'Camión' }],
  trailers: [{ id: 9, label: 'AC222AC · A01', type: 'Acoplado' }],
  drivers: [{ id: 11, label: 'Pérez, Juan', employeeNumber: '100' }],
  customers: [{ id: 3, label: 'Cliente Sur', taxId: null }],
  addresses: [
    { id: 4, customerId: 3, label: 'Planta · Ruta 9 · Rosario' },
    { id: 5, customerId: 99, label: 'Depósito de otro cliente' },
  ],
  loadUnits: [{ id: 2, label: 'Tonelada (tn)', symbol: 'tn' }],
}

describe('alta de viaje operativo', () => {
  it('arma el formulario con dos paradas y la protección CSRF', async () => {
    const wrapper = mount(TripOperationsCreatePage, { props: { data } })

    const form = wrapper.get('form')
    expect(form.attributes('method')).toBe('post')
    expect(form.attributes('action')).toBe('/mantenimiento/viajes/operativos')
    expect(wrapper.get('input[name="csrf_test_name"]').attributes('value')).toBe('abc123')
    expect(wrapper.find('select[name="tractor_id"]').exists()).toBe(true)
    expect(wrapper.find('select[name="choferes[]"]').exists()).toBe(true)
    expect(wrapper.findAll('article')).toHaveLength(2)

    await wrapper.get('button[type="button"]').trigger('click')
    expect(wrapper.findAll('article')).toHaveLength(3)
    wrapper.unmount()
  })

  it('filtra los domicilios por cliente seleccionado', async () => {
    const wrapper = mount(TripOperationsCreatePage, { props: { data } })
    const addressSelect = wrapper.get('select[name="paradas[0][domicilio_id]"]')
    expect(addressSelect.text()).toContain('Depósito de otro cliente')

    await wrapper.get('select[name="paradas[0][cliente_id]"]').setValue('3')

    expect(wrapper.get('select[name="paradas[0][domicilio_id]"]').text()).toContain('Planta · Ruta 9 · Rosario')
    expect(wrapper.get('select[name="paradas[0][domicilio_id]"]').text()).not.toContain('Depósito de otro cliente')
    wrapper.unmount()
  })
})
