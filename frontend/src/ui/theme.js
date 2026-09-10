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

export function toggleTheme() {
  return applyTheme(getTheme() === 'dark' ? 'light' : 'dark')
}

export { STORAGE_KEY }
