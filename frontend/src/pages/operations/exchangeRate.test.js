import { describe, expect, it, vi } from 'vitest'
import { calculateArs, fetchHistoricalBcraQuote, selectBcraQuote } from './exchangeRate.js'

describe('historical exchange rate helpers', () => {
  it('calculates the ARS historical equivalent', () => {
    expect(calculateArs('2992.00', '305')).toBe(912560)
  })

  it('selects the latest available quote not after the work date', () => {
    const quote = selectBcraQuote({
      results: [
        { fecha: '2026-08-27', detalle: [{ tipoCotizacion: 304.5 }] },
        { fecha: '2026-08-28', detalle: [] },
        { fecha: '2026-08-26', detalle: [{ tipoCotizacion: 303.8 }] },
      ],
    }, '2026-08-28')

    expect(quote).toEqual({ date: '2026-08-27', rate: 304.5 })
  })

  it('queries a lookback window so weekends and holidays fall back to prior business day', async () => {
    const fetchImpl = vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ results: [{ fecha: '2026-08-28', detalle: [{ tipoCotizacion: 305 }] }] }),
    })

    const quote = await fetchHistoricalBcraQuote('BRL', '2026-08-29', fetchImpl)

    expect(quote).toEqual({ date: '2026-08-28', rate: 305 })
    expect(fetchImpl.mock.calls[0][0]).toContain('fechahasta=2026-08-29')
    expect(fetchImpl.mock.calls[0][0]).toContain('fechadesde=2026-08-19')
  })
})
