function normalizePlanState(state) {
  return String(state ?? '').toUpperCase()
}

function escapeHtml(value) {
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;')
}

function parseAppPayload(html) {
  const document = new DOMParser().parseFromString(html, 'text/html')
  const node = document.getElementById('maintenance-app-data')
  if (!node?.textContent) throw new Error('No se pudo leer la respuesta de planes preventivos.')
  return JSON.parse(node.textContent)
}

export function assignedPlansForEquipment(payload, equipmentId) {
  return (payload?.data?.plans?.items ?? []).filter(
    (plan) => Number(plan?.equipment?.id) === Number(equipmentId),
  )
}

export function planFrequencySummary(plan) {
  const parts = []
  const criteria = plan?.criteria ?? {}
  if (criteria.kilometers?.interval) parts.push(`Cada ${criteria.kilometers.interval} km`)
  if (criteria.hours?.interval) parts.push(`Cada ${criteria.hours.interval} h`)
  if (criteria.date?.interval) parts.push(`Cada ${criteria.date.interval} días`)
  return parts.join(' · ') || 'Sin frecuencia'
}

export function planNextSummary(plan) {
  const parts = []
  const criteria = plan?.criteria ?? {}
  if (criteria.kilometers?.next !== null && criteria.kilometers?.next !== undefined) parts.push(`${criteria.kilometers.next} km`)
  if (criteria.hours?.next !== null && criteria.hours?.next !== undefined) parts.push(`${criteria.hours.next} h`)
  if (criteria.date?.next) parts.push(String(criteria.date.next))
  return parts.join(' · ') || 'Sin datos'
}

function statusBadge(state) {
  const normalized = normalizePlanState(state)
  const labels = {
    AL_DIA: 'AL DÍA',
    PROXIMO: 'PRÓXIMO',
    VENCIDO: 'VENCIDO',
    SIN_DATOS: 'SIN DATOS',
  }
  const classes = {
    AL_DIA: 'bg-success-subtle text-success-strong',
    PROXIMO: 'bg-warning-subtle text-warning-foreground',
    VENCIDO: 'bg-danger-subtle text-danger-strong',
    SIN_DATOS: 'bg-surface-muted text-ink-muted',
  }
  const label = labels[normalized] ?? (normalized || 'SIN DATOS')
  return `<span class="inline-flex rounded-full px-2.5 py-1 text-xs font-bold ${classes[normalized] ?? classes.SIN_DATOS}">${label}</span>`
}

function createPanel(sourceUrl) {
  const maintenancePanel = document.getElementById('equipment-panel-mantenimiento')
  if (!maintenancePanel || maintenancePanel.querySelector('[data-equipment-assigned-plans]')) return null

  const preventiveActions = maintenancePanel.firstElementChild
  const panel = document.createElement('section')
  panel.dataset.equipmentAssignedPlans = 'true'
  panel.className = 'mb-6 overflow-hidden rounded-2xl border border-border bg-white shadow-card'
  panel.innerHTML = `
    <div class="flex flex-col gap-3 border-b border-border px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
      <div>
        <div class="flex items-center gap-2">
          <h2 class="font-bold text-ink">Planes preventivos asignados</h2>
          <span data-equipment-plans-count class="rounded-full bg-surface-muted px-2.5 py-1 text-xs font-bold text-ink-muted">…</span>
        </div>
        <p data-equipment-plans-summary class="mt-1 text-xs text-ink-muted">Cargando planes del equipo…</p>
      </div>
      <a href="${escapeHtml(sourceUrl.split('#')[0])}" class="text-sm font-semibold text-primary hover:underline">Administrar planes →</a>
    </div>
    <div data-equipment-plans-content class="p-5">
      <div class="rounded-xl bg-surface-subtle p-4 text-sm text-ink-muted">Cargando planes asignados…</div>
    </div>`

  if (preventiveActions?.nextSibling) maintenancePanel.insertBefore(panel, preventiveActions.nextSibling)
  else maintenancePanel.appendChild(panel)
  return panel
}

