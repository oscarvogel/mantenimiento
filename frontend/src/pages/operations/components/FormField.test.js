import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import FormField from './FormField.vue'

const field = (props) => mount(FormField, {
  props: { label: 'Kilometraje', forId: 'km', ...props },
  slots: { default: `<template #default="field"><input id="km" v-bind="field.control"></template>` },
})

describe('FormField', () => {
  it('conecta la ayuda y el error con el campo', () => {
    const wrapper = field({ hint: 'Sin puntos', error: 'No puede ser menor a la última lectura' })
    const input = wrapper.get('input')
    expect(input.attributes('aria-describedby')).toBe('km-hint km-error')
    expect(input.attributes('aria-invalid')).toBe('true')
    expect(wrapper.get('#km-error').attributes('role')).toBe('alert')
  })

  it('sin error no marca el campo como inválido', () => {
    const input = field({}).get('input')
    expect(input.attributes('aria-invalid')).toBeUndefined()
    expect(input.attributes('aria-describedby')).toBeUndefined()
  })
})
