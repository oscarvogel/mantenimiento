function option(value, label) {
  const element = document.createElement('option')
  element.value = value
  element.textContent = label
  return element
}

function fieldLabel(text, htmlFor) {
  const label = document.createElement('label')
  label.className = 'block text-sm font-semibold text-ink'
  label.htmlFor = htmlFor
  label.textContent = text
  return label
}

export function installWorkOrderTaskClosure(root, payload) {
  const orders = payload?.data?.orders ?? payload?.pagePayload?.orders ?? []
  for (const order of orders) {
    if (order.status !== 'EN_PROCESO' || !Array.isArray(order.tasks) || order.tasks.length === 0) continue

    const form = root.querySelector(`#close-order-${order.id}`)
    if (!form || form.dataset.taskClosureInstalled === '1') continue

    const legacyTextarea = form.querySelector('textarea[name="trabajo_realizado"]')
    if (!legacyTextarea) continue

    legacyTextarea.required = false
    legacyTextarea.removeAttribute('name')
    legacyTextarea.hidden = true
    const legacyLabel = form.querySelector(`label[for="${legacyTextarea.id}"]`)
    if (legacyLabel) legacyLabel.hidden = true

    const section = document.createElement('fieldset')
    section.className = 'grid gap-3 sm:col-span-2'
    section.dataset.testid = `task-closure-${order.id}`

    const legend = document.createElement('legend')
    legend.className = 'mb-1 text-sm font-bold text-ink'
    legend.textContent = 'Resultado de las tareas'
    section.appendChild(legend)

    const help = document.createElement('p')
    help.className = 'mb-1 text-xs text-ink-muted'
    help.textContent = 'Marcá cada tarea. Si queda pendiente o no aplica, indicá el motivo para conservar la trazabilidad.'
    section.appendChild(help)

    for (const task of order.tasks) {
      const card = document.createElement('div')
      card.className = 'grid gap-3 rounded-xl border border-border bg-surface-subtle p-3 sm:grid-cols-2'

      const title = document.createElement('div')
      title.className = 'sm:col-span-2'
      title.innerHTML = `<strong class="text-sm text-ink"></strong><span class="ml-2 text-xs text-ink-muted"></span>`
      title.querySelector('strong').textContent = task.description
      title.querySelector('span').textContent = task.status ? `Estado actual: ${task.status}` : ''
      card.appendChild(title)

      const resultId = `order-${order.id}-task-${task.id}-result`
      const resultWrap = document.createElement('div')
      resultWrap.className = 'grid gap-1'
      resultWrap.appendChild(fieldLabel('Resultado', resultId))
      const select = document.createElement('select')
      select.id = resultId
      select.name = `trabajo_realizado[${task.id}][resultado]`
      select.required = true
      select.className = 'min-h-10 w-full rounded-lg border border-border bg-white px-3 py-2 text-sm text-ink'
      select.appendChild(option('', 'Seleccionar…'))
      select.appendChild(option('REALIZADA', 'Realizada'))
      select.appendChild(option('PENDIENTE', 'Pendiente / no realizada'))
      select.appendChild(option('NO_APLICA', 'No aplica'))
      resultWrap.appendChild(select)
      card.appendChild(resultWrap)

      const detailId = `order-${order.id}-task-${task.id}-detail`
      const detailWrap = document.createElement('div')
      detailWrap.className = 'grid gap-1'
      detailWrap.appendChild(fieldLabel('Detalle / motivo', detailId))
      const detail = document.createElement('textarea')
      detail.id = detailId
      detail.name = `trabajo_realizado[${task.id}][detalle]`
      detail.rows = 2
      detail.required = true
      detail.maxLength = 1000
      detail.placeholder = 'Ej.: filtro reemplazado / sin repuesto disponible'
      detail.className = 'w-full rounded-lg border border-border bg-white px-3 py-2 text-sm text-ink'
      detailWrap.appendChild(detail)
      card.appendChild(detailWrap)

      section.appendChild(card)
    }

    const legacyContainer = legacyTextarea.parentElement
    const anchor = legacyContainer && legacyContainer !== form ? legacyContainer : legacyTextarea
    form.insertBefore(section, anchor)
    form.dataset.taskClosureInstalled = '1'
  }
}
