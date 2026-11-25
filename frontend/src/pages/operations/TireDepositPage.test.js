import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import TireDepositPage from './TireDepositPage.vue'

const data = (overrides = {}) => ({
  csrf: { name: 'csrf_test', hash: 'abc' },
  canOperate: true,
  selectedDeposit: null,
  movementsLimit: 50,
  oldInput: {},
  urls: { index: '/neumaticos/deposito', movement: '/neumaticos/deposito/movimientos', masters: '/neumaticos/maestros#depositos', registry: '/neumaticos' },
  deposits: [
    { id: 4, name: 'Central', branchName: 'Oberá', location: 'Ruta 14', tires: 1, negative: 1 },
    { id: 5, name: 'Posadas', branchName: 'Posadas', location: '', tires: 1, negative: 0 },
  ],
  stock: [
    { depositId: 4, depositName: 'Central', neumaticId: 10, number: 'TSA 1', status: 'NUEVA', statusLabel: 'Nueva', description: 'Michelin · 295/80', quantity: 1, updatedAt: null, tireUrl: '/neumaticos/10' },
    { depositId: 4, depositName: 'Central', neumaticId: 11, number: 'TSA 2', status: 'MEDIO_USO', statusLabel: '1/2 uso', description: '', quantity: -1, updatedAt: null, tireUrl: '/neumaticos/11' },
    { depositId: 5, depositName: 'Posadas', neumaticId: 12, number: 'TSA 3', status: 'NUEVA', statusLabel: 'Nueva', description: '', quantity: 1, updatedAt: null, tireUrl: '/neumaticos/12' },
  ],
  movements: [
    { at: '2026-11-21 10:30:00', type: 'AJUSTE', depositName: 'Central', neumaticId: 11, number: 'TSA 2', quantity: -1, sheetNumber: null, notes: 'Conteo físico', userName: 'Carla Gómez', tireUrl: '/neumaticos/11' },
    { at: '2026-11-20 09:00:00', type: 'SALIDA', depositName: 'Posadas', neumaticId: 12, number: 'TSA 3', quantity: 1, sheetNumber: 'PL-3', notes: null, userName: null, tireUrl: '/neumaticos/12' },
    { at: '2026-11-19 09:00:00', type: 'ENTRADA', depositName: 'Central', neumaticId: 10, number: 'TSA 1', quantity: 1, sheetNumber: 'PL-1', notes: null, userName: null, tireUrl: '/neumaticos/10' },
  ],
  tires: [
    { id: 10, number: 'TSA 1', status: 'NUEVA', statusLabel: 'Nueva', description: 'Michelin · 295/80', canEnter: true },
    { id: 11, number: 'TSA 2', status: 'MEDIO_USO', statusLabel: '1/2 uso', description: '', canEnter: true },
    { id: 12, number: 'TSA 3', status: 'NUEVA', statusLabel: 'Nueva', description: '', canEnter: true },
    { id: 13, number: 'TSA 4', status: 'VENDIDA', statusLabel: 'Vendida', description: '', canEnter: false },
  ],
  types: [{ value: 'ENTRADA', label: 'Entrada' }, { value: 'SALIDA', label: 'Salida' }, { value: 'AJUSTE', label: 'Ajuste' }],
  ...overrides,
})

const mountPage = (overrides = {}) => mount(TireDepositPage, { props: { data: data(overrides) }, attachTo: document.body })
const openForm = async (wrapper) => {
  await wrapper.findAll('button').find((button) => button.text().includes('Registrar movimiento')).trigger('click')
}
const dialog = () => document.querySelector('[role="dialog"]')
const optionLabels = async (wrapper) => {
  const component = wrapper.findComponent({ name: 'AutocompleteSelect' })
  return component.props('options').map((option) => option.label)
}

afterEach(() => {
  document.body.innerHTML = ''
  window.history.replaceState(null, '', '#')
})

