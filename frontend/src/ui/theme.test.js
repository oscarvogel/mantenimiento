import { beforeEach, describe, expect, it } from 'vitest'
import {
  STORAGE_KEY,
  applyTheme,
  getStoredTheme,
  getTheme,
  initializeTheme,
  toggleTheme,
} from './theme.js'

describe('theme selector', () => {
  beforeEach(() => {
    localStorage.clear()
    document.documentElement.removeAttribute('data-theme')
    document.documentElement.classList.remove('dark')
    document.documentElement.style.colorScheme = ''
    document.head.innerHTML = '<meta id="theme-color" name="theme-color" content="#f7f9fc">'
  })

  it('applies the theme to the document and persists the choice', () => {
    expect(applyTheme('dark')).toBe('dark')
    expect(getTheme()).toBe('dark')
    expect(document.documentElement.classList.contains('dark')).toBe(true)
    expect(document.documentElement.style.colorScheme).toBe('dark')
    expect(document.querySelector('#theme-color').content).toBe('#0c1523')
    expect(localStorage.getItem(STORAGE_KEY)).toBe('dark')
  })

  it('toggles between light and dark', () => {
    applyTheme('light')
    expect(toggleTheme()).toBe('dark')
    expect(toggleTheme()).toBe('light')
  })

  it('initializes from the stored value and ignores invalid values', () => {
    localStorage.setItem(STORAGE_KEY, 'dark')
    expect(getStoredTheme()).toBe('dark')
    expect(initializeTheme()).toBe('dark')

    localStorage.setItem(STORAGE_KEY, 'sepia')
    expect(getStoredTheme()).toBeNull()
  })
})
