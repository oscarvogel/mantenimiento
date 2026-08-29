import { useAlerts } from '../composables/useAlerts.js'

function attributesFrom(form, submitter) {
  const source = submitter instanceof HTMLElement ? submitter.dataset : {}

  return {
    title: source.confirmTitle ?? form.dataset.confirmTitle,
    text: source.confirmText ?? form.dataset.confirmText,
    button: source.confirmButton ?? form.dataset.confirmButton,
    cancel: source.confirmCancel ?? form.dataset.confirmCancel,
    icon: source.confirmIcon ?? form.dataset.confirmIcon,
    danger: source.confirmDanger !== undefined
      ? source.confirmDanger === 'true' || source.confirmDanger === '1'
      : form.dataset.confirmDanger === 'true' || form.dataset.confirmDanger === '1',
  }
}

function findSubmitButton(form) {
  return form.querySelector('button[type="submit"]')
}

function markSubmitting(form, submitter) {
  const submit = submitter instanceof HTMLButtonElement ? submitter : findSubmitButton(form)
  if (!submit || submit.disabled) return

  const original = submit.value || submit.textContent.trim()
  submit.dataset.originalLabel = original
  submit.dataset.originalDisabled = String(submit.disabled)
  submit.disabled = true
  submit.textContent = 'Guardando…'
}

function restoreSubmitButton(form, submitter) {
  const submit = submitter instanceof HTMLButtonElement ? submitter : findSubmitButton(form)
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
    const submitter = event.submitter instanceof HTMLElement ? event.submitter : null

    if (form.dataset.confirmed === 'true') {
      // Segundo submit: la confirmación ya fue aceptada. No volver a mostrar
      // SweetAlert; activar el estado de guardado y dejar avanzar el submit nativo.
      markSubmitting(form, submitter)
      return
    }

    event.preventDefault()

    confirm(attributesFrom(form, submitter)).then((accepted) => {
      if (!accepted) return

      form.dataset.confirmed = 'true'
      // Preservar el submitter mantiene formaction/formmethod en formularios
      // con más de una acción (por ejemplo Guardar y Enviar prueba).
      try {
        if (submitter instanceof HTMLButtonElement || submitter instanceof HTMLInputElement) {
          form.requestSubmit(submitter)
        } else {
          form.requestSubmit()
        }
      } catch {
        delete form.dataset.confirmed
        restoreSubmitButton(form, submitter)
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
      restoreSubmitButton(form, null)
    },
    true,
  )
}