function renderPlans(panel, plans) {
  const count = panel.querySelector('[data-equipment-plans-count]')
  const summary = panel.querySelector('[data-equipment-plans-summary]')
  const content = panel.querySelector('[data-equipment-plans-content]')
  if (!count || !summary || !content) return

  count.textContent = String(plans.length)
  summary.textContent = plans.length === 1 ? '1 plan activo para este equipo' : `${plans.length} planes activos para este equipo`

  if (!plans.length) {
    content.innerHTML = `
      <div class="rounded-xl border border-dashed border-border-strong p-6 text-center">
        <p class="font-semibold text-ink">Este equipo todavía no tiene planes preventivos asignados.</p>
        <p class="mt-1 text-sm text-ink-muted">Usá “Agregar planes” para asignar el primero sin salir de la ficha.</p>
      </div>`
    return
  }

  content.className = 'overflow-x-auto'
  content.innerHTML = `
    <table class="ui-table-hover w-full min-w-[54rem] text-left text-sm">
      <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted">
        <tr>
          <th class="px-4 py-3">Servicio</th>
          <th class="px-4 py-3">Frecuencia</th>
          <th class="px-4 py-3">Próximo</th>
          <th class="px-4 py-3">Estado</th>
          <th class="px-4 py-3">Prioridad</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-border-subtle">
        ${plans.map((plan) => `
          <tr>
            <td class="px-4 py-3">
              <strong class="text-ink">${escapeHtml(plan.serviceName)}</strong>
              ${plan.notes ? `<p class="mt-0.5 max-w-xs truncate text-xs text-ink-muted">${escapeHtml(plan.notes)}</p>` : ''}
            </td>
            <td class="px-4 py-3 text-sm text-ink">${escapeHtml(planFrequencySummary(plan))}</td>
            <td class="px-4 py-3 text-sm font-semibold text-ink">${escapeHtml(planNextSummary(plan))}</td>
            <td class="px-4 py-3">${statusBadge(plan.state)}</td>
            <td class="px-4 py-3 text-xs font-semibold text-ink">${escapeHtml(plan.priority === 'CRITICA' ? 'CRÍTICA' : plan.priority || 'MEDIA')}</td>
          </tr>`).join('')}
      </tbody>
    </table>`
}

function renderUnavailable(panel) {
  const count = panel.querySelector('[data-equipment-plans-count]')
  const summary = panel.querySelector('[data-equipment-plans-summary]')
  const content = panel.querySelector('[data-equipment-plans-content]')
  if (count) count.textContent = '—'
  if (summary) summary.textContent = 'Información preventiva no disponible para este usuario'
  if (content) {
    content.className = 'p-5'
    content.innerHTML = '<div class="rounded-xl bg-surface-subtle p-4 text-sm text-ink-muted">No se pudieron consultar los planes preventivos. La ficha del equipo continúa disponible normalmente.</div>'
  }
}

export function installEquipmentAssignedPlans(root, serverPayload) {
  if (!root || serverPayload?.page !== 'equipment-detail') return

  const equipmentId = Number(serverPayload?.data?.equipment?.id ?? 0)
  const sourceUrl = serverPayload?.data?.routes?.addPlansFromTemplate
  if (!equipmentId || !sourceUrl) return

  const panel = createPanel(sourceUrl)
  if (!panel) return

  let refreshing = false
  const refresh = async () => {
    if (refreshing) return
    refreshing = true
    try {
      const response = await fetch(sourceUrl.split('#')[0], {
        headers: { Accept: 'text/html' },
        credentials: 'same-origin',
      })
      if (!response.ok) throw new Error('No se pudieron consultar los planes.')
      const payload = parseAppPayload(await response.text())
      renderPlans(panel, assignedPlansForEquipment(payload, equipmentId))
    } catch {
      renderUnavailable(panel)
    } finally {
      refreshing = false
    }
  }

  refresh()

  const drawerObserver = new MutationObserver(() => {
    const feedback = document.querySelector('[data-quick-plan-feedback]')
    if (!feedback || feedback.classList.contains('hidden')) return
    const text = feedback.textContent?.toLocaleLowerCase('es') ?? ''
    if (text.includes('agregado') || text.includes('asignado')) refresh()
  })
  drawerObserver.observe(document.body, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['class'] })
}
