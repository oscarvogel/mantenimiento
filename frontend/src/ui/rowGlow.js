// Brillo en las filas nuevas o editadas. Al salir de una lista se guarda cómo estaba cada fila.
// Si al volver hubo un guardado exitoso en el medio, brillan las filas que no estaban o
// que cambiaron. El servidor no avisa qué registro se guardó: se compara en el navegador.
// shortcut: también brilla una fila que cambió otra persona en ese rato. Para
// precisión exacta, que cada controlador mande el id guardado en el flash.

const SNAPSHOT_PREFIX = 'ui-row-glow:'
const LAST_SAVE_KEY = 'ui-row-glow:last-save'
const MAX_AGE_MS = 30 * 60 * 1000
// Más cambios que esto es un reordenamiento o datos que se mueven solos, no una edición.
const MAX_GLOWING = 5
const ROW_SELECTORS = {
  table: 'tbody > tr',
  // Tarjetas que reemplazan a la tabla en el celular.
  card: ['sm', 'md', 'lg'].map((size) => `ul.divide-y[class~="${size}:hidden"] > li`).join(', '),
}

export function rememberSuccessfulSave(now = Date.now()) {
  try { sessionStorage.setItem(LAST_SAVE_KEY, String(now)) } catch { /* sin almacenamiento, sin brillo */ }
}

function hash(text) {
  let value = 5381
  for (let index = 0; index < text.length; index += 1) value = ((value * 33) ^ text.charCodeAt(index)) >>> 0
  return value.toString(36)
}

function rowKey(row) {
  const link = row.querySelector('a[href]')
  if (link) return link.getAttribute('href')
  return (row.firstElementChild ?? row).textContent.replace(/\s+/g, ' ').trim()
}

function collectRows(root) {
  const rows = {}
  for (const [kind, selector] of Object.entries(ROW_SELECTORS)) {
    const seen = new Map()
    rows[kind] = [...root.querySelectorAll(selector)].map((element) => {
      const base = rowKey(element)
      const count = seen.get(base) ?? 0
      seen.set(base, count + 1)
      return { element, key: count ? `${base}#${count}` : base, signature: hash(element.textContent.replace(/\s+/g, ' ').trim()) }
    })
  }
  return rows
}

function snapshotOf(rows) {
  return Object.fromEntries(Object.entries(rows).map(([kind, list]) => [kind, Object.fromEntries(list.map((row) => [row.key, row.signature]))]))
}

export function installRowGlow(root, { now = Date.now(), storage = sessionStorage, page = location.pathname + location.search } = {}) {
  if (!root) return []
  const storageKey = SNAPSHOT_PREFIX + page
  const glowing = []

  try {
    const previous = JSON.parse(storage.getItem(storageKey) ?? 'null')
    const lastSave = Number(storage.getItem(LAST_SAVE_KEY) ?? 0)
    const fresh = previous && now - previous.savedAt < MAX_AGE_MS && lastSave > previous.savedAt

    if (fresh) {
      for (const [kind, list] of Object.entries(collectRows(root))) {
        const before = previous.rows?.[kind]
        if (!before || Object.keys(before).length === 0) continue
        const changed = list.filter((row) => before[row.key] !== row.signature)
        if (changed.length === 0 || changed.length > MAX_GLOWING) continue
        changed.forEach((row) => {
          row.element.classList.add('ui-row-glow')
          row.element.addEventListener('animationend', () => row.element.classList.remove('ui-row-glow'), { once: true })
          glowing.push(row.element)
        })
      }
    }
  } catch { /* una foto rota no impide usar la lista */ }

  // La foto se toma al salir, con lo que la persona vio por última vez.
  window.addEventListener('pagehide', () => {
    try {
      storage.setItem(storageKey, JSON.stringify({ savedAt: Date.now(), rows: snapshotOf(collectRows(root)) }))
    } catch { /* almacenamiento lleno o bloqueado */ }
  })

  return glowing
}
