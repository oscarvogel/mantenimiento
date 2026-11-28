import { describe, expect, it } from 'vitest'
import { formatDate, formatDateTime, formatMoney, formatNumber, formatRelativeDays } from './format.js'

// Intl uses non-breaking spaces between symbol and amount.
const plain = (text) => text.replace(/ /g, ' ')

describe('formatMoney', () => {
  it('muestra pesos enteros por defecto', () => {
    expect(plain(formatMoney(1234567.89))).toBe('$ 1.234.568')
  })

  it('muestra centavos en facturas y liquidaciones', () => {
    expect(plain(formatMoney(1234.5, { cents: true }))).toBe('$ 1.234,50')
  })

  it('respeta la moneda del comprobante', () => {
    expect(plain(formatMoney(1500, { currency: 'usd', cents: true }))).toBe('US$ 1.500,00')
  })

  it('no inventa un cero cuando falta el dato', () => {
    expect(formatMoney(null)).toBe('Sin datos')
    expect(formatMoney('', { empty: '—' })).toBe('—')
    expect(plain(formatMoney(0))).toBe('$ 0')
  })
})

describe('formatNumber', () => {
  it('usa punto de miles y coma decimal', () => {
    expect(formatNumber(11010)).toBe('11.010')
    expect(formatNumber(1234.56, 1)).toBe('1.234,6')
    expect(formatNumber(undefined)).toBe('Sin datos')
  })
})

describe('fechas', () => {
  it('muestra día/mes/año sin correrse por la zona horaria', () => {
    expect(formatDate('2026-08-08')).toBe('08/08/2026')
    expect(formatDate('2026-08-08 23:59:00')).toBe('08/08/2026')
    expect(formatDate(new Date(2026, 0, 5))).toBe('05/01/2026')
  })

  it('agrega la hora solo si el dato la trae', () => {
    expect(formatDateTime('2026-08-08 12:00:00')).toBe('08/08/2026 12:00')
    expect(formatDateTime('2026-08-08')).toBe('08/08/2026')
    expect(formatDateTime('2026-08-08 00:00:00')).toBe('08/08/2026')
  })

  it('muestra tal cual lo que no es una fecha, para no esconder datos', () => {
    expect(formatDate('08/08/2026')).toBe('08/08/2026')
    expect(formatDateTime('a confirmar')).toBe('a confirmar')
  })

  it('devuelve un texto claro cuando no hay fecha', () => {
    expect(formatDate(null)).toBe('Sin fecha')
    expect(formatDate(undefined)).toBe('Sin fecha')
    expect(formatDate('', '—')).toBe('—')
  })

  it('cuenta días de calendario hacia atrás y hacia adelante', () => {
    const today = new Date(2026, 10, 25, 23, 30)
    expect(formatRelativeDays('2026-11-25', today)).toBe('hoy')
    expect(formatRelativeDays('2026-11-24 08:00', today)).toBe('ayer')
    expect(formatRelativeDays('2026-11-22', today)).toBe('hace 3 días')
    expect(formatRelativeDays('2026-11-30', today)).toBe('dentro de 5 días')
  })
})
