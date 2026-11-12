/**
 * Moneda y cotizacion de los dos fletes de un tramo del viaje.
 *
 * El flete de liquidacion se puede cargar en una moneda distinta al de
 * facturacion: en pesos no hace falta convertir nada. Si el operador no la
 * cambia, la liquidacion sigue a la facturacion y comparte su cotizacion, que
 * es como se cargo siempre.
 *
 * Todos los objetos que usan estos helpers tienen la misma forma:
 * { moneda, cotizacion, moneda_liquidacion, cotizacion_liquidacion }
 */

/** Moneda del flete de liquidacion. */
export const liquidationCurrency = (leg) => leg.moneda_liquidacion || leg.moneda || 'ARS'

/** La liquidacion sigue a la facturacion y comparte su cotizacion. */
export const liquidationFollowsInvoice = (leg) => Boolean(leg.liquidacion_segue_facturacion)

/** La cotizacion de liquidacion no se ingresa a mano. */
export const liquidationRateDisabled = (leg) => liquidationCurrency(leg) === 'ARS' || liquidationFollowsInvoice(leg)

/** Cotizacion que se muestra y se usa para el flete de liquidacion. */
export const effectiveLiquidationRate = (leg) => {
  if (liquidationCurrency(leg) === 'ARS') return '1'
  if (liquidationFollowsInvoice(leg)) return leg.cotizacion ?? ''

  return leg.cotizacion_liquidacion ?? ''
}

/** El operador cambio la moneda del flete de facturacion. */
export const onInvoiceCurrencyChange = (leg) => {
  leg.cotizacion = leg.moneda === 'ARS' ? '1' : ''
  // Si la liquidacion venia siguiendo a la facturacion, la sigue.
  if (liquidationFollowsInvoice(leg)) {
    leg.moneda_liquidacion = leg.moneda
    leg.cotizacion_liquidacion = ''
  }
}

/** El operador cambio la moneda del flete de liquidacion. */
export const onLiquidationCurrencyChange = (leg) => {
  leg.liquidacion_segue_facturacion = liquidationCurrency(leg) === (leg.moneda || 'ARS')
  leg.cotizacion_liquidacion = ''
}

/** El operador eligio cargar el flete de liquidacion en pesos. */
export const onLiquidationInPesos = (leg) => {
  leg.moneda_liquidacion = 'ARS'
  leg.liquidacion_segue_facturacion = false
  leg.cotizacion_liquidacion = ''
}

/** Datos de un tramo guardado: la liquidacion arranca siguiendo a la facturacion. */
export const legMoneyFromServer = (leg) => {
  const moneda = leg.moneda || 'ARS'

  return {
    moneda,
    cotizacion: leg.cotizacion ?? '1',
    moneda_liquidacion: leg.moneda_liquidacion || moneda,
    cotizacion_liquidacion: leg.cotizacion_liquidacion ?? '',
    liquidacion_segue_facturacion: (leg.moneda_liquidacion || moneda) === moneda,
  }
}
