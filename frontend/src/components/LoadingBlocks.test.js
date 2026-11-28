import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import LoadingBlocks from './LoadingBlocks.vue'

describe('LoadingBlocks', () => {
  it('muestra bloques sin texto visible y avisa al lector de pantalla qué se está cargando', () => {
    const wrapper = mount(LoadingBlocks, { props: { label: 'Cargando cambios…', rows: 4 }, attrs: { id: 'titulo' } })

    expect(wrapper.attributes('role')).toBe('status')
    expect(wrapper.attributes('id')).toBe('titulo')
    expect(wrapper.get('.sr-only').text()).toBe('Cargando cambios…')
    expect(wrapper.findAll('[aria-hidden="true"]')).toHaveLength(4)
    // Latido de Tailwind: transitions.css lo apaga con movimiento reducido.
    expect(wrapper.classes()).toContain('animate-pulse')
  })
})
