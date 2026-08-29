const normalizeHref = (value) => {
  try { return new URL(value, window.location.origin).href } catch { return String(value || '') }
}

const readingUrl = (base, equipment) => {
  const url = new URL(base, window.location.origin)
  url.searchParams.set('q', equipment.code || equipment.plate || String(equipment.id))
  return url.toString()
}

const action = (href, compact = false) => {
  const link = document.createElement('a')
  link.href = href
  link.dataset.contextualReadingAction = 'true'
  link.textContent = 'Registrar km/horas'
  link.className = compact
    ? 'inline-flex min-h-9 items-center justify-center rounded-lg border border-primary px-3 py-1.5 text-xs font-semibold text-primary hover:bg-primary-subtle'
    : 'inline-flex min-h-11 items-center justify-center rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground shadow-sm hover:bg-primary-hover'
  return link
}

const readingBaseFromPayload = (payload) => {
  const navigation = payload?.navigation || []
  return navigation.find((item) => item.key === 'quick-readings')?.href || null
}

const installAssetsIndexActions = (root, payload, base) => {
  for (const equipment of payload?.data?.equipment?.items || []) {
    if (equipment.status !== 'ACTIVO') continue
    const detail = [...root.querySelectorAll('a[href]')].filter((anchor) => normalizeHref(anchor.href) === normalizeHref(equipment.detailUrl))
    for (const anchor of detail) {
      const container = anchor.parentElement
      if (!container || container.querySelector('[data-contextual-reading-action="true"]')) continue
      container.insertBefore(action(readingUrl(base, equipment), true), anchor)
    }
  }
}

const installEquipmentDetailAction = (root, payload, base) => {
  const equipment = payload?.data?.equipment
  if (!equipment || equipment.status !== 'ACTIVO') return
  const heading = root.querySelector('h1')
  const header = heading?.closest('header') || heading?.parentElement
  if (!header || header.querySelector('[data-contextual-reading-action="true"]')) return
  const wrapper = document.createElement('div')
  wrapper.className = 'mt-3 flex flex-wrap gap-2'
  wrapper.dataset.contextualReadingWrapper = 'true'
  wrapper.appendChild(action(readingUrl(base, equipment)))
  header.appendChild(wrapper)
}

export function installContextualReadingActions(root, payload) {
  if (!root || !payload) return
  const base = readingBaseFromPayload(payload)
  if (!base) return
  if (payload.page === 'assets-index') installAssetsIndexActions(root, payload, base)
  if (payload.page === 'equipment-detail') installEquipmentDetailAction(root, payload, base)
}
