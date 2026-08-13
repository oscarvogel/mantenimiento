import { useAlerts } from '../composables/useAlerts.js'

function attributesFrom(form) {
  return {
    title: form.dataset.confirmTitle,
    text: form.dataset.confirmText,
    button: form.dataset.confirmButton,
    cancel: form.dataset.confirmCancel,
    icon: form.dataset.confirmIcon,
    danger: form.dataset.confirmDanger === 'true' || form.dataset.confirmDanger === '1',
  }
}

function findSubmitButton(form) {
  return form.querySelector('button[type="submit"]')
}

function markSubmitting(form) {
  const submit = findSubmitButton(form)
  if (!submit || submit.disabled) return

  const original = submit.value || submit.textContent.trim()
  submit.dataset.originalLabel = original
  submit.dataset.originalDisabled = String(submit.disabled)
  submit.disabled = true
  submit.textContent = 'Guardando…'
}

function restoreSubmitButton(form) {
  const submit = findSubmitButton(form)
  if (!submit) return

  if (submit.dataset.originalLabel) {
    submit.textContent = submit.dataset.originalLabel
    submit.disabled = submit.dataset.originalDisabled === 'true'
    delete submit.dataset.originalLabel
    delete submit.dataset.originalDisabled
  }
}

export function installConfirmForms() {
  const { confirm } = useAlerts()

  document.addEventListener('submit', (event) => {
    const target = event.target
    if (!(target instanceof HTMLFormElement)) return

    const form = target.closest('form[data-confirm]')
    if (!form) return

    if (form.dataset.confirmed === 'true') {
      // Segundo submit: la confirmación ya fue aceptada. No volver a mostrar
      // SweetAlert; activar el estado de guardado y dejar avanzar el submit nativo.
      markSubmitting(form)
      return
    }

    event.preventDefault()

    confirm(attributesFrom(form)).then((accepted) => {
      if (!accepted) return

      form.dataset.confirmed = 'true'
      // requestSubmit() corre la validación HTML primero; si falla, no dispara
      // el evento submit y el botón no queda bloqueado (se restaura en invalid).
      try {
        form.requestSubmit()
      } catch {
        delete form.dataset.confirmed
        restoreSubmitButton(form)
      }
    })
  })

  // invalid no burbujea; en captura para restaurar el form confirmado cuando la
  // validación HTML bloquea el envío, evitando botones bloqueados.
  document.addEventListener(
    'invalid',
    (event) => {
      const control = event.target
      const form = control instanceof Element ? control.closest('form[data-confirm]') : null
      if (!form) return
      delete form.dataset.confirmed
      restoreSubmitButton(form)
    },
    true,
  )
}