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

  it('deja el input como primer control del label aunque haya etiquetas seleccionadas', async () => {
    const wrapper = mount({
      components: { AutocompleteSelect },
      data: () => ({ value: [1], options }),
      template: '<label><AutocompleteSelect v-model="value" name="choferes[]" :options="options" multiple /></label>',
    })

    const label = wrapper.get('label')
    const firstControl = label.element.querySelector('input, button, select, textarea')
    expect(firstControl.getAttribute('role')).toBe('combobox')
    expect(wrapper.get('button[aria-label="Quitar Uno"]').exists()).toBe(true)

    await label.trigger('click')
    expect(wrapper.vm.value).toEqual([1])
    wrapper.unmount()
  })

  it('ofrece dar de alta el texto buscado cuando no hay resultados', async () => {
    const wrapper = mount(AutocompleteSelect, {
      props: { modelValue: null, options: [], name: 'localidad_origen_id', placeholder: 'Buscá la localidad', allowCreate: true },
    })
    const input = wrapper.get('input[role="combobox"]')

    await input.trigger('focus')
    await input.setValue('capitan leonidas')

    const createButton = wrapper.findAll('button').find((button) => button.text().includes('Dar de alta'))
    expect(createButton).toBeTruthy()
    expect(createButton.text()).toContain('capitan leonidas')

    await createButton.trigger('mousedown')
    expect(wrapper.emitted('create')[0][0]).toBe('capitan leonidas')
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
