import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import AutocompleteSelect from '../../src/pages/operations/components/AutocompleteSelect.vue'

const options = [
  { value: 1, label: 'Uno', hint: 'Camión' },
  { value: 2, label: 'Dos', hint: 'Acoplado' },
  { value: 3, label: 'Tres' },
]

describe('AutocompleteSelect', () => {
  it('filtra, selecciona y refleja el valor en un input oculto', async () => {
    const wrapper = mount(AutocompleteSelect, {
      props: { modelValue: null, options, name: 'tractor_id', placeholder: 'Elegí' },
    })
    const input = wrapper.get('input[role="combobox"]')

    await input.trigger('focus')
    expect(wrapper.findAll('button[role="option"]')).toHaveLength(3)

    await input.setValue('dos')
    expect(wrapper.findAll('button[role="option"]')).toHaveLength(1)
    expect(wrapper.text()).toContain('Dos')

    await wrapper.get('button[role="option"]').trigger('mousedown')
    expect(wrapper.emitted('update:modelValue')[0][0]).toBe(2)

    await wrapper.setProps({ modelValue: 2 })
    expect(wrapper.get('input[name="tractor_id"]').attributes('value')).toBe('2')
    expect(wrapper.get('input[role="combobox"]').element.value).toBe('Dos')
    wrapper.unmount()
  })

  it('permite seleccionar varios choferes y quitarlos', async () => {
    const wrapper = mount(AutocompleteSelect, {
      props: { modelValue: [], options, name: 'choferes[]', multiple: true },
    })
    await wrapper.get('input[role="combobox"]').trigger('focus')
    await wrapper.findAll('button[role="option"]')[0].trigger('mousedown')
    expect(wrapper.emitted('update:modelValue')[0][0]).toEqual([1])

    await wrapper.setProps({ modelValue: [1, 3] })
    expect(wrapper.get('input[name="choferes[]"]').attributes('value')).toBe('1')
    expect(wrapper.findAll('input[name="choferes[]"]')).toHaveLength(2)
    expect(wrapper.text()).toContain('Uno')
    expect(wrapper.text()).toContain('Tres')

    await wrapper.get('button[aria-label="Quitar Uno"]').trigger('click')
    expect(wrapper.emitted('update:modelValue').at(-1)[0]).toEqual([3])
    wrapper.unmount()
  })
})
