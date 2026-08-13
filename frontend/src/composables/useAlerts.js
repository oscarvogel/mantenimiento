import Swal from 'sweetalert2'

const reducedMotion = () =>
  typeof window !== 'undefined' &&
  typeof window.matchMedia === 'function' &&
  window.matchMedia('(prefers-reduced-motion: reduce)').matches

const alertDefaults = {
  customClass: {
    popup: 'ui-swal-popup',
    title: 'ui-swal-title',
    htmlContainer: 'ui-swal-html',
    actions: 'ui-swal-actions',
    confirmButton: 'ui-swal-confirm',
    cancelButton: 'ui-swal-cancel',
    denounceButton: 'ui-swal-confirm',
  },
  confirmButtonColor: '#0862C6',
  cancelButtonColor: '#F0F3F7',
  buttonsStyling: false,
  reverseButtons: true,
  width: 440,
  padding: '1.5rem',
  background: '#FEFEFE',
  showCloseButton: true,
  focusConfirm: true,
  allowOutsideClick: () => !Swal.isLoading(),
  allowEscapeKey: true,
}

const reduceAnimations = (options, fallback) => ({
  ...options,
  showClass: { popup: '' },
  hideClass: { popup: '' },
  ...(fallback && { timer: undefined, timerProgressBar: false }),
})

const alert = (icon, title, text, options = {}) =>
  Swal.fire({
    icon,
    title,
    text: text || undefined,
    ...alertDefaults,
    ...(reducedMotion() ? reduceAnimations(options, icon === 'success') : options),
  })

export function useAlerts() {
  return {
    success(title, text) {
      return alert('success', title, text, { timer: 2600, timerProgressBar: false })
    },
    error(title, text) {
      return alert('error', title, text, { timer: undefined })
    },
    warning(title, text) {
      return alert('warning', title, text, { timer: undefined })
    },
    info(title, text) {
      return alert('info', title, text, { timer: 4000, timerProgressBar: false })
    },
    denied(title, text) {
      return alert('error', title, text, { timer: undefined })
    },
    async confirm(options = {}) {
      const title = options.title ?? '¿Confirmar acción?'
      const text = options.text ?? ''
      const confirmButtonText = options.button ?? 'Confirmar'
      const cancelButtonText = options.cancel ?? 'Cancelar'
      const danger = options.danger === true
      const result = await Swal.fire({
        title,
        text: text || undefined,
        icon: options.icon ?? 'warning',
        showCancelButton: true,
        confirmButtonText,
        cancelButtonText,
        reverseButtons: true,
        buttonsStyling: false,
        customClass: {
          popup: 'ui-swal-popup',
          title: 'ui-swal-title',
          htmlContainer: 'ui-swal-html',
          actions: 'ui-swal-actions',
          confirmButton: danger ? 'ui-swal-confirm ui-swal-danger' : 'ui-swal-confirm',
          cancelButton: 'ui-swal-cancel',
        },
        confirmButtonColor: danger ? '#D63C3C' : '#0862C6',
        cancelButtonColor: '#F0F3F7',
        width: 440,
        padding: '1.5rem',
        background: '#FEFEFE',
        showCloseButton: true,
        focusConfirm: true,
        allowOutsideClick: () => !Swal.isLoading(),
        allowEscapeKey: true,
        ...(reducedMotion() && { showClass: { popup: '' }, hideClass: { popup: '' } }),
      })
      return result.isConfirmed === true
    },
  }
}