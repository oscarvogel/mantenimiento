import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { nextTick } from 'vue'
import BaseDialog from './BaseDialog.vue'

const render = (props = {}) => mount(BaseDialog, {
  props: { title: 'Dar de baja', description: 'Juan Pérez', ...props },
  slots: { default: '<p class="contenido">Contenido</p>' },
})

const dialog = () => document.body.querySelector('dialog')

describe('BaseDialog', () => {
  it('abre como modal sobre el body con título accesible', async () => {
    render()
    await nextTick()
    expect(dialog().hasAttribute('open')).toBe(true)
    expect(dialog().getAttribute('aria-modal')).toBe('true')
    expect(document.getElementById(dialog().getAttribute('aria-labelledby')).textContent).toBe('Dar de baja')
    expect(dialog().textContent).toContain('Juan Pérez')
  })

  it('Escape, el fondo y la X piden cerrar', async () => {
    const wrapper = render()
    await nextTick()
    dialog().dispatchEvent(new Event('cancel', { cancelable: true }))
    dialog().click()
    document.body.querySelector('button[aria-label="Cerrar"]').click()
    expect(wrapper.emitted('close')).toHaveLength(3)
  })

  it('un clic dentro del contenido no cierra', async () => {
    const wrapper = render()
    await nextTick()
    document.body.querySelector('.contenido').click()
    expect(wrapper.emitted('close')).toBeUndefined()
  })

  it('mientras guarda no se puede cerrar', async () => {
    const wrapper = render({ persistent: true })
    await nextTick()
    dialog().dispatchEvent(new Event('cancel', { cancelable: true }))
    dialog().click()
    expect(wrapper.emitted('close')).toBeUndefined()
    expect(document.body.querySelector('button[aria-label="Cerrar"]').disabled).toBe(true)
  })

  it('cerrado no deja el modal en la página', async () => {
    const wrapper = render()
    await nextTick()
    await wrapper.setProps({ open: false })
    expect(dialog()).toBeNull()
  })

  it('respeta el id del título, la etiqueta de cerrar y los atributos extra', async () => {
    mount(BaseDialog, { props: { title: 'Cierre', titleId: 'cierre-title', closeLabel: 'Cerrar evidencias' }, attrs: { 'data-testid': 'cierre' } })
    await nextTick()
    expect(dialog().getAttribute('aria-labelledby')).toBe('cierre-title')
    expect(dialog().dataset.testid).toBe('cierre')
    expect(document.body.querySelector('button[aria-label="Cerrar evidencias"]')).not.toBeNull()
  })
})
