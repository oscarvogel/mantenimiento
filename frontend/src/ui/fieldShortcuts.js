// Field shortcuts the spec asks for (§6.1, §6.2): "Mi trabajo" and "Informar falla".
// Built only from items the server already sent, so no permission is invented here:
// no orders item, no "Mi trabajo"; no requests item, no "Informar falla".

export const MY_WORK_VIEW = 'mi-trabajo'
export const REPORT_FAILURE_ACTION = 'falla'

const withQuery = (href, params) => {
  const [path, query = ''] = String(href).split('?')
  const search = new URLSearchParams(query)
  Object.entries(params).forEach(([key, value]) => search.set(key, String(value)))
  return `${path}?${search.toString()}`
}

export const isMyWorkView = (search) => new URLSearchParams(search).get('vista') === MY_WORK_VIEW
export const isReportFailureMode = (search) => new URLSearchParams(search).get('accion') === REPORT_FAILURE_ACTION

export const withFieldShortcuts = (navigation, user, search = '') => {
  const orders = navigation.find((item) => item.key === 'work-orders' && item.href && !item.disabled)
  const requests = navigation.find((item) => item.key === 'work-requests')
  const readings = navigation.find((item) => item.key === 'quick-readings' && item.href && !item.disabled)
  const myWorkActive = Boolean(orders?.active) && isMyWorkView(search)
  const failureActive = Boolean(readings?.active) && isReportFailureMode(search)

  const extra = []
  if (orders && user?.id) {
    extra.push({
      key: 'my-work',
      label: 'Mi trabajo',
      icon: 'my-work',
      href: withQuery(orders.href, { responsable_id: user.id, vista: MY_WORK_VIEW }),
      active: myWorkActive,
    })
  }
  // The incident form lives on the equipment's field page, reached by picking a unit.
  if (requests && readings) {
    extra.push({
      key: 'report-failure',
      label: 'Informar falla',
      icon: 'report-failure',
      href: withQuery(readings.href, { accion: REPORT_FAILURE_ACTION }),
      active: failureActive,
    })
  }

  return [
    ...navigation.map((item) => {
      if (item.key === 'work-orders' && myWorkActive) return { ...item, active: false }
      if (item.key === 'quick-readings' && failureActive) return { ...item, active: false }
      return item
    }),
    ...extra,
  ]
}

// "Mi trabajo" sections, in the order a technician works through them.
const sections = [
  { title: 'En curso', statuses: ['EN_PROCESO'] },
  { title: 'Para empezar', statuses: ['EMITIDA', 'BORRADOR', 'PENDIENTE'] },
  { title: 'En espera de repuestos', statuses: ['ESPERA_REPUESTOS', 'EN_ESPERA_REPUESTOS'] },
]

export const groupMyWork = (orders) => {
  const known = new Set(sections.flatMap((section) => section.statuses))
  return [
    ...sections.map((section) => ({ title: section.title, orders: orders.filter((order) => section.statuses.includes(order.status)) })),
    { title: 'Terminadas', orders: orders.filter((order) => !known.has(order.status)) },
  ].filter((section) => section.orders.length > 0)
}
