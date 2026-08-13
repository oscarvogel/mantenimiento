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