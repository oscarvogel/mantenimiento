import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent } from 'vue'
import Swal from 'sweetalert2'
import { installConfirmForms } from '../../src/ui/confirmForms.js'
import { consumeFlash } from '../../src/ui/globals.js'
import { useAlerts } from '../../src/composables/useAlerts.js'
import AssetsIndexPage from '../../src/pages/operations/AssetsIndexPage.vue'
import { assetsData } from '../operations/fixtures.js'

vi.mock('sweetalert2', () => ({
  default: {
    fire: vi.fn(),
    isLoading: vi.fn(() => false),
    isVisible: vi.fn(() => false),
  },
}))

const resolveFire = (isConfirmed) => {
  Swal.fire.mockResolvedValue({ isConfirmed })
}

function formFixture(withConfirm = false) {
  const form = document.createElement('form')
  form.method = 'post'
  form.action = '/procesar'
  if (withConfirm) form.setAttribute('data-confirm', '')
  form.innerHTML = '<button type="submit">Enviar</button>'
  document.body.appendChild(form)
  return form
}

const dispatchSubmit = (form) => form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))

const uniqueInstall = Symbol('confirmFormsInstalled')

beforeEach(() => {
  vi.clearAllMocks()
  if (document[uniqueInstall] !== true) {
    installConfirmForms()
    document[uniqueInstall] = true
  }
})

afterEach(() => {
  document.body.innerHTML = ''
  vi.clearAllMocks()
})

describe('confirmForms: listener global de data-confirm', () => {
  it('formulario sin data-confirm se envía normalmente', () => {
    const form = formFixture()
    const spy = vi.fn()
    form.addEventListener('submit', spy)

    dispatchSubmit(form)

    expect(spy).toHaveBeenCalledTimes(1)
    expect(Swal.fire).not.toHaveBeenCalled()
  })

  it('formulario con data-confirm no se envía antes de confirmar', async () => {
    const form = formFixture(true)
    resolveFire(false)
    const spy = vi.fn()
    form.addEventListener('submit', spy)

    dispatchSubmit(form)
    await flushPromises()

    expect(spy).toHaveBeenCalledTimes(1)
    expect(Swal.fire).toHaveBeenCalledTimes(1)
  })

  it('cancelar la confirmación no envía el formulario', async () => {
    const form = formFixture(true)
    resolveFire(false)
    const spy = vi.fn()
    form.addEventListener('submit', spy)

    dispatchSubmit(form)
    await flushPromises()

    expect(Swal.fire).toHaveBeenCalledTimes(1)
    expect(spy).toHaveBeenCalledTimes(1)
    expect(form.dataset.confirmed).toBeUndefined()
  })

  it('confirmar la acción envía el formulario una sola vez', async () => {
    const form = formFixture(true)
    resolveFire(true)
    const spy = vi.fn()
    form.addEventListener('submit', spy)

    dispatchSubmit(form)
    await flushPromises()

    expect(spy).toHaveBeenCalledTimes(2)
    expect(form.dataset.confirmed).toBe('true')
  })

  it('data-confirm-danger solicita el estilo destructivo', async () => {
    const form = formFixture(true)
    form.setAttribute('data-confirm-danger', 'true')
    resolveFire(true)

    dispatchSubmit(form)
    await flushPromises()

    const options = Swal.fire.mock.calls[0][0]
    expect(options.customClass.confirmButton).toContain('ui-swal-danger')
  })

  it('funciona dentro de un formulario renderizado en un componente Vue', async () => {
    resolveFire(true)

    const Wrapper = defineComponent({
      template: '<form method="post" data-confirm data-confirm-title="¿Confirmar?"><button type="submit">Guardar</button></form>',
    })
    const wrapper = mount(Wrapper, { attachTo: document.body })

    dispatchSubmit(wrapper.get('form').element)
    await flushPromises()

    expect(Swal.fire).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })
})

describe('useAlerts: tipos de alerta', () => {
  const fixtures = [
    ['success', 'success', 2600],
    ['error', 'error', undefined],
    ['warning', 'warning', undefined],
    ['info', 'info', 4000],
    ['denied', 'error', undefined],
  ]

  it.each(fixtures)('%s dispara SweetAlert con el tipo y temporización correctos', (type, icon, timer) => {
    const alerts = useAlerts()
    Swal.fire.mockResolvedValue({ isConfirmed: false })

    alerts[type]('Título', 'Texto')

    expect(Swal.fire).toHaveBeenCalledWith(expect.objectContaining({
      icon,
      title: 'Título',
      text: 'Texto',
      timer,
    }))
  })
})

describe('consumeFlash: flash del servidor → SweetAlert centralizado', () => {
  it('flash.success produce una alerta success', () => {
    consumeFlash({ success: 'Cambios guardados.' })

    expect(Swal.fire).toHaveBeenCalledTimes(1)
    expect(Swal.fire).toHaveBeenCalledWith(expect.objectContaining({ icon: 'success' }))
  })

  it('flash.error produce una alerta error', () => {
    consumeFlash({ error: 'No se pudo guardar.' })

    expect(Swal.fire).toHaveBeenCalledTimes(1)
    expect(Swal.fire).toHaveBeenCalledWith(expect.objectContaining({ icon: 'error' }))
  })

  it('prioriza error por sobre success cuando coexisten', () => {
    consumeFlash({ success: 'OK', error: 'Falló algo.' })

    expect(Swal.fire.mock.calls.map((call) => call[0].icon)).toEqual(['error', 'success'])
  })

  it('ignora valores no textuales', () => {
    consumeFlash({ success: '', error: null })

    expect(Swal.fire).not.toHaveBeenCalled()
  })
})

describe('prefers-reduced-motion', () => {
  it('respeta reduced motion sin romper los estilos de la alerta', () => {
    window.matchMedia = vi.fn().mockReturnValue({ matches: true })
    const alerts = useAlerts()
    Swal.fire.mockResolvedValue({ isConfirmed: true })

    alerts.confirm({ title: '¿Confirmar?', danger: true })

    const options = Swal.fire.mock.calls[0][0]
    expect(options.customClass.confirmButton).toContain('ui-swal-danger')
    expect(options.showClass).toBeDefined()
  })
})

describe('sin banners duplicados', () => {
  it('las páginas ya no renderizan banners inline por flash', () => {
    const data = {
      ...assetsData,
      flash: { success: 'Operación completada.', error: '' },
    }
    const wrapper = mount(AssetsIndexPage, { props: { data } })

    expect(wrapper.find('[role="status"], [role="alert"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('Operación completada.')
    wrapper.unmount()
  })
})