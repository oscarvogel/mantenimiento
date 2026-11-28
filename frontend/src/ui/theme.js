const STORAGE_KEY = 'maintenance-theme'
const THEMES = new Set(['light', 'dark'])

export function getStoredTheme() {
  if (typeof window === 'undefined') return null

  try {
    const stored = window.localStorage.getItem(STORAGE_KEY)
    return THEMES.has(stored) ? stored : null
  } catch {
    return null
  }
}

export function getTheme() {
  if (typeof document === 'undefined') return 'light'
  return THEMES.has(document.documentElement.dataset.theme)
    ? document.documentElement.dataset.theme
    : 'light'
}

export function applyTheme(theme) {
  const nextTheme = THEMES.has(theme) ? theme : 'light'
  if (typeof document === 'undefined') return nextTheme

  const root = document.documentElement
  root.dataset.theme = nextTheme
  root.classList.toggle('dark', nextTheme === 'dark')
  root.style.colorScheme = nextTheme

  const themeColor = document.querySelector('meta[name="theme-color"]')
  if (themeColor) {
    themeColor.setAttribute('content', nextTheme === 'dark' ? '#0c1523' : '#f7f9fc')
  }

  try {
    window.localStorage.setItem(STORAGE_KEY, nextTheme)
  } catch {
    // Private browsing or a restricted storage policy should not break the UI.
  }

  window.dispatchEvent(new CustomEvent('maintenance:theme-change', {
    detail: { theme: nextTheme },
  }))

  return nextTheme
}

export function initializeTheme() {
  return applyTheme(getStoredTheme() ?? 'light')
}

let runningTransition = null

// El tema nuevo entra como un corte en diagonal (transitions.css, "Cambio de tema"). Sin la API
// o con movimiento reducido, cambia al instante.
export function toggleTheme() {
  const root = document.documentElement
  // Durante la animación el tema todavía no cambió: el destino pendiente manda.
  const current = root.dataset.themeTransition === 'to-dark' ? 'dark'
    : root.dataset.themeTransition === 'to-light' ? 'light'
      : getTheme()
  const next = current === 'dark' ? 'light' : 'dark'
  const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches

  if (typeof document.startViewTransition !== 'function' || reducedMotion) {
    return applyTheme(next)
  }

  root.dataset.themeTransition = next === 'dark' ? 'to-dark' : 'to-light'
  const transition = document.startViewTransition(() => applyTheme(next))
  runningTransition = transition
  transition.finished.finally(() => {
    // Un segundo clic arranca otra transición: la marca es de esa, no se borra.
    if (runningTransition === transition) {
      delete root.dataset.themeTransition
      runningTransition = null
    }
  })

  return next
}

export { STORAGE_KEY }
