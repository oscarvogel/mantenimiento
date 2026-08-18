const normalizeText = (value) => String(value ?? '').trim().toLocaleUpperCase('es')

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

export function availableManualServices(equipment, serviceTypes) {
  if (!equipment) return []
  const assigned = new Set((equipment.assignedServiceTypeIds ?? []).map(Number))
  return (serviceTypes ?? []).filter((service) => !assigned.has(Number(service.id)))
}

export function matchesTemplateQuery(item, query) {
  const needle = normalizeText(query)
  if (!needle) return true

  const haystack = normalizeText([
    item.serviceName,
    item.templateName,
    item.notes,
    item.brand,
    item.model,
    item.equipmentTypeName,
  ].filter(Boolean).join(' '))

  return haystack.includes(needle)
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
  if (item.intervalKm) parts.push(`Cada ${item.intervalKm} km`)
  if (item.intervalHours) parts.push(`Cada ${item.intervalHours} h`)
  if (item.intervalDays) parts.push(`Cada ${item.intervalDays} días`)
  return parts.join(' · ') || 'Frecuencia sin informar'
}

function assignedPlanSummary(plan) {
  const parts = []
  if (plan.criteria?.kilometers?.interval) parts.push(`Cada ${plan.criteria.kilometers.interval} km`)
  if (plan.criteria?.hours?.interval) parts.push(`Cada ${plan.criteria.hours.interval} h`)
  if (plan.criteria?.date?.interval) parts.push(`Cada ${plan.criteria.date.interval} días`)
  return parts.join(' · ') || 'Frecuencia sin informar'
}

function scopeLabel(item) {
  if (item.model) return `Modelo ${item.model}`
  if (item.brand) return `Marca ${item.brand}`
  if (item.equipmentTypeId) return item.equipmentTypeName || 'Tipo de equipo'
  return 'Plantilla genérica'
}

