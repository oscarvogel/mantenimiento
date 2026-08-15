import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import UsageReadingInput from '../../src/pages/operations/components/UsageReadingInput.vue'

describe('UsageReadingInput', () => {
  it('acepta coma decimal, muestra el delta localizado y conserva el valor para el POST', async () => {
    const wrapper = mount(UsageReadingInput, {
      props: {
        equipment: { controlsKm: false, controlsHours: true },
        modelValue: { kilometers: '', hours: '', currentKm: null, currentHours: '1250.4' },
      },
    })

    const hours = wrapper.get('input[name="hours"]')
    await hours.setValue('1258,4')
    await wrapper.setProps({ modelValue: wrapper.emitted('update:modelValue').at(-1)[0] })

    expect(wrapper.text()).toContain('+8,0 h')
    expect(wrapper.emitted('update:modelValue').at(-1)[0].hours).toBe('1258,4')
  })

  it('explica el formato sin mostrar detalles técnicos cuando es inválido', async () => {
    const wrapper = mount(UsageReadingInput, {
      props: {
        equipment: { controlsKm: false, controlsHours: true },
        modelValue: { kilometers: '', hours: '', currentKm: null, currentHours: '1250.4' },
      },
    })

    await wrapper.get('input[name="hours"]').setValue('1258,44')
    await wrapper.setProps({ modelValue: wrapper.emitted('update:modelValue').at(-1)[0] })

    expect(wrapper.text()).toContain('El horómetro debe ser un número positivo con un decimal como máximo. Podés usar coma o punto.')
  })
})
