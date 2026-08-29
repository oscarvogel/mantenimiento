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

export function planLastSummary(plan) {
  const parts = []
  const criteria = plan?.criteria ?? {}
  if (criteria.kilometers?.base !== null && criteria.kilometers?.base !== undefined) parts.push(`${criteria.kilometers.base} km`)
  if (criteria.hours?.base !== null && criteria.hours?.base !== undefined) parts.push(`${criteria.hours.base} h`)
  if (criteria.date?.base) parts.push(String(criteria.date.base))
  return parts.join(' · ') || 'Sin datos'
}

export function planHasLastData(plan) {
  return planLastSummary(plan) !== 'Sin datos'
}

function appendIfPresent(params, key, value) {
  if (value !== null && value !== undefined && value !== '') params.set(key, String(value))
}

export function buildPlanUpdateParams(plan, values, csrf) {
  const params = new URLSearchParams()
  const criteria = plan?.criteria ?? {}

  appendIfPresent(params, 'intervalo_km', criteria.kilometers?.interval)
  appendIfPresent(params, 'intervalo_horas', criteria.hours?.interval)
  appendIfPresent(params, 'intervalo_dias', criteria.date?.interval)
  appendIfPresent(params, 'anticipacion_km', criteria.kilometers?.warning ?? 0)
  appendIfPresent(params, 'anticipacion_horas', criteria.hours?.warning ?? 0)
  appendIfPresent(params, 'anticipacion_dias', criteria.date?.warning ?? 0)

  if (criteria.kilometers) appendIfPresent(params, 'base_km', values.baseKm)
  if (criteria.hours) appendIfPresent(params, 'base_horas', values.baseHours)
  if (criteria.date) appendIfPresent(params, 'base_fecha', values.baseDate)

  params.set('prioridad', plan?.priority || 'MEDIA')
  if (plan?.notes) params.set('observaciones', plan.notes)
  if (csrf?.name && csrf?.hash) params.set(csrf.name, csrf.hash)

  return params
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
    content.className = 'p-5'
    content.innerHTML = `
      <div class="rounded-xl border border-dashed border-border-strong p-6 text-center">
        <p class="font-semibold text-ink">Este equipo todavía no tiene planes preventivos asignados.</p>
        <p class="mt-1 text-sm text-ink-muted">Usá “Agregar planes” para asignar el primero sin salir de la ficha.</p>
      </div>`
    return
  }

  content.className = 'overflow-x-auto'
  content.innerHTML = `
    <table class="ui-table-hover w-full min-w-[68rem] text-left text-sm">
      <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted">
        <tr>
          <th class="px-4 py-3">Servicio</th>
          <th class="px-4 py-3">Frecuencia</th>
          <th class="px-4 py-3">Último</th>
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
            <td class="px-4 py-3">
              <div class="text-sm font-semibold text-ink">${escapeHtml(planLastSummary(plan))}</div>
              ${plan.editUrl ? `<button type="button" data-edit-plan-last="${Number(plan.id)}" class="mt-1 text-xs font-semibold text-primary hover:underline">${planHasLastData(plan) ? 'Editar última realización' : 'Registrar último mantenimiento'}</button>` : ''}
            </td>
            <td class="px-4 py-3 text-sm font-semibold text-ink">${escapeHtml(planNextSummary(plan))}</td>
            <td class="px-4 py-3">${statusBadge(plan.state)}</td>
            <td class="px-4 py-3 text-xs font-semibold text-ink">${escapeHtml(plan.priority === 'CRITICA' ? 'CRÍTICA' : plan.priority || 'MEDIA')}</td>
          </tr>`).join('')}
      </tbody>
    </table>`
}

function createLastMaintenanceModal() {
  let modal = document.querySelector('[data-plan-last-modal]')
  if (modal) return modal

  modal = document.createElement('div')
  modal.dataset.planLastModal = 'true'
  modal.className = 'fixed inset-0 z-50 hidden items-center justify-center bg-black/40 p-4'
  modal.innerHTML = `
    <div class="w-full max-w-lg rounded-2xl bg-white shadow-xl" role="dialog" aria-modal="true" aria-labelledby="plan-last-title">
      <form data-plan-last-form>
        <div class="flex items-start justify-between gap-4 border-b border-border px-5 py-4">
          <div>
            <h3 id="plan-last-title" class="text-lg font-bold text-ink">Registrar última realización</h3>
            <p data-plan-last-service class="mt-1 text-sm text-ink-muted"></p>
          </div>
          <button type="button" data-plan-last-close class="rounded-lg px-2 py-1 text-xl leading-none text-ink-muted hover:bg-surface-muted" aria-label="Cerrar">×</button>
        </div>
        <div class="space-y-4 px-5 py-5">
          <div data-plan-last-km class="hidden">
            <label class="mb-1 block text-sm font-semibold text-ink" for="plan-last-km-input">Kilometraje de la última realización</label>
            <div class="flex items-center gap-2">
              <input id="plan-last-km-input" name="base_km" type="number" min="0" step="1" inputmode="numeric" class="w-full rounded-xl border border-border px-3 py-2 text-sm" placeholder="Ej. 120000">
              <span class="text-sm text-ink-muted">km</span>
            </div>
          </div>
          <div data-plan-last-hours class="hidden">
            <label class="mb-1 block text-sm font-semibold text-ink" for="plan-last-hours-input">Horómetro de la última realización</label>
            <div class="flex items-center gap-2">
              <input id="plan-last-hours-input" name="base_horas" type="number" min="0" step="0.1" inputmode="decimal" class="w-full rounded-xl border border-border px-3 py-2 text-sm" placeholder="Ej. 3450.5">
              <span class="text-sm text-ink-muted">h</span>
            </div>
          </div>
          <div data-plan-last-date class="hidden">
            <label class="mb-1 block text-sm font-semibold text-ink" for="plan-last-date-input">Fecha de la última realización</label>
            <input id="plan-last-date-input" name="base_fecha" type="date" class="w-full rounded-xl border border-border px-3 py-2 text-sm">
          </div>
          <div data-plan-last-feedback class="hidden rounded-xl px-3 py-2 text-sm"></div>
          <p class="text-xs text-ink-muted">Al guardar se recalculan automáticamente el próximo vencimiento y el estado del plan.</p>
        </div>
        <div class="flex justify-end gap-2 border-t border-border px-5 py-4">
          <button type="button" data-plan-last-close class="rounded-xl border border-border px-4 py-2 text-sm font-semibold text-ink">Cancelar</button>
          <button type="submit" data-plan-last-submit class="rounded-xl bg-primary px-4 py-2 text-sm font-semibold text-white">Guardar</button>
        </div>
      </form>
    </div>`

  document.body.appendChild(modal)
  return modal
}

function setModalFeedback(modal, message, isError = false) {
  const feedback = modal.querySelector('[data-plan-last-feedback]')
  if (!feedback) return
  if (!message) {
    feedback.className = 'hidden rounded-xl px-3 py-2 text-sm'
    feedback.textContent = ''
    return
  }
  feedback.textContent = message
  feedback.className = `rounded-xl px-3 py-2 text-sm ${isError ? 'bg-danger-subtle text-danger-strong' : 'bg-success-subtle text-success-strong'}`
}

function openLastMaintenanceModal(modal, plan) {
  modal.dataset.planId = String(plan.id)
  modal.querySelector('[data-plan-last-service]').textContent = plan.serviceName || 'Plan preventivo'
  modal.querySelector('#plan-last-title').textContent = planHasLastData(plan) ? 'Editar última realización' : 'Registrar última realización'

  const criteria = plan.criteria ?? {}
  const kmBlock = modal.querySelector('[data-plan-last-km]')
  const hoursBlock = modal.querySelector('[data-plan-last-hours]')
  const dateBlock = modal.querySelector('[data-plan-last-date]')
  const kmInput = modal.querySelector('#plan-last-km-input')
  const hoursInput = modal.querySelector('#plan-last-hours-input')
  const dateInput = modal.querySelector('#plan-last-date-input')

  kmBlock.classList.toggle('hidden', !criteria.kilometers)
  hoursBlock.classList.toggle('hidden', !criteria.hours)
  dateBlock.classList.toggle('hidden', !criteria.date)
  kmInput.value = criteria.kilometers?.base ?? ''
  hoursInput.value = criteria.hours?.base ?? ''
  dateInput.value = criteria.date?.base ?? ''

  setModalFeedback(modal, '')
  modal.classList.remove('hidden')
  modal.classList.add('flex')
  const firstVisibleInput = [kmInput, hoursInput, dateInput].find((input) => !input.closest('.hidden'))
  firstVisibleInput?.focus()
}

function closeLastMaintenanceModal(modal) {
  modal.classList.add('hidden')
  modal.classList.remove('flex')
  delete modal.dataset.planId
  setModalFeedback(modal, '')
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

  const modal = createLastMaintenanceModal()
  let plans = []
  let csrf = serverPayload?.data?.csrf ?? null
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
      csrf = payload?.data?.csrf ?? csrf
      plans = assignedPlansForEquipment(payload, equipmentId)
      renderPlans(panel, plans)
    } catch {
      renderUnavailable(panel)
    } finally {
      refreshing = false
    }
  }

  panel.addEventListener('click', (event) => {
    const button = event.target.closest('[data-edit-plan-last]')
    if (!button) return
    const plan = plans.find((item) => Number(item.id) === Number(button.dataset.editPlanLast))
    if (!plan?.editUrl) return
    openLastMaintenanceModal(modal, plan)
  })

  modal.addEventListener('click', (event) => {
    if (event.target === modal || event.target.closest('[data-plan-last-close]')) closeLastMaintenanceModal(modal)
  })

  modal.querySelector('[data-plan-last-form]').addEventListener('submit', async (event) => {
    event.preventDefault()
    const plan = plans.find((item) => Number(item.id) === Number(modal.dataset.planId))
    if (!plan?.editUrl) return

    const submit = modal.querySelector('[data-plan-last-submit]')
    submit.disabled = true
    submit.textContent = 'Guardando…'
    setModalFeedback(modal, '')

    try {
      const values = {
        baseKm: modal.querySelector('#plan-last-km-input').value,
        baseHours: modal.querySelector('#plan-last-hours-input').value,
        baseDate: modal.querySelector('#plan-last-date-input').value,
      }
      const response = await fetch(plan.editUrl, {
        method: 'POST',
        headers: {
          Accept: 'text/html',
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
        },
        credentials: 'same-origin',
        body: buildPlanUpdateParams(plan, values, csrf),
      })
      if (!response.ok) throw new Error('No se pudo guardar la última realización.')

      const payload = parseAppPayload(await response.text())
      csrf = payload?.data?.csrf ?? csrf
      const serverError = payload?.data?.flash?.error
      if (serverError) throw new Error(serverError)

      setModalFeedback(modal, 'Última realización guardada correctamente.')
      await refresh()
      window.setTimeout(() => closeLastMaintenanceModal(modal), 450)
    } catch (error) {
      setModalFeedback(modal, error?.message || 'No se pudo guardar la última realización.', true)
    } finally {
      submit.disabled = false
      submit.textContent = 'Guardar'
    }
  })

  refresh()

  const drawerObserver = new MutationObserver(() => {
    const feedback = document.querySelector('[data-quick-plan-feedback]')
    if (!feedback || feedback.classList.contains('hidden')) return
    const text = feedback.textContent?.toLocaleLowerCase('es') ?? ''
    if (text.includes('agregado') || text.includes('asignado')) refresh()
  })
  drawerObserver.observe(document.body, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['class'] })
}
