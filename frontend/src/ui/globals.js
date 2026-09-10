import { installConfirmForms } from './confirmForms.js'
import { useAlerts } from '../composables/useAlerts.js'

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
      if (control.isConnected && !control.disabled) control.focus()
    })
  }, true)

  document.addEventListener('input', clearInvalidState)
  document.addEventListener('change', clearInvalidState)

  document.addEventListener('submit', (event) => {
    const form = event.target
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-submit-feedback]')) return

    const submitter = event.submitter instanceof HTMLButtonElement || event.submitter instanceof HTMLInputElement
      ? event.submitter
      : form.querySelector('button[type="submit"], input[type="submit"]')
    if (!submitter || submitter.disabled) return

    form.setAttribute('aria-busy', 'true')
    submitter.dataset.originalFeedbackLabel = submitter.value || submitter.textContent.trim()
    submitter.disabled = true
    const loadingLabel = form.dataset.loadingLabel || 'Procesando…'
    if ('value' in submitter && submitter instanceof HTMLInputElement) submitter.value = loadingLabel
    else submitter.textContent = loadingLabel
  })
}

function clearInvalidState(event) {
  const control = event.target
  if (!isFormControl(control) || !control.hasAttribute('aria-invalid') || !control.checkValidity()) return
  control.removeAttribute('aria-invalid')
}

export function consumeFlash(flash) {
  if (!flash || typeof flash !== 'object') return
  const alerts = useAlerts()

  for (const key of FLASH_ORDER) {
    const message = flash[key]
    if (typeof message !== 'string' || message === '') continue
    const handler = flashHandlers[key]
    if (handler) handler(alerts, message)
  }
}
