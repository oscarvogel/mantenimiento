import { formatDate, formatNumber } from '../../ui/format.js'
// Moved to src/ui/buttons.js; re-exported so existing imports keep working.
export * from '../../ui/buttons.js'

export const localDateValue = (date) => {
  const year = String(date.getFullYear()).padStart(4, '0')
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')

  return `${year}-${month}-${day}`
}

export const today = () => localDateValue(new Date())

export const formatDateDmy = (value) => formatDate(value, '')

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

export const formatNumberEs = (value, maximumFractionDigits = 1) => formatNumber(value, maximumFractionDigits, 'sin datos')

export const formatKilometers = (value) => value === null || value === undefined || value === '' ? 'sin datos' : `${formatNumberEs(value, 0)} km`
export const formatHours = (value) => value === null || value === undefined || value === '' ? 'sin datos' : `${formatNumberEs(value, 1)} h`

// Importes con su moneda. El formato es el de la cuenta corriente: la moneda
// delante y dos decimales, con el separador de miles que usa es-AR.
export const formatMoney = (value, currency = 'ARS') => {
  const number = Number(value ?? 0)
  if (!Number.isFinite(number)) return `${currency} 0,00`

  return `${currency} ${number.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

// Cotizaciones: hasta seis decimales, que es la escala del catálogo.
export const formatRate = (value) => {
  const number = Number(value ?? 0)
  if (!Number.isFinite(number)) return '—'

  return number.toLocaleString('es-AR', { minimumFractionDigits: 0, maximumFractionDigits: 6 })
}

export const rateSourceLabel = (source) => ({
  CATALOGO_EMPRESA: 'catálogo de la empresa',
  MANUAL: 'informada',
  PESOS: 'pesos',
}[String(source ?? '').toUpperCase()] ?? (source || '—'))

export const formatReadingOrigin = (origin) => ({
  CARGA_RAPIDA: 'Carga rápida',
  ORDEN_TRABAJO: 'Cierre de orden',
  ALTA_INICIAL: 'Lectura inicial',
  IMPORTACION: 'Importación',
  MANUAL: 'Carga manual',
  QR_ANONIMO: 'QR anónimo',
  VIAJE: 'Cierre de viaje',
  NEUMATICO: 'Colocación de neumático',
  DEMO: 'Demo',
}[String(origin ?? '').toUpperCase()] ?? (origin || 'Carga manual'))

export const readingDelta = (current, next, parser = parseFlexibleNumber) => {
  const currentNumber = parser(current)
  const nextNumber = parser(next)
  if (currentNumber === null || nextNumber === null) return null
  return nextNumber - currentNumber
}

export const kilometersDelta = (current, next) => readingDelta(current, next, parseKilometers)

// El recorrido del viaje sale de sus tramos, que son obligatorios. Las
// paradas son el itinerario fino y se registran aparte.
export const tripRouteLabel = (trip) => {
  const origin = String(trip?.origen ?? '').trim()
  const destination = String(trip?.destino ?? '').trim()
  if (origin === '' && destination === '') return 'Sin recorrido'

  return `${origin || 'Sin origen'} → ${destination || 'Sin destino'}`
}

export const tripRouteCounts = (trip) => {
  const legs = Number(trip?.tramos ?? 0)
  const stops = Number(trip?.paradas ?? 0)
  const parts = []
  if (legs > 0) parts.push(`${legs} ${legs === 1 ? 'tramo' : 'tramos'}`)
  if (stops > 0) parts.push(`${stops} ${stops === 1 ? 'parada' : 'paradas'}`)

  return parts.length > 0 ? parts.join(' · ') : 'Sin tramos'
}
