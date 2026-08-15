const EQUIPMENT_SELECTORS = ['template-equipment', 'plan-equipment', 'plans-equipment']
const MAX_VISIBLE_RESULTS = 30

const normalize = (value) => String(value ?? '')
  .normalize('NFD')
  .replace(/[\u0300-\u036f]/g, '')
  .toLocaleLowerCase('es')
  .trim()

const equipmentSearchText = (equipment) => normalize([
  equipment?.code,
  equipment?.plate,
  equipment?.typeName,
  equipment?.branchCode,
  equipment?.branchName,
  equipment?.brandName,
  equipment?.modelName,
].filter(Boolean).join(' '))

const equipmentPrimaryLabel = (equipment, option) => equipment?.code || option?.textContent?.trim() || ''
const equipmentSecondaryLabel = (equipment) => [equipment?.typeName, equipment?.branchCode].filter(Boolean).join(' · ')

function createResultButton(entry, onSelect) {
  const button = document.createElement('button')
  button.type = 'button'
  button.setAttribute('role', 'option')
  button.dataset.equipmentId = entry.id
  button.className = 'block w-full border-b border-border-subtle px-3 py-2 text-left last:border-b-0 hover:bg-primary-subtle focus:bg-primary-subtle focus:outline-none'

  const primary = document.createElement('span')
  primary.className = 'block text-sm font-semibold text-ink'
  primary.textContent = entry.primary
  button.append(primary)

  if (entry.secondary) {
    const secondary = document.createElement('span')
    secondary.className = 'mt-0.5 block text-xs text-ink-muted'
    secondary.textContent = entry.secondary
    button.append(secondary)
  }

  button.addEventListener('mousedown', (event) => event.preventDefault())
  button.addEventListener('click', () => onSelect(entry))
  return button
}

export function enhanceEquipmentSelect(select, equipmentCatalog = []) {
  if (!(select instanceof HTMLSelectElement) || select.dataset.equipmentComboboxEnhanced === '1') return null

  const byId = new Map(equipmentCatalog.map((equipment) => [String(equipment.id), equipment]))
  const entries = [...select.options]
    .filter((option) => option.value !== '')
    .map((option) => {
      const equipment = byId.get(String(option.value)) ?? null
      return {
        id: String(option.value),
        option,
        equipment,
        primary: equipmentPrimaryLabel(equipment, option),
        secondary: equipmentSecondaryLabel(equipment),
        searchText: equipment ? equipmentSearchText(equipment) : normalize(option.textContent),
      }
    })

  const wrapper = document.createElement('div')
  wrapper.className = 'relative'
  wrapper.dataset.equipmentCombobox = select.id

  const input = document.createElement('input')
  input.type = 'search'
  input.autocomplete = 'off'
  input.spellcheck = false
  input.placeholder = select.required ? 'Buscar equipo por código, patente, tipo o sucursal…' : 'Todos / buscar equipo…'
  input.className = select.className
  input.setAttribute('role', 'combobox')
  input.setAttribute('aria-autocomplete', 'list')
  input.setAttribute('aria-expanded', 'false')
  input.setAttribute('aria-controls', `${select.id}-autocomplete-list`)
  input.setAttribute('aria-label', select.getAttribute('aria-label') || 'Buscar equipo')
  input.dataset.testid = `${select.id}-autocomplete`

  const list = document.createElement('div')
  list.id = `${select.id}-autocomplete-list`
  list.setAttribute('role', 'listbox')
  list.className = 'absolute z-50 mt-1 hidden max-h-72 w-full overflow-y-auto rounded-lg border border-border bg-white shadow-lg'

  select.insertAdjacentElement('beforebegin', wrapper)
  wrapper.append(input, list)
  select.hidden = true
  select.dataset.equipmentComboboxEnhanced = '1'

  let visibleEntries = []
  let activeIndex = -1

  const selectedEntry = () => entries.find((entry) => entry.id === String(select.value)) ?? null
  const syncInput = () => {
    const selected = selectedEntry()
    input.value = selected?.primary ?? ''
  }
  syncInput()

  const close = () => {
    list.classList.add('hidden')
    input.setAttribute('aria-expanded', 'false')
    input.removeAttribute('aria-activedescendant')
    activeIndex = -1
  }

  const activate = (index) => {
    const buttons = [...list.querySelectorAll('[role="option"]')]
    if (buttons.length === 0) return
    activeIndex = Math.max(0, Math.min(index, buttons.length - 1))
    buttons.forEach((button, idx) => button.setAttribute('aria-selected', idx === activeIndex ? 'true' : 'false'))
    const active = buttons[activeIndex]
    active.id ||= `${select.id}-autocomplete-option-${activeIndex}`
    input.setAttribute('aria-activedescendant', active.id)
    active.scrollIntoView({ block: 'nearest' })
  }

  const choose = (entry) => {
    select.value = entry.id
    select.dispatchEvent(new Event('input', { bubbles: true }))
    select.dispatchEvent(new Event('change', { bubbles: true }))
    input.value = entry.primary
    close()
  }

  const render = (query = '') => {
    const normalizedQuery = normalize(query)
    visibleEntries = entries
      .filter((entry) => normalizedQuery === '' || entry.searchText.includes(normalizedQuery))
      .slice(0, MAX_VISIBLE_RESULTS)

    list.replaceChildren()
    if (visibleEntries.length === 0) {
      const empty = document.createElement('p')
      empty.className = 'px-3 py-3 text-sm text-ink-muted'
      empty.textContent = 'Sin equipos que coincidan con la búsqueda.'
      list.append(empty)
    } else {
      visibleEntries.forEach((entry) => list.append(createResultButton(entry, choose)))
    }

    list.classList.remove('hidden')
    input.setAttribute('aria-expanded', 'true')
    activeIndex = -1
  }

  input.addEventListener('focus', () => render(input.value === selectedEntry()?.primary ? '' : input.value))
  input.addEventListener('input', () => render(input.value))
  input.addEventListener('keydown', (event) => {
    if (event.key === 'ArrowDown') {
      event.preventDefault()
      if (list.classList.contains('hidden')) render(input.value)
      activate(activeIndex + 1)
    } else if (event.key === 'ArrowUp') {
      event.preventDefault()
      activate(activeIndex <= 0 ? visibleEntries.length - 1 : activeIndex - 1)
    } else if (event.key === 'Enter' && activeIndex >= 0 && visibleEntries[activeIndex]) {
      event.preventDefault()
      choose(visibleEntries[activeIndex])
    } else if (event.key === 'Escape') {
      event.preventDefault()
      syncInput()
      close()
    }
  })

  input.addEventListener('blur', () => {
    window.setTimeout(() => {
      if (!wrapper.contains(document.activeElement)) {
        syncInput()
        close()
      }
    }, 0)
  })

  select.addEventListener('change', syncInput)

  return { input, list, entries, destroy: () => wrapper.remove() }
}

export function installEquipmentComboboxes(root, equipmentCatalog = []) {
  if (!root) return () => {}

  const enhance = () => {
    for (const id of EQUIPMENT_SELECTORS) {
      const select = root.querySelector(`#${id}`)
      if (select) enhanceEquipmentSelect(select, equipmentCatalog)
    }
  }

  enhance()
  const observer = new MutationObserver(enhance)
  observer.observe(root, { childList: true, subtree: true })
  return () => observer.disconnect()
}

export { MAX_VISIBLE_RESULTS }
