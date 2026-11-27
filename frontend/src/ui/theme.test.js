import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
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

  describe('con la API de transiciones', () => {
    let finish
    let reducedMotion

    beforeEach(() => {
      reducedMotion = false
      window.matchMedia = vi.fn(() => ({ matches: reducedMotion }))
      document.startViewTransition = vi.fn((update) => {
        update()
        return { finished: new Promise((resolve) => { finish = resolve }) }
      })
    })

    afterEach(() => {
      delete document.startViewTransition
      delete window.matchMedia
      delete document.documentElement.dataset.themeTransition
    })

    it('anima el cambio y marca la dirección de la diagonal mientras dura', async () => {
      applyTheme('light')

      expect(toggleTheme()).toBe('dark')
      expect(document.startViewTransition).toHaveBeenCalledTimes(1)
      expect(document.documentElement.dataset.themeTransition).toBe('to-dark')
      expect(getTheme()).toBe('dark')

      finish()
      await Promise.resolve()
      await Promise.resolve()
      expect(document.documentElement.dataset.themeTransition).toBeUndefined()
    })

    it('al volver a claro barre en sentido contrario', () => {
      applyTheme('dark')
      expect(toggleTheme()).toBe('light')
      expect(document.documentElement.dataset.themeTransition).toBe('to-light')
    })

    it('con movimiento reducido cambia al instante', () => {
      reducedMotion = true
      applyTheme('light')

      expect(toggleTheme()).toBe('dark')
      expect(document.startViewTransition).not.toHaveBeenCalled()
      expect(document.documentElement.dataset.themeTransition).toBeUndefined()
    })
  })

  it('initializes from the stored value and ignores invalid values', () => {
    localStorage.setItem(STORAGE_KEY, 'dark')
    expect(getStoredTheme()).toBe('dark')
    expect(initializeTheme()).toBe('dark')

    localStorage.setItem(STORAGE_KEY, 'sepia')
    expect(getStoredTheme()).toBeNull()
  })
})
