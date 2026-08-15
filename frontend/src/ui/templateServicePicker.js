const normalize = (value) => String(value ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim()

export function compactFrequency(parts) {
  return parts
    .map((part) => String(part ?? '').split(' · ')[0].trim())
    .filter(Boolean)
    .join(' / ')
}

export function filterServiceCandidates(candidates, query) {
  const term = normalize(query)
  if (!term) return candidates
  return candidates.filter((candidate) => normalize([
    candidate.name,
    candidate.code,
    candidate.template,
    candidate.scope,
    candidate.frequency,
  ].filter(Boolean).join(' ')).includes(term))
}

function createElement(tag, className = '', text = '') {
  const element = document.createElement(tag)
  if (className) element.className = className
  if (text) element.textContent = text
  return element
}

function candidateFromArticle(article, templateDefaults, serviceTypes) {
  const checkbox = article.querySelector('input[type="checkbox"][name^="planes["]')
  if (!checkbox) return null
  const match = checkbox.name.match(/^planes\[(\d+)]/)
  const itemId = match ? Number(match[1]) : null
  const item = templateDefaults.find((entry) => Number(entry.id) === itemId)
  const service = item ? serviceTypes.find((entry) => Number(entry.id) === Number(item.serviceTypeId)) : null
  const title = article.querySelector('label strong')?.textContent?.trim() || item?.serviceName || 'Servicio'
  const scope = article.querySelector('label span span')?.textContent?.trim() || item?.templateName || ''
  const frequencyParts = [...article.querySelectorAll('.mt-4 .text-xs.font-semibold')].map((node) => node.textContent)
  return {
    article,
    checkbox,
    itemId,
    name: title,
    code: service?.code || '',
    template: item?.templateName || '',
    scope,
    frequency: compactFrequency(frequencyParts),
  }
}

function buildModal(panel, state) {
  const backdrop = createElement('div', 'fixed inset-0 z-[80] hidden bg-black/40 p-3 sm:p-6')
  backdrop.dataset.templateServicePicker = 'modal'
  backdrop.setAttribute('role', 'presentation')

  const dialog = createElement('section', 'mx-auto flex max-h-[90vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl')
  dialog.setAttribute('role', 'dialog')
  dialog.setAttribute('aria-modal', 'true')
  dialog.setAttribute('aria-labelledby', 'service-library-title')

  const header = createElement('div', 'flex items-start justify-between gap-4 border-b border-border p-4 sm:p-5')
  const headingWrap = createElement('div')
  const heading = createElement('h3', 'text-lg font-bold text-ink', 'Agregar servicio desde biblioteca')
  heading.id = 'service-library-title'
  const description = createElement('p', 'mt-1 text-sm text-ink-muted', 'Buscá y seleccioná los servicios que querés agregar a este equipo.')
  headingWrap.append(heading, description)
  const closeButton = createElement('button', 'rounded-lg px-3 py-2 text-sm font-semibold text-ink-muted hover:bg-surface-subtle', 'Cerrar')
  closeButton.type = 'button'
  closeButton.setAttribute('aria-label', 'Cerrar biblioteca de servicios')
  header.append(headingWrap, closeButton)

  const controls = createElement('div', 'space-y-3 border-b border-border-subtle p-4 sm:p-5')
  const search = createElement('input', 'w-full rounded-lg border border-border-strong bg-white px-3 py-2.5 text-sm text-ink outline-none focus:border-primary focus:ring-2 focus:ring-primary/20')
  search.type = 'search'
  search.placeholder = 'Buscar por nombre o código del servicio'
  search.setAttribute('aria-label', 'Buscar servicio')
  const compatible = createElement('div', 'flex items-center gap-2 text-xs font-semibold text-success-strong')
  compatible.innerHTML = '<span aria-hidden="true">✓</span><span>Mostrando solamente servicios compatibles con el equipo seleccionado</span>'
  controls.append(search, compatible)

  const results = createElement('div', 'min-h-0 flex-1 overflow-y-auto p-3 sm:p-4')
  const footer = createElement('div', 'flex flex-col-reverse gap-2 border-t border-border p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5')
  const count = createElement('p', 'text-sm font-semibold text-ink-muted')
  const addButton = createElement('button', 'inline-flex min-h-11 items-center justify-center rounded-lg bg-primary px-4 py-2.5 text-sm font-bold text-white transition hover:bg-primary-hover disabled:cursor-not-allowed disabled:opacity-50')
  addButton.type = 'button'
  footer.append(count, addButton)

  dialog.append(header, controls, results, footer)
  backdrop.append(dialog)
  panel.append(backdrop)

  const render = () => {
    const filtered = filterServiceCandidates(state.candidates, search.value)
    results.replaceChildren()
    if (filtered.length === 0) {
      results.append(createElement('p', 'rounded-lg bg-surface-subtle p-4 text-sm text-ink-muted', 'No encontramos servicios con ese criterio.'))
    } else {
      const list = createElement('div', 'divide-y divide-border-subtle rounded-xl border border-border')
      for (const candidate of filtered) {
        const label = createElement('label', 'flex cursor-pointer items-start gap-3 p-3 hover:bg-surface-subtle sm:p-4')
        const input = createElement('input', 'mt-1 size-4 shrink-0 rounded border-border-strong text-primary focus:ring-primary')
        input.type = 'checkbox'
        input.checked = state.selected.has(candidate.itemId)
        input.addEventListener('change', () => {
          if (input.checked) state.selected.add(candidate.itemId)
          else state.selected.delete(candidate.itemId)
          renderCount()
        })
        const copy = createElement('span', 'min-w-0 flex-1')
        const top = createElement('span', 'flex flex-wrap items-center gap-2')
        const name = createElement('strong', 'text-sm text-ink', candidate.name)
        top.append(name)
        if (candidate.code) top.append(createElement('span', 'rounded bg-surface-muted px-1.5 py-0.5 text-[11px] font-semibold text-ink-muted', candidate.code))
        const meta = createElement('span', 'mt-1 block text-xs text-ink-muted', [candidate.scope, candidate.frequency].filter(Boolean).join(' · '))
        copy.append(top, meta)
        label.append(input, copy)
        list.append(label)
      }
      results.append(list)
    }
  }

  const renderCount = () => {
    const total = state.selected.size
    count.textContent = total === 1 ? '1 servicio seleccionado' : `${total} servicios seleccionados`
    addButton.textContent = total > 0 ? `Agregar ${total} ${total === 1 ? 'servicio' : 'servicios'}` : 'Seleccioná al menos un servicio'
    addButton.disabled = total === 0
  }

  const close = () => {
    backdrop.classList.add('hidden')
    document.body.classList.remove('overflow-hidden')
  }
  const open = () => {
    render()
    renderCount()
    backdrop.classList.remove('hidden')
    document.body.classList.add('overflow-hidden')
    setTimeout(() => search.focus(), 0)
  }

  search.addEventListener('input', render)
  closeButton.addEventListener('click', close)
  backdrop.addEventListener('click', (event) => {
    if (event.target === backdrop) close()
  })
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !backdrop.classList.contains('hidden')) close()
  })
  addButton.addEventListener('click', () => {
    state.applySelection()
    close()
  })

  renderCount()
  return { open, refresh: () => { render(); renderCount() } }
}

