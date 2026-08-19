const normalizeText = (value) => String(value ?? '').trim().toLocaleUpperCase('es')

// Helpers legacy mantenidos mientras se retiran los últimos consumidores de plantillas.
export function templateSpecificity(item) {
  if (item.model) return 4
  if (item.brand && item.equipmentTypeId) return 3
  if (item.equipmentTypeId) return 2
  return 1
}

export function compatibleTemplates(equipment, templateDefaults) {
  if (!equipment) return []
  const assigned = new Set((equipment.assignedServiceTypeIds ?? []).map(Number))
  const seen = new Set(assigned)
  return [...(templateDefaults ?? [])]
    .filter((item) =>
      (!item.equipmentTypeId || Number(item.equipmentTypeId) === Number(equipment.typeId))
      && (!item.brand || normalizeText(item.brand) === normalizeText(equipment.brandName))
      && (!item.model || normalizeText(item.model) === normalizeText(equipment.modelName)),
    )
    .sort((left, right) => templateSpecificity(right) - templateSpecificity(left)
      || Number(left.templateId) - Number(right.templateId)
      || Number(left.id) - Number(right.id))
    .filter((item) => {
      const serviceTypeId = Number(item.serviceTypeId)
      if (seen.has(serviceTypeId)) return false
      seen.add(serviceTypeId)
      return true
    })
}

export function matchesTemplateQuery(item, query) {
  const needle = normalizeText(query)
  if (!needle) return true
  return normalizeText([
    item.serviceName,
    item.templateName,
    item.notes,
    item.brand,
    item.model,
    item.equipmentTypeName,
  ].filter(Boolean).join(' ')).includes(needle)
}

export function assignmentContextFromUrl(href, baseUrl = 'http://localhost/') {
  try {
    const url = new URL(href, baseUrl)
    const equipmentId = Number(url.searchParams.get('equipo_id') ?? 0)
    if (!equipmentId || !url.pathname.includes('/planes')) return null
    return { equipmentId, sourceUrl: url.toString() }
  } catch {
    return null
  }
}

export function assignableServices(equipment, services) {
  if (!equipment) return []
  const assigned = new Set((equipment.assignedServiceTypeIds ?? []).map(Number))
  return [...(services ?? [])].filter((service) => {
    if (assigned.has(Number(service.id))) return false
    if (service.intervalKm && !equipment.controlsKm) return false
    if (service.intervalHours && !equipment.controlsHours) return false
    return true
  })
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
  if (!node?.textContent) throw new Error('No se pudo leer la respuesta del servidor.')
  return JSON.parse(node.textContent)
}

function intervalSummary(item) {
  const parts = []
  if (item.intervalKm) parts.push(`cada ${Number(item.intervalKm).toLocaleString('es-AR')} km`)
  if (item.intervalHours) parts.push(`cada ${item.intervalHours} h`)
  if (item.intervalDays) parts.push(`cada ${item.intervalDays} días`)
  return parts.join(' · ') || 'Sin frecuencia'
}

function warningSummary(item) {
  const parts = []
  if (item.warningKm) parts.push(`${Number(item.warningKm).toLocaleString('es-AR')} km`)
  if (item.warningHours) parts.push(`${item.warningHours} h`)
  if (item.warningDays) parts.push(`${item.warningDays} días`)
  return parts.length ? `Avisar ${parts.join(' / ')} antes` : 'Sin anticipación'
}

function createDrawer() {
  const wrapper = document.createElement('div')
  wrapper.dataset.quickPlanDrawer = 'true'
  wrapper.className = 'fixed inset-0 z-[100] hidden'
  wrapper.innerHTML = `
    <div class="absolute inset-0 bg-slate-950/40" data-quick-plan-close></div>
    <section role="dialog" aria-modal="true" aria-labelledby="quick-plan-title" class="absolute inset-y-0 right-0 flex w-full max-w-3xl flex-col bg-white shadow-2xl">
      <header class="flex items-start justify-between gap-4 border-b border-border px-5 py-5 sm:px-7">
        <div>
          <p class="text-xs font-bold uppercase tracking-wider text-primary">Mantenimiento preventivo</p>
          <h2 id="quick-plan-title" class="mt-1 text-xl font-bold text-ink">Asignar servicios</h2>
          <p class="mt-1 text-sm text-ink-muted" data-quick-plan-equipment></p>
        </div>
        <button type="button" data-quick-plan-close class="rounded-lg border border-border px-3 py-2 text-sm font-semibold text-ink hover:bg-surface-subtle">Cerrar</button>
      </header>
      <div class="border-b border-border bg-surface-subtle px-5 py-4 sm:px-7">
        <label for="quick-plan-search" class="text-sm font-semibold text-ink">Buscar servicio</label>
        <input id="quick-plan-search" data-quick-plan-search type="search" autocomplete="off" placeholder="Motor, frenos, hidráulica…" class="mt-2 w-full rounded-lg border border-border-strong bg-white px-3 py-2.5 text-sm text-ink outline-none focus:border-primary focus:ring-2 focus:ring-primary/20" />
        <p class="mt-2 text-xs text-ink-muted">La frecuencia y la anticipación ya vienen definidas por el servicio. Acá sólo indicás desde qué última realización debe empezar a contar.</p>
      </div>
      <div class="min-h-0 flex-1 overflow-y-auto px-5 py-5 sm:px-7">
        <div data-quick-plan-feedback class="mb-4 hidden rounded-lg px-4 py-3 text-sm font-medium"></div>
        <div data-quick-plan-loading class="rounded-lg bg-surface-subtle p-4 text-sm text-ink-muted">Cargando servicios…</div>
        <div data-quick-plan-results class="space-y-3"></div>
      </div>
      <footer class="border-t border-border bg-white px-5 py-4 sm:px-7">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <p class="text-sm text-ink-muted"><strong data-quick-plan-count>0</strong> seleccionados</p>
          <button type="button" data-quick-plan-submit class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2.5 text-sm font-bold text-white hover:bg-primary-strong disabled:cursor-not-allowed disabled:opacity-50" disabled>Asignar seleccionados</button>
        </div>
      </footer>
    </section>`
  document.body.appendChild(wrapper)
  return wrapper
}

