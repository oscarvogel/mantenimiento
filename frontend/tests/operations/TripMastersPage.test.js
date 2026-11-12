import { afterEach, describe, expect, it } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import TripMastersPage from '../../src/pages/operations/TripMastersPage.vue'

let wrapper
let main

const data = {
  canEdit: true,
  csrf: { name: 'csrf_test', hash: 'secure-token' },
  urls: { create: '/viajes/maestros/crear', exchangeRates: '/viajes/maestros/cotizaciones' },
  catalogs: {
    clientes: {
      label: 'Clientes',
      items: [{ id: 21, nombre: 'Cliente de prueba', activo: 1, updateUrl: '/viajes/maestros/clientes/21', statusUrl: '/viajes/maestros/clientes/21/estado' }],
    },
    domicilios: { label: 'Domicilios', items: [] },
    cotizaciones: {
      label: 'Cotizaciones',
      items: [
        { id: 1, moneda: 'ARS', nombre: 'Peso argentino', cotizacion: 1 },
        { id: 2, moneda: 'USD', nombre: 'Dólar estadounidense', cotizacion: 1234.5 },
      ],
    },
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

  it('carga un concepto con categoría, unidad, importe y edición habilitada', async () => {
    const pageData = {
      ...data,
      catalogs: {
        ...data.catalogs,
        conceptos: { label: 'Conceptos de gasto y haber', items: [] },
      },
      currencies: [{ id: 1, codigo: 'ARS', nombre: 'Peso argentino' }],
    }
    wrapper = mount(TripMastersPage, { props: { data: pageData } })

    await wrapper.findAll('button').find((button) => button.text() === 'Conceptos de gasto y haber').trigger('click')
    await wrapper.findAll('button').find((button) => button.text() === 'Agregar conceptos de gasto y haber').trigger('click')
    await nextTick()

    const form = document.body.querySelector('[role="dialog"] form')
    expect(form).not.toBeNull()
    expect([...form.querySelectorAll('select[name="categoria"] option')].map((option) => option.value)).toEqual(['', 'GASTO', 'HABER', 'AMBOS'])
    expect([...form.querySelectorAll('select[name="unidad"] option')].map((option) => option.value)).toEqual(['', 'DIA', 'UNIDAD', 'FIJO'])
    expect(form.querySelector('input[name="importe_unitario"]').type).toBe('number')
    expect(form.querySelector('select[name="moneda"]').value).toBe('ARS')
    expect(form.querySelector('input[type="hidden"][name="permite_editar_importe"]').value).toBe('0')
    expect(form.querySelector('input[type="checkbox"][name="permite_editar_importe"]').checked).toBe(true)
  })

  it('guarda la cotización vigente de cada moneda de la empresa', async () => {
    wrapper = mount(TripMastersPage, { props: { data } })

    await wrapper.findAll('button').find((button) => button.text() === 'Cotizaciones').trigger('click')

    const form = wrapper.find('form[action="/viajes/maestros/cotizaciones"]')
    expect(form.exists()).toBe(true)
    expect(form.find('input[name="csrf_test"]').element.value).toBe('secure-token')
    expect(form.find('input[name="cotizaciones[USD]"]').element.value).toBe('1234.5')
    expect(form.find('input[name="cotizaciones[ARS]"]').exists()).toBe(false)
  })
})
