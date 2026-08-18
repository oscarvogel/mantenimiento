const normalizeCode = (value) => String(value ?? '').trim().toLocaleUpperCase('es')

export function flattenLibraryTasks(items) {
  return (items ?? []).flatMap((item) => (item.tasks ?? []).map((task) => ({
    ...task,
    serviceTypeId: Number(task.serviceTypeId ?? item.serviceTypeId ?? 0),
    statusUrl: task.statusUrl || `${String(task.updateUrl ?? '').replace(/\/$/, '')}/estado`,
  })))
}

export function taskStatusLabel(active) {
  return active ? 'Activa' : 'Inactiva'
}

function createFeedback() {
  const node = document.createElement('div')
  node.setAttribute('role', 'status')
  node.setAttribute('aria-live', 'polite')
  node.className = 'fixed bottom-4 right-4 z-[120] hidden max-w-sm rounded-xl border border-border bg-white px-4 py-3 text-sm font-semibold text-ink shadow-xl'
  document.body.appendChild(node)
  return node
}

function showFeedback(node, message, error = false) {
  node.textContent = message
  node.className = `fixed bottom-4 right-4 z-[120] max-w-sm rounded-xl border px-4 py-3 text-sm font-semibold shadow-xl ${error ? 'border-danger bg-danger-subtle text-danger-strong' : 'border-success bg-success-subtle text-success-strong'}`
  window.clearTimeout(node._hideTimer)
  node._hideTimer = window.setTimeout(() => node.classList.add('hidden'), 2800)
}

function rowForTask(root, task, claimedRows) {
  const expectedCode = normalizeCode(task.code)
  const expectedName = String(task.name ?? '').trim()
  if (!expectedCode) return null

  const rows = [...root.querySelectorAll('li')]
  return rows.find((row) => {
    if (claimedRows.has(row)) return false
    const codeNode = [...row.querySelectorAll('p.font-mono')].find((node) => normalizeCode(node.textContent).startsWith(expectedCode))
    const nameNode = [...row.querySelectorAll('p')].find((node) => String(node.textContent ?? '').trim() === expectedName)
    return Boolean(codeNode && nameNode)
  }) ?? null
}

function updateInactiveChip(row, active) {
  const chips = [...row.querySelectorAll('span')]
  const inactiveChip = chips.find((node) => String(node.textContent ?? '').trim() === 'Inactiva')
  if (active) {
    inactiveChip?.remove()
    return
  }
  if (inactiveChip) return

  const chipContainer = [...row.querySelectorAll('div')].find((node) => node.className.includes('flex-wrap') && node.querySelector('span'))
  if (!chipContainer) return
  const chip = document.createElement('span')
  chip.className = 'rounded-full bg-surface-muted px-2 py-0.5 text-[11px] font-semibold text-ink-muted'
  chip.textContent = 'Inactiva'
  chip.dataset.libraryTaskInactiveChip = 'true'
  chipContainer.appendChild(chip)
}

function renderButton(button, active, busy = false) {
  button.disabled = busy
  button.setAttribute('aria-pressed', active ? 'true' : 'false')
  button.textContent = taskStatusLabel(active)
  button.className = `inline-flex min-h-9 items-center rounded-full border px-3 py-1.5 text-xs font-bold transition ${active ? 'border-success bg-success-subtle text-success-strong' : 'border-border-strong bg-surface-muted text-ink-muted'} ${busy ? 'cursor-wait opacity-60' : 'hover:brightness-95'}`
  button.title = active ? 'Desactivar tarea' : 'Activar tarea'
}

export function installLibraryTaskStatus(root, serverPayload) {
  if (!root || serverPayload?.page !== 'preventive-library') return

  const canEdit = Boolean(serverPayload?.data?.canEdit)
  const csrf = serverPayload?.data?.csrf ?? {}
  const tasks = flattenLibraryTasks(serverPayload?.data?.items ?? [])
  const feedback = createFeedback()
  let scanning = false

  const scan = () => {
    if (scanning) return
    scanning = true
    const claimedRows = new Set()

    try {
      tasks.forEach((task) => {
        const row = rowForTask(root, task, claimedRows)
        if (!row) return
        claimedRows.add(row)
        if (row.querySelector(`[data-library-task-status="${Number(task.id)}"]`)) return

        const actions = [...row.querySelectorAll('div')].find((node) => node.className.includes('items-center') && node.className.includes('gap-2') && node.querySelector('button'))
        const host = actions ?? row
        const control = document.createElement(canEdit ? 'button' : 'span')
        control.dataset.libraryTaskStatus = String(Number(task.id))

        if (!canEdit) {
          control.className = 'inline-flex min-h-9 items-center rounded-full border border-border px-3 py-1.5 text-xs font-semibold text-ink-muted'
          control.textContent = taskStatusLabel(Boolean(task.active))
          control.title = 'Solo lectura'
          host.prepend(control)
          return
        }

        control.type = 'button'
        renderButton(control, Boolean(task.active))
        host.prepend(control)

        control.addEventListener('click', async () => {
          if (control.disabled || !task.statusUrl || !task.serviceTypeId) return
          const previous = Boolean(task.active)
          const next = !previous
          task.active = next
          renderButton(control, next, true)
          updateInactiveChip(row, next)

          const body = new FormData()
          if (csrf.name && csrf.hash) body.append(csrf.name, csrf.hash)
          body.append('tipo_servicio_id', String(task.serviceTypeId))
          body.append('activo', next ? '1' : '0')

          try {
            const response = await fetch(task.statusUrl, {
              method: 'POST',
              body,
              credentials: 'same-origin',
              headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            })
            const payload = await response.json().catch(() => ({}))
            if (!response.ok || payload?.ok === false) throw new Error(payload?.message || 'No se pudo cambiar el estado de la tarea.')
            task.active = Boolean(payload?.active ?? next)
            renderButton(control, task.active)
            updateInactiveChip(row, task.active)
            showFeedback(feedback, payload?.message || `Tarea ${taskStatusLabel(task.active).toLowerCase()}.`)
          } catch (error) {
            task.active = previous
            renderButton(control, previous)
            updateInactiveChip(row, previous)
            showFeedback(feedback, error instanceof Error ? error.message : 'No se pudo cambiar el estado de la tarea.', true)
          }
        })
      })
    } finally {
      scanning = false
    }
  }

  scan()
  const observer = new MutationObserver(scan)
  observer.observe(root, { childList: true, subtree: true })
}
