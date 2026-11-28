import { installConfirmForms } from './confirmForms.js'
import { installNumberInputWheelGuard } from './numberInputWheel.js'
import { useAlerts } from '../composables/useAlerts.js'
import { rememberSuccessfulSave } from './rowGlow.js'

const FLASH_ORDER = ['error', 'warning', 'success', 'info']

const flashHandlers = {
  error: (alerts, text) => alerts.error(text),
  warning: (alerts, text) => alerts.warning(text),
  success: (alerts, text) => alerts.success(text),
  info: (alerts, text) => alerts.info(text),
  denied: (alerts, text) => alerts.denied(text),
}

export function installGlobalBehaviors() {
  installConfirmForms()
  installNativeFormFeedback()
  installNumberInputWheelGuard()
}

function isFormControl(target) {
  return target instanceof HTMLInputElement
    || target instanceof HTMLSelectElement
    || target instanceof HTMLTextAreaElement
    || target instanceof HTMLButtonElement
  }

function installNativeFormFeedback() {
  document.addEventListener('invalid', (event) => {
    const control = event.target
    if (!isFormControl(control)) return

    control.setAttribute('aria-invalid', 'true')
    const form = control.form
    if (!form || form.dataset.validationFocusQueued === 'true') return

    form.dataset.validationFocusQueued = 'true'
    queueMicrotask(() => {
      delete form.dataset.validationFocusQueued
      if (!control.isConnected || control.disabled) return
      control.focus()
      shakeField(control)
    })
  }, true)

  // Validación propia de Vue: después del submit, el primer campo marcado se sacude.
  // No se mira mientras se escribe: Lecturas rápidas marca filas en vivo.
  document.addEventListener('submit', (event) => {
    const form = event.target
    if (!(form instanceof HTMLFormElement)) return
    requestAnimationFrame(() => shakeFirstInvalid(form))
  })

  document.addEventListener('input', clearInvalidState)
  document.addEventListener('change', clearInvalidState)

  // Todo POST que navega muestra que se está enviando y no se puede mandar dos veces.
  // Quedan afuera los que maneja Vue (preventDefault), los de confirmación (confirmForms.js
  // ya los marca) y los que abren otra pestaña. Ningún POST de la app descarga un archivo.
  document.addEventListener('submit', (event) => {
    const form = event.target
    if (!(form instanceof HTMLFormElement) || event.defaultPrevented) return
    if (form.method !== 'post' && !form.matches('[data-submit-feedback]')) return
    if (form.matches('[data-confirm]') || (form.target && form.target !== '_self')) return

    const submitter = event.submitter instanceof HTMLButtonElement || event.submitter instanceof HTMLInputElement
      ? event.submitter
      : form.querySelector('button[type="submit"], button:not([type]), input[type="submit"]')
    if (!submitter || submitter.disabled) return

    form.setAttribute('aria-busy', 'true')
    submitter.classList.add('ui-submitting')
    submitter.disabled = true
    // Sin texto propio se conserva la etiqueta (y su ícono): el aro alcanza.
    const loadingLabel = form.dataset.loadingLabel
    if (!loadingLabel) return
    submitter.dataset.originalFeedbackLabel = submitter.value || submitter.textContent.trim()
    if (submitter instanceof HTMLInputElement) submitter.value = loadingLabel
    else submitter.textContent = loadingLabel
  })

  // Volver con "Atrás" puede traer la página guardada con el botón todavía deshabilitado.
  window.addEventListener('pageshow', (event) => {
    if (!event.persisted) return
    document.querySelectorAll('form[aria-busy="true"]').forEach((form) => form.removeAttribute('aria-busy'))
    document.querySelectorAll('.ui-submitting').forEach((button) => {
      button.classList.remove('ui-submitting')
      button.disabled = false
      const label = button.dataset.originalFeedbackLabel
      if (label === undefined) return
      if (button instanceof HTMLInputElement) button.value = label
      else button.textContent = label
      delete button.dataset.originalFeedbackLabel
    })
  })
}

// Un sacudón corto: dice "acá" sin depender del color. Con movimiento reducido no se mueve.
export function shakeField(field) {
  field.classList.remove('ui-shake')
  void field.offsetWidth // reinicia la animación si ya se estaba sacudiendo
  field.classList.add('ui-shake')
  field.addEventListener('animationend', () => field.classList.remove('ui-shake'), { once: true })
}

// También al cargar: la página que vuelve del servidor con errores en los campos.
export function shakeFirstInvalid(root) {
  const field = root?.querySelector('[aria-invalid="true"]')
  if (field) shakeField(field)
  return field ?? null
}

function clearInvalidState(event) {
  const control = event.target
  if (!isFormControl(control) || !control.hasAttribute('aria-invalid') || !control.checkValidity()) return
  control.removeAttribute('aria-invalid')
}

export function consumeFlash(flash) {
  if (!flash || typeof flash !== 'object') return
  if (typeof flash.success === 'string' && flash.success !== '') rememberSuccessfulSave()
  const alerts = useAlerts()

  for (const key of FLASH_ORDER) {
    const message = flash[key]
    if (typeof message !== 'string' || message === '') continue
    const handler = flashHandlers[key]
    if (handler) {
      handler(alerts, message)
      return
    }
  }
}
