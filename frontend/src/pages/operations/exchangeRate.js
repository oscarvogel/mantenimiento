export const isForeignCurrency = (currency) => String(currency || 'ARS').trim().toUpperCase() !== 'ARS'

export const calculateArs = (amount, rate) => {
  const parsedAmount = Number(String(amount ?? '').replace(',', '.'))
  const parsedRate = Number(String(rate ?? '').replace(',', '.'))
  if (!Number.isFinite(parsedAmount) || parsedAmount < 0 || !Number.isFinite(parsedRate) || parsedRate <= 0) return null
  return Math.round((parsedAmount * parsedRate + Number.EPSILON) * 100) / 100
}

const normalizeDate = (value) => String(value || '').slice(0, 10)

export const selectBcraQuote = (payload, targetDate) => {
  const rows = Array.isArray(payload?.results) ? payload.results : []
  const candidates = []
  for (const row of rows) {
    const date = normalizeDate(row?.fecha || row?.date)
    const details = Array.isArray(row?.detalle) ? row.detalle : []
    for (const detail of details) {
      const rate = Number(detail?.tipoCotizacion ?? detail?.tipo_cotizacion ?? detail?.cotizacion)
      if (date && date <= targetDate && Number.isFinite(rate) && rate > 0) candidates.push({ date, rate })
    }
  }
  candidates.sort((a, b) => b.date.localeCompare(a.date))
  return candidates[0] ?? null
}

export const fetchHistoricalBcraQuote = async (currency, targetDate, fetchImpl = fetch) => {
  const normalizedCurrency = String(currency || '').trim().toUpperCase()
  if (!/^[A-Z]{3}$/.test(normalizedCurrency) || normalizedCurrency === 'ARS') return null
  if (!/^\d{4}-\d{2}-\d{2}$/.test(targetDate || '')) throw new Error('Seleccioná una fecha de trabajo válida.')

  const from = new Date(`${targetDate}T12:00:00`)
  from.setDate(from.getDate() - 10)
  const fromDate = from.toISOString().slice(0, 10)
  const url = `https://api.bcra.gob.ar/estadisticascambiarias/v1.0/Cotizaciones/${encodeURIComponent(normalizedCurrency)}?fechadesde=${fromDate}&fechahasta=${targetDate}&limit=100`
  const response = await fetchImpl(url, { headers: { Accept: 'application/json' } })
  if (!response.ok) throw new Error('El BCRA no devolvió una cotización válida.')
  const quote = selectBcraQuote(await response.json(), targetDate)
  if (!quote) throw new Error('No se encontró una cotización BCRA para esa fecha ni para los días hábiles anteriores.')
  return quote
}
