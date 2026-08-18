import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import TaskEditModal from './TaskEditModal.vue'

const task = () => ({
  id: 1,
  serviceTypeId: 10,
  name: 'Cambiar aceite y filtros',
  code: 'ACEITE-FILTROS',
  description: 'Renovar aceite y filtros según el procedimiento del equipo.',
  procedure: '',
  order: 1,
  durationMinutes: 90,
  observations: '',
  mandatory: true,
  active: true,
  requiresPart: true,
  requiresControl: true,
  requiresPhoto: false,
  updateUrl: '/mantenimiento/importaciones/biblioteca/tareas/1',
})

afterEach(() => {
  vi.restoreAllMocks()
})

describe('TaskEditModal', () => {
  it('no dispara requests al cambiar checks y guarda todo en un único POST', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue({
      ok: true,
      redirected: false,
      headers: new Headers({ 'content-type': 'application/json' }),
      json: vi.fn().mockResolvedValue({ ok: true }),
    })

    const wrapper = mount(TaskEditModal, {
      props: {
        open: true,
        task: task(),
        csrf: { name: 'csrf_test_name', hash: 'HASH123' },
        canEdit: true,
      },
      attachTo: document.body,
    })
    await flushPromises()

    const active = wrapper.find('input[name="activo"]')
    const requiresPhoto = wrapper.find('input[name="requiere_foto"]')
    await active.setValue(false)
    await requiresPhoto.setValue(true)
    await flushPromises()

    expect(fetchMock).not.toHaveBeenCalled()

    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(fetchMock).toHaveBeenCalledTimes(1)
    expect(fetchMock).toHaveBeenCalledWith(
      '/mantenimiento/importaciones/biblioteca/tareas/1',
      expect.objectContaining({ method: 'POST', credentials: 'same-origin' }),
    )

    const [, options] = fetchMock.mock.calls[0]
    expect(options.body).toBeInstanceOf(FormData)
    expect(options.body.get('csrf_test_name')).toBe('HASH123')
    expect(options.body.get('activo')).toBeNull()
    expect(options.body.get('requiere_foto')).toBe('1')

    wrapper.unmount()
  })
})
