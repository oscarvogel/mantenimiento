import { afterEach, describe, expect, it } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import TripMastersPage from '../../src/pages/operations/TripMastersPage.vue'

let wrapper
let main

const data = {
  canEdit: true,
  csrf: { name: 'csrf_test', hash: 'secure-token' },
  urls: { create: '/viajes/maestros/crear' },
  catalogs: {
    clientes: {
      label: 'Clientes',
      items: [{ id: 21, nombre: 'Cliente de prueba', activo: 1, updateUrl: '/viajes/maestros/clientes/21', statusUrl: '/viajes/maestros/clientes/21/estado' }],
    },
    domicilios: { label: 'Domicilios', items: [] },
  },
  countries: [{ id: 1, nombre: 'Argentina', codigo_iso: 'AR', activo: 1 }],
  currencies: [],
  employees: [],
}

afterEach(() => {
  wrapper?.unmount()
  main?.remove()
})

describe('TripMastersPage editor', () => {
  it.each([
    ['Nuevo cliente', 'clientes'],
    ['Agregar domicilio', 'domicilios'],
  ])('abre %s fuera del main transformado para mantener las acciones en el viewport', async (label, catalog) => {
    // El shell conserva un transform tras la animación y su contenido supera
    // el viewport móvil; un overlay fijo descendiente se ancla a ese main.
    main = document.createElement('main')
    main.className = 'ui-view-enter'
    main.style.transform = 'translateY(0)'
    main.style.height = '1493px'
    document.body.append(main)
    wrapper = mount(TripMastersPage, { props: { data }, attachTo: main })

    await wrapper.findAll('button').find((button) => button.text() === label).trigger('click')

    const dialog = document.body.querySelector('[role="dialog"]')
    expect(dialog).not.toBeNull()
    expect(main.contains(dialog)).toBe(false)
    expect(dialog.parentElement.parentElement).toBe(document.body)

    const form = dialog.querySelector('form')
    expect(form.getAttribute('action')).toBe('/viajes/maestros/crear')
    expect(form.querySelector('input[name="catalogo"]').value).toBe(catalog)
    expect(form.querySelector('input[name="csrf_test"]').value).toBe('secure-token')
    expect(form.querySelector('button[type="submit"]').textContent).toBe('Guardar')
    if (catalog === 'domicilios') {
      expect(form.querySelector('input[name="cliente_id"]').value).toBe('21')
    }

    Array.from(form.querySelectorAll('button')).find((button) => button.textContent === 'Cancelar').click()
    await flushPromises()
    expect(document.body.querySelector('[role="dialog"]')).toBeNull()
  })
})
