import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import UsageReadingInput from '../../src/pages/operations/components/UsageReadingInput.vue'

const render = (equipment, modelValue = {}) => mount(UsageReadingInput, {
  props: {
    equipment,
    modelValue: { kilometers: '', hours: '', currentKm: null, currentHours: null, ...modelValue },
  },
})
const both = { controlsKm: true, controlsHours: true }

describe('UsageReadingInput', () => {
  it('muestra solo el campo de horas cuando el equipo no controla kilómetros', () => {
    const wrapper = render({ controlsKm: false, controlsHours: true })
    expect(wrapper.find('input[name="kilometers"]').exists()).toBe(false)
    expect(wrapper.find('input[name="hours"]').exists()).toBe(true)
  })

  it('muestra solo el campo de kilómetros cuando el equipo no controla horas', () => {
    const wrapper = render({ controlsKm: true, controlsHours: false })
    expect(wrapper.find('input[name="kilometers"]').exists()).toBe(true)
    expect(wrapper.find('input[name="hours"]').exists()).toBe(false)
  })

  it('muestra ambos campos cuando el equipo controla ambas métricas', () => {
    const wrapper = render(both)
    expect(wrapper.find('input[name="kilometers"]').exists()).toBe(true)
    expect(wrapper.find('input[name="hours"]').exists()).toBe(true)
  })

  it('muestra el delta positivo de horas con una cifra decimal', async () => {
    const wrapper = render({ controlsKm: false, controlsHours: true }, { currentHours: '1250.4' })
    await wrapper.get('input[name="hours"]').setValue('1258,4')
    await wrapper.setProps({ modelValue: wrapper.emitted('update:modelValue').at(-1)[0] })
    expect(wrapper.text()).toContain('+8,0 h')
  })

  it('muestra sin variación cuando el valor no cambia', async () => {
    const wrapper = render({ controlsKm: false, controlsHours: true }, { currentHours: '1250.4' })
    await wrapper.get('input[name="hours"]').setValue('1250,4')
    await wrapper.setProps({ modelValue: wrapper.emitted('update:modelValue').at(-1)[0] })
    expect(wrapper.text()).toContain('Sin variación')
  })

  it('advierte cuando la lectura retrocede', async () => {
    const wrapper = render(both, { currentKm: 1000, currentHours: '1250.4' })
    await wrapper.get('input[name="kilometers"]').setValue('999')
    await wrapper.setProps({ modelValue: wrapper.emitted('update:modelValue').at(-1)[0] })
    expect(wrapper.text()).toContain('El valor es menor al último registro.')
  })

  it('rechaza kilometraje decimal con un mensaje independiente del horómetro', async () => {
    const wrapper = render({ controlsKm: true, controlsHours: false }, { currentKm: 1000 })
    await wrapper.get('input[name="kilometers"]').setValue('1000,5')
    await wrapper.setProps({ modelValue: wrapper.emitted('update:modelValue').at(-1)[0] })
    expect(wrapper.text()).toContain('El kilometraje debe ser un número entero positivo.')
    expect(wrapper.text()).not.toContain('El horómetro debe ser')
  })

  it('acepta coma decimal, muestra el delta y conserva el valor para el POST', async () => {
    const wrapper = render({ controlsKm: false, controlsHours: true }, { currentHours: '1250.4' })
    const hours = wrapper.get('input[name="hours"]')
    await hours.setValue('1258,4')
    await wrapper.setProps({ modelValue: wrapper.emitted('update:modelValue').at(-1)[0] })
    expect(wrapper.text()).toContain('+8,0 h')
    expect(wrapper.emitted('update:modelValue').at(-1)[0].hours).toBe('1258,4')
  })

  it('explica el formato inválido en lenguaje humano', async () => {
    const wrapper = render({ controlsKm: false, controlsHours: true }, { currentHours: '1250.4' })
    await wrapper.get('input[name="hours"]').setValue('1258,44')
    await wrapper.setProps({ modelValue: wrapper.emitted('update:modelValue').at(-1)[0] })
    expect(wrapper.text()).toContain('El horómetro debe ser un número positivo con un decimal como máximo. Podés usar coma o punto.')
  })
})
