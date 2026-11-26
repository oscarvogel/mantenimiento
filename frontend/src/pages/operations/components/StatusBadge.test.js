import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import StatusBadge from './StatusBadge.vue'

const badge = (status) => mount(StatusBadge, { props: { status } })

describe('StatusBadge', () => {
  it('muestra el estado en lenguaje humano, no el código', () => {
    expect(badge('EN_PROCESO').text()).toBe('En proceso')
    expect(badge('BORRADOR_VALIDADO').text()).toBe('Borrador validado')
    expect(badge('AL_DIA').text()).toBe('Al día')
    expect(badge('VALIDA').text()).toBe('Válida')
  })

  it('no depende solo del color: cada tono tiene ícono', () => {
    const vencido = badge('VENCIDO')
    expect(vencido.classes()).toContain('bg-danger-subtle')
    expect(vencido.find('svg[aria-hidden="true"]').exists()).toBe(true)
  })

  it('un estado desconocido se muestra legible y con tono informativo', () => {
    const otro = badge('EN_REVISION_TECNICA')
    expect(otro.text()).toBe('En revision tecnica')
    expect(otro.classes()).toContain('bg-info-subtle')
  })
})
