export const fieldClass = 'block min-h-11 w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-ink shadow-sm placeholder:text-ink-subtle focus:border-border-focus focus:outline-none focus:ring-2 focus:ring-primary/15 disabled:cursor-not-allowed disabled:bg-surface-muted disabled:text-ink-subtle'

export const primaryButton = 'ui-interactive inline-flex min-h-11 items-center justify-center rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground shadow-sm hover:bg-primary-hover active:bg-primary-active disabled:cursor-not-allowed disabled:opacity-50'

export const secondaryButton = 'ui-interactive inline-flex min-h-10 items-center justify-center rounded-lg border border-border-strong bg-white px-3.5 py-2 text-sm font-semibold text-ink hover:bg-surface-muted disabled:cursor-not-allowed disabled:opacity-50'

export const dangerButton = 'ui-interactive inline-flex min-h-10 items-center justify-center rounded-lg border border-danger/30 bg-white px-3.5 py-2 text-sm font-semibold text-danger-strong hover:bg-danger-subtle disabled:cursor-not-allowed disabled:opacity-50'

export const localDateValue = (date) => {
  const year = String(date.getFullYear()).padStart(4, '0')
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')

  return `${year}-${month}-${day}`
}

export const today = () => localDateValue(new Date())

export const nowLocal = () => {
  const date = new Date(Date.now() - new Date().getTimezoneOffset() * 60_000)
  return date.toISOString().slice(0, 16)
}

export const parseFlexibleNumber = (value) => {
  if (value === null || value === undefined || String(value).trim() === '') return null
  const raw = String(value).trim()
  if (!/^\d+(?:[.,]\d)?$/.test(raw)) return null
  const normalized = raw.replace(',', '.')
  const parsed = Number(normalized)
  return Number.isFinite(parsed) ? parsed : null
}

// El kilometraje representa unidades enteras. No reutilizar el parser decimal
// del horómetro: aceptar una coma o un punto aquí produciría un valor que el
// backend no puede persistir.
export const parseKilometers = (value) => {
  if (value === null || value === undefined || String(value).trim() === '') return null
  const raw = String(value).trim()
  if (!/^\d+$/.test(raw)) return null
  const parsed = Number(raw)
  return Number.isSafeInteger(parsed) ? parsed : null
}

export const normalizeDecimalInput = (value) => {
  const raw = String(value ?? '').trim()
  return /^\d+(?:[.,]\d)?$/.test(raw) ? raw.replace(',', '.') : raw
}

export const formatNumberEs = (value, maximumFractionDigits = 1) => {
  if (value === null || value === undefined || value === '') return 'sin datos'
  const number = Number(value)
  if (!Number.isFinite(number)) return 'sin datos'
  return new Intl.NumberFormat('es-AR', { maximumFractionDigits, minimumFractionDigits: 0 }).format(number)
}

export const formatKilometers = (value) => value === null || value === undefined || value === '' ? 'sin datos' : `${formatNumberEs(value, 0)} km`
export const formatHours = (value) => value === null || value === undefined || value === '' ? 'sin datos' : `${formatNumberEs(value, 1)} h`

export const formatReadingOrigin = (origin) => ({
  CARGA_RAPIDA: 'Carga rápida',
  ORDEN_TRABAJO: 'Cierre de orden',
  ALTA_INICIAL: 'Lectura inicial',
  IMPORTACION: 'Importación',
  MANUAL: 'Carga manual',
  QR_ANONIMO: 'QR anónimo',
  DEMO: 'Demo',
}[String(origin ?? '').toUpperCase()] ?? (origin || 'Carga manual'))

export const readingDelta = (current, next, parser = parseFlexibleNumber) => {
  const currentNumber = parser(current)
  const nextNumber = parser(next)
  if (currentNumber === null || nextNumber === null) return null
  return nextNumber - currentNumber
}

export const kilometersDelta = (current, next) => readingDelta(current, next, parseKilometers)
