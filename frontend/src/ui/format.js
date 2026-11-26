// The only place where amounts, numbers and dates become text (docs/DESIGN_TOKENS.md).
// Dashboards and lists show whole pesos; invoices and settlements pass `cents: true`.

const LOCALE = 'es-AR'
const NO_DATA = 'Sin datos'
const NO_DATE = 'Sin fecha'

const isBlank = (value) => value === null || value === undefined || value === ''

const toNumber = (value) => {
  if (isBlank(value)) return null
  const number = Number(value)
  return Number.isFinite(number) ? number : null
}

export const formatNumber = (value, maximumFractionDigits = 0, empty = NO_DATA) => {
  const number = toNumber(value)
  return number === null ? empty : new Intl.NumberFormat(LOCALE, { maximumFractionDigits }).format(number)
}

export const formatMoney = (value, { currency = 'ARS', cents = false, empty = NO_DATA } = {}) => {
  const number = toNumber(value)
  if (number === null) return empty
  const digits = cents ? 2 : 0
  return new Intl.NumberFormat(LOCALE, {
    style: 'currency',
    currency: String(currency || 'ARS').toUpperCase(),
    minimumFractionDigits: digits,
    maximumFractionDigits: digits,
  }).format(number)
}

// Accepts 'YYYY-MM-DD', 'YYYY-MM-DD HH:MM[:SS]', ISO strings or Date. Date-only strings
// are read as local days: `new Date('2026-08-08')` would be UTC and show the 7th here.
const parts = (value) => {
  if (value instanceof Date) {
    return Number.isNaN(value.getTime())
      ? null
      : { y: value.getFullYear(), m: value.getMonth() + 1, d: value.getDate(), h: value.getHours(), i: value.getMinutes(), hasTime: true }
  }
  const match = /^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}))?/.exec(String(value ?? '').trim())
  if (!match) return null
  return { y: +match[1], m: +match[2], d: +match[3], h: +(match[4] ?? 0), i: +(match[5] ?? 0), hasTime: match[4] !== undefined }
}

const pad = (n) => String(n).padStart(2, '0')

// `empty` only when there is no value: text the parser does not know (a date the backend
// already formatted, a note) is shown as it came instead of hiding real data.
const fallback = (value, empty) => {
  const text = value instanceof Date ? '' : String(value ?? '').trim()
  return text === '' ? empty : text
}

export const formatDate = (value, empty = NO_DATE) => {
  const p = parts(value)
  return p ? `${pad(p.d)}/${pad(p.m)}/${p.y}` : fallback(value, empty)
}

// Midnight is how the backend stores a date without time: showing "00:00" adds noise.
export const formatDateTime = (value, empty = NO_DATE) => {
  const p = parts(value)
  if (!p) return fallback(value, empty)
  const midnight = p.h === 0 && p.i === 0
  return p.hasTime && !midnight ? `${pad(p.d)}/${pad(p.m)}/${p.y} ${pad(p.h)}:${pad(p.i)}` : formatDate(value, empty)
}

const relative = new Intl.RelativeTimeFormat(LOCALE, { numeric: 'auto' })

// "hoy", "ayer", "hace 3 días", "mañana", "dentro de 5 días". Counts calendar days.
export const formatRelativeDays = (value, today = new Date(), empty = NO_DATE) => {
  const p = parts(value)
  if (!p) return empty
  const days = Math.round(
    (Date.UTC(p.y, p.m - 1, p.d) - Date.UTC(today.getFullYear(), today.getMonth(), today.getDate())) / 86_400_000,
  )
  return relative.format(days, 'day')
}