describe('TireDepositPage', () => {
  it('muestra los depósitos como filtro con su cantidad', () => {
    const wrapper = mountPage()

    const links = wrapper.findAll('nav[aria-label="Depósitos"] a')
    expect(links.map((link) => link.text())).toEqual(['Todos', 'Central 1', 'Posadas 1'])
    expect(links[1].attributes('href')).toBe('/neumaticos/deposito?deposito=4')
    expect(links[0].attributes('aria-current')).toBe('page')
  })

  it('lista las existencias con su ficha y marca la negativa', () => {
    const wrapper = mountPage()

    expect(wrapper.find('a[href="/neumaticos/10"]').text()).toBe('TSA 1')
    expect(wrapper.text()).toContain('Michelin · 295/80 · Central')
    expect(wrapper.text()).toContain('Existencia -1')
  })

  it('muestra los movimientos con signo, planilla, motivo y usuario', () => {
    const wrapper = mountPage()

    const text = wrapper.text()
    expect(text).toContain('Ajuste · TSA 2 · Central')
    expect(text).toContain('Conteo físico')
    expect(text).toContain('21/11/2026 10:30 · Carla Gómez')
    expect(text).toContain('Planilla PL-3')
    expect(text).toContain('−1')
    expect(text).toContain('+1')
  })

  it('sin depósitos lleva a cargarlos en Maestros y no ofrece movimientos', () => {
    const wrapper = mountPage({ deposits: [], stock: [], movements: [] })

    expect(wrapper.text()).toContain('Todavía no hay depósitos activos.')
    expect(wrapper.find('a[href="/neumaticos/maestros#depositos"]').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('Registrar movimiento')
  })

  it('una entrada no ofrece neumáticos en estado final y pide planilla', async () => {
    const wrapper = mountPage()
    await openForm(wrapper)

    expect(await optionLabels(wrapper)).toEqual(['TSA 1', 'TSA 2', 'TSA 3'])
    expect(dialog().querySelector('#tire-movement-sheet').required).toBe(true)
    expect(dialog().querySelector('form').getAttribute('action')).toBe('/neumaticos/deposito/movimientos')
  })

  it('una salida solo ofrece lo que hay en el depósito elegido', async () => {
    const wrapper = mountPage()
    await openForm(wrapper)

    dialog().querySelector('input[value="SALIDA"]').click()
    const deposit = dialog().querySelector('#tire-movement-deposit')
    deposit.value = '4'
    deposit.dispatchEvent(new Event('change'))
    await wrapper.vm.$nextTick()

    expect(await optionLabels(wrapper)).toEqual(['TSA 1'])
  })

  it('un ajuste pide motivo, acepta negativos y no pide planilla', async () => {
    const wrapper = mountPage()
    await openForm(wrapper)

    dialog().querySelector('input[value="AJUSTE"]').click()
    await wrapper.vm.$nextTick()

    expect(dialog().querySelector('#tire-movement-sheet')).toBeNull()
    expect(dialog().querySelector('#tire-movement-notes').required).toBe(true)
    expect(dialog().querySelector('#tire-movement-quantity').hasAttribute('min')).toBe(false)
    expect(await optionLabels(wrapper)).toContain('TSA 4')
  })

  it('arranca en el depósito filtrado', async () => {
    const wrapper = mountPage({ selectedDeposit: 5 })
    await openForm(wrapper)

    expect(dialog().querySelector('#tire-movement-deposit').value).toBe('5')
  })

  it('reabre el formulario con lo cargado cuando el servidor lo rechazó', () => {
    mountPage({ oldInput: { tipo: 'SALIDA', deposito_id: '4', numero_planilla: 'PL-9', cantidad: '2' } })

    expect(dialog()).not.toBeNull()
    expect(dialog().querySelector('input[value="SALIDA"]').checked).toBe(true)
    expect(dialog().querySelector('#tire-movement-sheet').value).toBe('PL-9')
    expect(dialog().querySelector('#tire-movement-quantity').value).toBe('2')
  })

  it('quien solo consulta ve existencias y movimientos sin poder registrar', () => {
    const wrapper = mountPage({ canOperate: false, tires: [], oldInput: { tipo: 'ENTRADA' } })

    expect(wrapper.text()).not.toContain('Registrar movimiento')
    expect(dialog()).toBeNull()
    expect(wrapper.text()).toContain('TSA 1')
  })
})