function installPanelPicker(panel, templateDefaults, serviceTypes) {
  if (panel.dataset.servicePickerInstalled === '1') return
  const form = panel.querySelector('form')
  if (!form) return
  panel.dataset.servicePickerInstalled = '1'

  const state = {
    candidates: [],
    selected: new Set(),
    initializedEquipmentId: null,
    applySelection: () => {},
  }

  const toolbar = createElement('div', 'hidden rounded-xl border border-border bg-surface-subtle p-4')
  toolbar.dataset.templateServicePicker = 'toolbar'
  const toolbarRow = createElement('div', 'flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between')
  const toolbarCopy = createElement('div')
  toolbarCopy.append(
    createElement('p', 'text-sm font-bold text-ink', 'Servicios a agregar'),
    createElement('p', 'mt-1 text-xs text-ink-muted', 'Elegí primero los servicios. Después completá solamente la información histórica de los seleccionados.'),
  )
  const openButton = createElement('button', 'inline-flex min-h-11 items-center justify-center rounded-lg bg-primary px-4 py-2.5 text-sm font-bold text-white hover:bg-primary-hover', '+ Agregar servicio desde biblioteca')
  openButton.type = 'button'
  toolbarRow.append(toolbarCopy, openButton)
  toolbar.append(toolbarRow)

  const configHeading = createElement('div', 'hidden')
  configHeading.dataset.templateServicePicker = 'config-heading'
  configHeading.append(
    createElement('h3', 'text-base font-bold text-ink', 'Configurar servicios seleccionados'),
    createElement('p', 'mt-1 text-sm text-ink-muted', 'Completá la última realización si la conocés. Podés volver a la biblioteca para cambiar la selección.'),
  )

  const firstInfo = form.querySelector('p, template, div')
  if (firstInfo) firstInfo.before(toolbar)
  else form.append(toolbar)
  toolbar.after(configHeading)

  const modal = buildModal(panel, state)
  openButton.addEventListener('click', () => modal.open())

  const refresh = () => {
    const equipmentSelect = form.querySelector('#template-equipment')
    const equipmentId = equipmentSelect?.value || ''
    const articles = [...form.querySelectorAll('article')].filter((article) => article.querySelector('input[type="checkbox"][name^="planes["]'))
    const candidates = articles.map((article) => candidateFromArticle(article, templateDefaults, serviceTypes)).filter(Boolean)

    state.candidates = candidates
    if (equipmentId !== state.initializedEquipmentId) {
      state.initializedEquipmentId = equipmentId
      state.selected.clear()
      for (const candidate of candidates) {
        candidate.checkbox.checked = false
        candidate.checkbox.dispatchEvent(new Event('change', { bubbles: true }))
      }
    }

    state.applySelection = () => {
      for (const candidate of state.candidates) {
        const selected = state.selected.has(candidate.itemId)
        candidate.checkbox.checked = selected
        candidate.checkbox.dispatchEvent(new Event('change', { bubbles: true }))
        candidate.article.classList.toggle('hidden', !selected)
      }
      configHeading.classList.toggle('hidden', state.selected.size === 0)
      const submit = form.querySelector('button[type="submit"]')
      if (submit) submit.classList.toggle('hidden', state.selected.size === 0)
    }

    toolbar.classList.toggle('hidden', !equipmentId || candidates.length === 0)
    for (const candidate of candidates) candidate.article.classList.add('hidden')
    configHeading.classList.add('hidden')
    const submit = form.querySelector('button[type="submit"]')
    if (submit) submit.classList.add('hidden')
    modal.refresh()
  }

  let scheduled = false
  const scheduleRefresh = () => {
    if (scheduled) return
    scheduled = true
    queueMicrotask(() => {
      scheduled = false
      refresh()
    })
  }

  const observer = new MutationObserver(scheduleRefresh)
  observer.observe(form, { childList: true, subtree: true })
  form.addEventListener('change', (event) => {
    if (event.target?.id === 'template-equipment') setTimeout(refresh, 0)
  })
  refresh()
}

export function installTemplateServicePicker(root, catalogs = {}) {
  const install = () => {
    const panel = root.querySelector('#planes-desde-plantilla')
    if (panel) installPanelPicker(panel, catalogs.templateDefaults ?? [], catalogs.serviceTypes ?? [])
  }
  install()
  const observer = new MutationObserver(install)
  observer.observe(root, { childList: true, subtree: true })
  return observer
}