function todayValue() {
  const now = new Date()
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')
  return `${now.getFullYear()}-${month}-${day}`
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
          <h2 id="quick-plan-title" class="mt-1 text-xl font-bold text-ink">Plan de mantenimiento</h2>
          <p class="mt-1 text-sm text-ink-muted" data-quick-plan-equipment></p>
        </div>
        <button type="button" data-quick-plan-close class="rounded-lg border border-border px-3 py-2 text-sm font-semibold text-ink hover:bg-surface-subtle" aria-label="Cerrar">Cerrar</button>
      </header>
      <div class="min-h-0 flex-1 overflow-y-auto">
        <div data-quick-plan-feedback class="mx-5 mt-5 hidden rounded-lg px-4 py-3 text-sm font-medium sm:mx-7"></div>
        <div data-quick-plan-loading class="m-5 rounded-lg bg-surface-subtle p-4 text-sm text-ink-muted sm:m-7">Cargando planes…</div>

        <section data-quick-plan-assigned class="hidden border-b border-border px-5 py-5 sm:px-7">
          <div class="flex items-center justify-between gap-3">
            <div>
              <h3 class="font-bold text-ink">Planes asignados</h3>
              <p class="mt-1 text-sm text-ink-muted">Lo que este equipo ya tiene configurado.</p>
            </div>
            <span data-assigned-count class="rounded-full bg-surface-subtle px-2.5 py-1 text-xs font-bold text-ink-muted">0</span>
          </div>
          <div data-assigned-results class="mt-4 space-y-2"></div>
        </section>

        <section data-existing-section class="border-b border-border px-5 py-5 sm:px-7">
          <div>
            <h3 class="font-bold text-ink">Agregar un plan existente</h3>
            <p class="mt-1 text-sm text-ink-muted">Te mostramos solamente opciones compatibles que todavía no están asignadas.</p>
          </div>
          <label for="quick-plan-search" class="mt-4 block text-sm font-semibold text-ink">Buscar plan</label>
          <input id="quick-plan-search" data-quick-plan-search type="search" autocomplete="off" placeholder="Nombre, servicio, plantilla, marca o modelo…" class="mt-2 w-full rounded-lg border border-border-strong bg-white px-3 py-2.5 text-sm text-ink outline-none focus:border-primary focus:ring-2 focus:ring-primary/20" />
          <div data-quick-plan-results class="mt-4 space-y-3"></div>
          <div data-existing-actions class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-ink-muted"><strong data-quick-plan-count>0</strong> seleccionados</p>
            <button type="button" data-quick-plan-submit class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2.5 text-sm font-bold text-white hover:bg-primary-strong disabled:cursor-not-allowed disabled:opacity-50" disabled>Agregar seleccionados</button>
          </div>
        </section>

        <section class="px-5 py-5 sm:px-7">
          <div class="rounded-xl border border-primary/20 bg-brand-50 p-4">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <h3 class="font-bold text-ink">¿No encontrás el plan que necesitás?</h3>
                <p class="mt-1 text-sm text-ink-muted">Crealo para este equipo sin salir de acá.</p>
              </div>
              <button type="button" data-manual-toggle class="shrink-0 rounded-lg border border-primary bg-white px-3 py-2 text-sm font-bold text-primary hover:bg-brand-50">+ Crear nuevo plan</button>
            </div>
          </div>

          <form data-manual-form class="mt-4 hidden rounded-xl border border-border bg-white p-4 sm:p-5">
            <div class="mb-4">
              <h3 class="font-bold text-ink">Crear nuevo plan</h3>
              <p class="mt-1 text-sm text-ink-muted">Este plan quedará asignado directamente al equipo.</p>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
              <label class="sm:col-span-2">
                <span class="text-sm font-semibold text-ink">Servicio</span>
                <select data-manual-service name="tipo_servicio_id" required class="mt-1.5 w-full rounded-lg border border-border-strong bg-white px-3 py-2.5 text-sm text-ink"></select>
              </label>

              <label data-field-km>
                <span class="text-sm font-semibold text-ink">Frecuencia (km)</span>
                <input data-manual-interval-km name="intervalo_km" type="number" min="1" class="mt-1.5 w-full rounded-lg border border-border-strong px-3 py-2.5 text-sm" />
              </label>
              <label data-field-km>
                <span class="text-sm font-semibold text-ink">Avisar antes (km)</span>
                <input name="anticipacion_km" type="number" min="0" value="0" class="mt-1.5 w-full rounded-lg border border-border-strong px-3 py-2.5 text-sm" />
              </label>
              <label data-field-km class="sm:col-span-2">
                <span class="text-sm font-semibold text-ink">Último mantenimiento realizado a (km)</span>
                <input data-manual-base-km name="base_km" type="number" min="0" class="mt-1.5 w-full rounded-lg border border-border-strong px-3 py-2.5 text-sm" />
              </label>

              <label data-field-hours>
                <span class="text-sm font-semibold text-ink">Frecuencia (horas)</span>
                <input data-manual-interval-hours name="intervalo_horas" type="number" min="0.1" step="0.1" class="mt-1.5 w-full rounded-lg border border-border-strong px-3 py-2.5 text-sm" />
              </label>
              <label data-field-hours>
                <span class="text-sm font-semibold text-ink">Avisar antes (horas)</span>
                <input name="anticipacion_horas" type="number" min="0" step="0.1" value="0" class="mt-1.5 w-full rounded-lg border border-border-strong px-3 py-2.5 text-sm" />
              </label>
              <label data-field-hours class="sm:col-span-2">
                <span class="text-sm font-semibold text-ink">Último mantenimiento realizado a (h)</span>
                <input data-manual-base-hours name="base_horas" type="number" min="0" step="0.1" class="mt-1.5 w-full rounded-lg border border-border-strong px-3 py-2.5 text-sm" />
              </label>

              <label>
                <span class="text-sm font-semibold text-ink">Frecuencia (días, opcional)</span>
                <input name="intervalo_dias" type="number" min="1" class="mt-1.5 w-full rounded-lg border border-border-strong px-3 py-2.5 text-sm" />
              </label>
              <label>
                <span class="text-sm font-semibold text-ink">Avisar antes (días)</span>
                <input name="anticipacion_dias" type="number" min="0" value="0" class="mt-1.5 w-full rounded-lg border border-border-strong px-3 py-2.5 text-sm" />
              </label>
              <label>
                <span class="text-sm font-semibold text-ink">Último mantenimiento realizado el</span>
                <input data-manual-base-date name="base_fecha" type="date" class="mt-1.5 w-full rounded-lg border border-border-strong px-3 py-2.5 text-sm" />
              </label>
              <label>
                <span class="text-sm font-semibold text-ink">Prioridad</span>
                <select name="prioridad" class="mt-1.5 w-full rounded-lg border border-border-strong bg-white px-3 py-2.5 text-sm text-ink">
                  <option value="BAJA">Baja</option>
                  <option value="MEDIA" selected>Media</option>
                  <option value="ALTA">Alta</option>
                  <option value="CRITICA">Crítica</option>
                </select>
              </label>
              <label class="sm:col-span-2">
                <span class="text-sm font-semibold text-ink">Observaciones (opcional)</span>
                <textarea name="observaciones" rows="2" maxlength="1000" class="mt-1.5 w-full rounded-lg border border-border-strong px-3 py-2.5 text-sm"></textarea>
              </label>
            </div>
            <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <button type="button" data-manual-cancel class="rounded-lg border border-border px-4 py-2.5 text-sm font-bold text-ink hover:bg-surface-subtle">Cancelar</button>
              <button type="submit" data-manual-submit class="rounded-lg bg-primary px-4 py-2.5 text-sm font-bold text-white hover:bg-primary-strong disabled:cursor-not-allowed disabled:opacity-50">Crear y asignar</button>
            </div>
          </form>
        </section>
      </div>
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
  const assignedSection = drawer.querySelector('[data-quick-plan-assigned]')
  const assignedResults = drawer.querySelector('[data-assigned-results]')
  const assignedCount = drawer.querySelector('[data-assigned-count]')
  const search = drawer.querySelector('[data-quick-plan-search]')
  const feedback = drawer.querySelector('[data-quick-plan-feedback]')
  const submit = drawer.querySelector('[data-quick-plan-submit]')
  const count = drawer.querySelector('[data-quick-plan-count]')
  const manualToggle = drawer.querySelector('[data-manual-toggle]')
  const manualForm = drawer.querySelector('[data-manual-form]')
  const manualService = drawer.querySelector('[data-manual-service]')
  const manualSubmit = drawer.querySelector('[data-manual-submit]')
  const selected = new Set()
  let currentPayload = null
  let currentTemplates = []
  let currentEquipment = null
  let busy = false

  const showFeedback = (message, isError = false) => {
    feedback.textContent = message
    feedback.className = `mx-5 mt-5 rounded-lg px-4 py-3 text-sm font-medium sm:mx-7 ${isError ? 'bg-danger-subtle text-danger-strong' : 'bg-success-subtle text-success-strong'}`
  }

  const updateSelection = () => {
    count.textContent = String(selected.size)
    submit.disabled = busy || selected.size === 0
    manualSubmit.disabled = busy
  }

  const renderAssigned = () => {
    const plans = (currentPayload?.data?.plans?.items ?? []).filter((plan) => Number(plan.equipment?.id) === equipmentId)
    assignedCount.textContent = String(plans.length)
    assignedSection.classList.remove('hidden')
    if (!plans.length) {
      assignedResults.innerHTML = '<div class="rounded-lg bg-surface-subtle px-3 py-3 text-sm text-ink-muted">Todavía no hay planes asignados.</div>'
      return
    }
    assignedResults.innerHTML = plans.map((plan) => `<article class="rounded-lg border border-border px-3 py-3"><div class="flex items-start justify-between gap-3"><div><p class="font-semibold text-ink">${escapeHtml(plan.serviceName)}</p><p class="mt-1 text-xs text-ink-muted">${escapeHtml(assignedPlanSummary(plan))}</p></div><span class="rounded-full bg-surface-subtle px-2 py-1 text-xs font-bold text-ink-muted">${escapeHtml(plan.state || 'SIN DATOS')}</span></div></article>`).join('')
  }

  const renderManualForm = () => {
    const services = availableManualServices(currentEquipment, currentPayload?.data?.catalogs?.serviceTypes ?? [])
    manualService.innerHTML = services.length
      ? '<option value="" disabled selected>Seleccionar servicio</option>' + services.map((service) => `<option value="${Number(service.id)}">${escapeHtml(service.code)} · ${escapeHtml(service.name)}</option>`).join('')
      : '<option value="" disabled selected>No hay servicios disponibles</option>'
    manualSubmit.disabled = busy || services.length === 0

    drawer.querySelectorAll('[data-field-km]').forEach((node) => node.classList.toggle('hidden', currentEquipment?.controlsKm !== true))
    drawer.querySelectorAll('[data-field-hours]').forEach((node) => node.classList.toggle('hidden', currentEquipment?.controlsHours !== true))
    drawer.querySelector('[data-manual-base-km]').value = currentEquipment?.currentKm ?? ''
    drawer.querySelector('[data-manual-base-hours]').value = currentEquipment?.currentHours ?? ''
    drawer.querySelector('[data-manual-base-date]').value = todayValue()
  }

  const render = () => {
    const visible = currentTemplates.filter((item) => matchesTemplateQuery(item, search.value))
    selected.forEach((id) => {
      if (!currentTemplates.some((item) => Number(item.id) === Number(id))) selected.delete(id)
    })
    updateSelection()

    if (!visible.length) {
      results.innerHTML = `<div class="rounded-xl border border-dashed border-border-strong p-6 text-center"><p class="font-semibold text-ink">${currentTemplates.length ? 'No hay coincidencias' : 'No hay planes compatibles disponibles'}</p><p class="mt-1 text-sm text-ink-muted">${currentTemplates.length ? 'Probá con otra búsqueda.' : 'Podés crear un plan nuevo para este equipo desde el bloque de abajo.'}</p>${currentTemplates.length ? '' : '<button type="button" data-empty-create-manual class="mt-4 rounded-lg bg-primary px-4 py-2.5 text-sm font-bold text-white hover:bg-primary-strong">+ Crear nuevo plan</button>'}</div>`
      return
    }

    results.innerHTML = visible.map((item) => {
      const checked = selected.has(Number(item.id))
      return `<article class="rounded-xl border ${checked ? 'border-primary bg-brand-50' : 'border-border bg-white'} p-4" data-template-card="${Number(item.id)}">
        <div class="flex items-start gap-3">
          <input type="checkbox" data-template-select="${Number(item.id)}" ${checked ? 'checked' : ''} class="mt-1 size-4 rounded border-border-strong text-primary focus:ring-primary" aria-label="Seleccionar ${escapeHtml(item.serviceName)}" />
          <div class="min-w-0 flex-1">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
              <div><h3 class="font-bold text-ink">${escapeHtml(item.serviceName)}</h3><p class="mt-1 text-xs text-ink-muted">${escapeHtml(item.templateName)} · ${escapeHtml(scopeLabel(item))}</p></div>
              <button type="button" data-template-add="${Number(item.id)}" class="shrink-0 rounded-lg border border-primary px-3 py-2 text-sm font-bold text-primary hover:bg-brand-50">+ Agregar</button>
            </div>
            <p class="mt-3 text-sm font-semibold text-ink">${escapeHtml(intervalSummary(item))}</p>
            ${item.notes ? `<p class="mt-2 text-sm text-ink-muted">${escapeHtml(item.notes)}</p>` : ''}
          </div>
        </div>
      </article>`
    }).join('')
  }

  const hydrate = (payload) => {
    currentPayload = payload
    const data = payload?.data ?? {}
    currentEquipment = (data.catalogs?.equipment ?? []).find((item) => Number(item.id) === equipmentId) ?? null
    if (!currentEquipment) throw new Error('El equipo ya no está disponible para asignar planes.')
    currentTemplates = compatibleTemplates(currentEquipment, data.catalogs?.templateDefaults ?? [])
    equipmentLabel.textContent = `${currentEquipment.code} · ${currentEquipment.typeName} · ${currentEquipment.branchName}`
    loading.classList.add('hidden')
    renderAssigned()
    renderManualForm()
    render()
  }

  const load = async () => {
    loading.classList.remove('hidden')
    results.innerHTML = ''
    feedback.classList.add('hidden')
    const response = await fetch(sourceUrl.split('#')[0], { headers: { Accept: 'text/html' }, credentials: 'same-origin' })
    if (!response.ok) throw new Error('No se pudo cargar la lista de planes.')
    hydrate(parseAppPayload(await response.text()))
  }

  const postSelections = async (ids) => {
    if (busy || !ids.length || !currentPayload) return
    busy = true
    updateSelection()
    feedback.classList.add('hidden')

    try {
      const data = currentPayload.data
      const form = new FormData()
      form.append(data.csrf.name, data.csrf.hash)
      form.append('equipo_id', String(equipmentId))
      ids.forEach((id) => form.append(`planes[${id}][seleccionado]`, '1'))

      const response = await fetch(data.routes.createFromTemplate, {
        method: 'POST',
        body: form,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      })
      if (!response.ok) throw new Error('No se pudieron asignar los planes seleccionados.')

      const payload = parseAppPayload(await response.text())
      if (payload?.data?.flash?.error) throw new Error(payload.data.flash.error)

      selected.clear()
      hydrate(payload)
      showFeedback(payload?.data?.flash?.success || `${ids.length} plan(es) agregado(s) correctamente.`)
    } catch (error) {
      showFeedback(error instanceof Error ? error.message : 'No se pudieron asignar los planes.', true)
    } finally {
      busy = false
      updateSelection()
    }
  }

  const openManual = () => {
    manualForm.classList.remove('hidden')
    manualToggle.classList.add('hidden')
    renderManualForm()
    manualService.focus()
  }

  const closeManual = () => {
    manualForm.classList.add('hidden')
    manualToggle.classList.remove('hidden')
    manualForm.reset()
    renderManualForm()
  }

  const postManual = async () => {
    if (busy || !currentPayload || !currentEquipment) return
    const form = new FormData(manualForm)
    form.append(currentPayload.data.csrf.name, currentPayload.data.csrf.hash)
    form.append('equipo_id', String(equipmentId))

    const hasInterval = [form.get('intervalo_km'), form.get('intervalo_horas'), form.get('intervalo_dias')]
      .some((value) => String(value ?? '').trim() !== '')
    if (!hasInterval) {
      showFeedback('Indicá al menos una frecuencia en km, horas o días.', true)
      return
    }

    busy = true
    updateSelection()
    feedback.classList.add('hidden')
    try {
      const response = await fetch(currentPayload.data.routes.create, {
        method: 'POST', body: form, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' },
      })
      if (!response.ok) throw new Error('No se pudo crear el plan.')
      const payload = parseAppPayload(await response.text())
      if (payload?.data?.flash?.error) throw new Error(payload.data.flash.error)

      closeManual()
      await load()
      showFeedback('Plan creado y asignado correctamente.')
    } catch (error) {
      showFeedback(error instanceof Error ? error.message : 'No se pudo crear el plan.', true)
    } finally {
      busy = false
      updateSelection()
    }
  }

  drawer.addEventListener('click', (event) => {
    const close = event.target.closest('[data-quick-plan-close]')
    if (close) {
      drawer.classList.add('hidden')
      document.body.classList.remove('overflow-hidden')
      return
    }

    const add = event.target.closest('[data-template-add]')
    if (add) {
      postSelections([Number(add.dataset.templateAdd)])
      return
    }

    if (event.target.closest('[data-empty-create-manual]')) openManual()
  })

  drawer.addEventListener('change', (event) => {
    const checkbox = event.target.closest('[data-template-select]')
    if (!checkbox) return
    const id = Number(checkbox.dataset.templateSelect)
    checkbox.checked ? selected.add(id) : selected.delete(id)
    render()
  })

  search.addEventListener('input', render)
  submit.addEventListener('click', () => postSelections([...selected]))
  manualToggle.addEventListener('click', openManual)
  drawer.querySelector('[data-manual-cancel]').addEventListener('click', closeManual)
  manualForm.addEventListener('submit', (event) => {
    event.preventDefault()
    postManual()
  })

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
    } else if (anchor.href !== sourceUrl) {
      return
    }

    event.preventDefault()
    drawer.classList.remove('hidden')
    document.body.classList.add('overflow-hidden')
    search.value = ''
    selected.clear()
    manualForm.classList.add('hidden')
    manualToggle.classList.remove('hidden')
    updateSelection()

    try {
      await load()
      search.focus()
    } catch (error) {
      loading.classList.add('hidden')
      showFeedback(error instanceof Error ? error.message : 'No se pudo abrir el selector de planes.', true)
    }
  })
}