export function installQuickPlanAssignment(root, serverPayload) {
  if (!root || !['equipment-detail', 'assets-index'].includes(serverPayload?.page)) return

  let equipmentId = Number(serverPayload?.data?.equipment?.id ?? 0)
  let sourceUrl = serverPayload?.data?.routes?.addPlansFromTemplate ?? ''
  const drawer = createDrawer()
  const equipmentLabel = drawer.querySelector('[data-quick-plan-equipment]')
  const loading = drawer.querySelector('[data-quick-plan-loading]')
  const results = drawer.querySelector('[data-quick-plan-results]')
  const search = drawer.querySelector('[data-quick-plan-search]')
  const feedback = drawer.querySelector('[data-quick-plan-feedback]')
  const submit = drawer.querySelector('[data-quick-plan-submit]')
  const count = drawer.querySelector('[data-quick-plan-count]')
  const selected = new Set()
  let currentPayload = null
  let currentEquipment = null
  let currentServices = []
  let busy = false

  const showFeedback = (message, isError = false) => {
    feedback.textContent = message
    feedback.className = `mb-4 rounded-lg px-4 py-3 text-sm font-medium ${isError ? 'bg-danger-subtle text-danger-strong' : 'bg-success-subtle text-success-strong'}`
  }

  const updateSelection = () => {
    count.textContent = String(selected.size)
    submit.disabled = busy || selected.size === 0
  }

  const baseFields = (service) => {
    const fields = []
    if (service.intervalKm) fields.push(`<label class="text-xs font-semibold text-ink">Último km<input data-base-km="${service.id}" type="number" min="0" value="${currentEquipment?.currentKm ?? ''}" class="mt-1 min-h-10 w-full rounded-lg border border-border px-3 py-2 font-normal" /></label>`)
    if (service.intervalHours) fields.push(`<label class="text-xs font-semibold text-ink">Últimas horas<input data-base-hours="${service.id}" inputmode="decimal" value="${currentEquipment?.currentHours ?? ''}" class="mt-1 min-h-10 w-full rounded-lg border border-border px-3 py-2 font-normal" /></label>`)
    if (service.intervalDays) fields.push(`<label class="text-xs font-semibold text-ink">Última fecha<input data-base-date="${service.id}" type="date" class="mt-1 min-h-10 w-full rounded-lg border border-border px-3 py-2 font-normal" /></label>`)
    return fields.length ? `<div class="mt-4 grid gap-3 sm:grid-cols-3">${fields.join('')}</div>` : ''
  }

  const render = () => {
    const needle = normalizeText(search.value)
    const visible = currentServices.filter((service) => !needle || normalizeText([service.code, service.name, service.category, service.description].filter(Boolean).join(' ')).includes(needle))
    updateSelection()

    if (!visible.length) {
      results.innerHTML = `<div class="rounded-xl border border-dashed border-border-strong p-6 text-center"><p class="font-semibold text-ink">${currentServices.length ? 'No hay coincidencias' : 'No hay servicios disponibles para asignar'}</p><p class="mt-1 text-sm text-ink-muted">${currentServices.length ? 'Probá con otra búsqueda.' : 'Creá servicios de mantenimiento o revisá cuáles ya están asignados a este equipo.'}</p></div>`
      return
    }

    results.innerHTML = visible.map((service) => {
      const checked = selected.has(Number(service.id))
      return `<article class="rounded-xl border ${checked ? 'border-primary bg-brand-50' : 'border-border bg-white'} p-4">
        <div class="flex items-start gap-3">
          <input type="checkbox" data-service-select="${service.id}" ${checked ? 'checked' : ''} class="mt-1 size-4 rounded border-border-strong text-primary focus:ring-primary" />
          <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2"><h3 class="font-bold text-ink">${escapeHtml(service.name)}</h3><span class="rounded-full bg-surface-muted px-2 py-0.5 text-xs font-semibold text-ink-muted">${escapeHtml(service.code)}</span></div>
            ${service.description ? `<p class="mt-1 text-sm text-ink-muted">${escapeHtml(service.description)}</p>` : ''}
            <p class="mt-3 text-sm font-semibold text-ink">${escapeHtml(intervalSummary(service))}</p>
            <p class="mt-1 text-sm text-ink-muted">${escapeHtml(warningSummary(service))} · Prioridad ${escapeHtml(service.priority)}</p>
            ${checked ? baseFields(service) : ''}
          </div>
        </div>
      </article>`
    }).join('')
  }

  const hydrate = (payload) => {
    currentPayload = payload
    const data = payload?.data ?? {}
    currentEquipment = (data.catalogs?.equipment ?? []).find((item) => Number(item.id) === equipmentId) ?? null
    if (!currentEquipment) throw new Error('El equipo ya no está disponible para asignar servicios.')
    currentServices = assignableServices(currentEquipment, data.catalogs?.serviceTypes ?? [])
    equipmentLabel.textContent = `${currentEquipment.code} · ${currentEquipment.typeName} · ${currentEquipment.branchName}`
    loading.classList.add('hidden')
    render()
  }

  const load = async () => {
    loading.classList.remove('hidden')
    results.innerHTML = ''
    feedback.classList.add('hidden')
    const response = await fetch(sourceUrl.split('#')[0], { headers: { Accept: 'text/html' }, credentials: 'same-origin' })
    if (!response.ok) throw new Error('No se pudo cargar la lista de servicios.')
    hydrate(parseAppPayload(await response.text()))
  }

  const fieldValue = (selector) => drawer.querySelector(selector)?.value?.trim() || ''

  const assignOne = async (serviceId) => {
    const data = currentPayload.data
    const form = new FormData()
    form.append(data.csrf.name, data.csrf.hash)
    form.append('equipo_id', String(equipmentId))
    form.append('tipo_servicio_id', String(serviceId))
    const km = fieldValue(`[data-base-km="${serviceId}"]`)
    const hours = fieldValue(`[data-base-hours="${serviceId}"]`)
    const date = fieldValue(`[data-base-date="${serviceId}"]`)
    if (km) form.append('base_km', km)
    if (hours) form.append('base_horas', hours)
    if (date) form.append('base_fecha', date)

    const response = await fetch(data.routes.create, {
      method: 'POST', body: form, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
    if (!response.ok) throw new Error('No se pudo asignar uno de los servicios seleccionados.')
    const payload = parseAppPayload(await response.text())
    if (payload?.data?.flash?.error) throw new Error(payload.data.flash.error)
    currentPayload = payload
  }

  const postSelections = async (ids) => {
    if (busy || !ids.length || !currentPayload) return
    busy = true
    updateSelection()
    feedback.classList.add('hidden')
    try {
      for (const id of ids) await assignOne(id)
      selected.clear()
      await load()
      showFeedback(`${ids.length} servicio${ids.length === 1 ? '' : 's'} asignado${ids.length === 1 ? '' : 's'} correctamente.`)
    } catch (error) {
      showFeedback(error instanceof Error ? error.message : 'No se pudieron asignar los servicios.', true)
    } finally {
      busy = false
      updateSelection()
    }
  }

  drawer.addEventListener('change', (event) => {
    const checkbox = event.target.closest('[data-service-select]')
    if (!checkbox) return
    const id = Number(checkbox.dataset.serviceSelect)
    checkbox.checked ? selected.add(id) : selected.delete(id)
    render()
  })

  drawer.addEventListener('click', (event) => {
    if (event.target.closest('[data-quick-plan-close]')) {
      drawer.classList.add('hidden')
      document.body.classList.remove('overflow-hidden')
    }
  })

  search.addEventListener('input', render)
  submit.addEventListener('click', () => postSelections([...selected]))

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !drawer.classList.contains('hidden')) {
      drawer.classList.add('hidden')
      document.body.classList.remove('overflow-hidden')
    }
  })

  root.addEventListener('click', async (event) => {
    const anchor = event.target.closest('a')
    if (!anchor) return
    if (serverPayload.page === 'assets-index') {
      const context = assignmentContextFromUrl(anchor.href, window.location.href)
      if (!context) return
      equipmentId = context.equipmentId
      sourceUrl = context.sourceUrl
    } else if (anchor.href !== sourceUrl) return

    event.preventDefault()
    drawer.classList.remove('hidden')
    document.body.classList.add('overflow-hidden')
    search.value = ''
    selected.clear()
    updateSelection()
    try {
      await load()
      search.focus()
    } catch (error) {
      loading.classList.add('hidden')
      showFeedback(error instanceof Error ? error.message : 'No se pudo abrir el selector de servicios.', true)
    }
  })
}
