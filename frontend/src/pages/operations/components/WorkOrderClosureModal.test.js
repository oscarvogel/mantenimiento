import { afterEach, describe, expect, it } from 'vitest'
import { nextTick } from 'vue'
import { mount } from '@vue/test-utils'
import WorkOrderClosureModal from './WorkOrderClosureModal.vue'

const wrappers = []

afterEach(() => {
  for (const wrapper of wrappers.splice(0)) wrapper.unmount()
})

const mountModal = () => {
  const formState = {
    kilometers: '',
    hours: '',
    currentKm: null,
    currentHours: null,
    tasks: {
      41: { resultado: 'REALIZADA', detalle: '' },
    },
    costo_mano_obra: '',
    costo_repuestos: '',
    otros_costos: '',
  }

  const wrapper = mount(WorkOrderClosureModal, {
    props: {
      order: {
        id: 12,
        number: 'OT-2026-000012',
        closeUrl: '/mantenimiento/ordenes/12/cerrar',
        controlsKm: false,
        controlsHours: false,
        tasks: [{ id: 41, description: 'Cambiar filtro', status: 'PENDIENTE' }],
      },
      csrf: { name: 'csrf_test_name', hash: 'HASH123' },
      formState,
    },
    attachTo: document.body,
  })
  wrappers.push(wrapper)
  return wrapper
}

const setInputValue = async (element, value) => {
  element.value = value
  element.dispatchEvent(new Event('input', { bubbles: true }))
  await nextTick()
}

describe('WorkOrderClosureModal', () => {
  it('muestra los tres costos opcionales y actualiza el total en tiempo real', async () => {
    mountModal()

    const labor = document.querySelector('input[name="costo_mano_obra"]')
    const parts = document.querySelector('input[name="costo_repuestos"]')
    const others = document.querySelector('input[name="otros_costos"]')

    expect(labor).not.toBeNull()
    expect(parts).not.toBeNull()
    expect(others).not.toBeNull()
    expect(labor.min).toBe('0')
    expect(labor.step).toBe('0.01')

    await setInputValue(labor, '100.50')
    await setInputValue(parts, '200')
    await setInputValue(others, '10.25')

    const total = document.querySelector('[data-testid="work-order-cost-total"]')
    expect(total).not.toBeNull()
    expect(total.textContent).toContain('$ 310,75')
  })

  it('no envía un campo costo_total editable al servidor', () => {
    mountModal()

    expect(document.querySelector('input[name="costo_total"]')).toBeNull()
    expect(document.querySelector('[data-testid="work-order-cost-total"]')).not.toBeNull()
  })
})
