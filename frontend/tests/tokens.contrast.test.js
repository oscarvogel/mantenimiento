import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

// Guards the Vogel palette (docs/DESIGN_TOKENS.md): text pairs need WCAG AA and
// the sidebar must differ from the canvas, which text-contrast tools never check.
const css = readFileSync(resolve(__dirname, '../src/styles.css'), 'utf8')

const block = (selector) => {
  const start = css.indexOf(`${selector} {`)
  return css.slice(start, css.indexOf('\n}', start))
}

const declarations = (text) => Object.fromEntries(
  [...text.matchAll(/--([\w-]+):\s*([^;]+);/g)].map(([, name, value]) => [name, value.trim()]),
)

const light = declarations(block(':root'))
const themes = {
  light,
  dark: { ...light, ...declarations(block("html[data-theme='dark']")) },
}

const channels = (vars, name) => {
  let value = vars[name]
  for (let depth = 0; value?.startsWith('var('); depth += 1) {
    if (depth > 5) throw new Error(`Circular token --${name}`)
    value = vars[value.slice(6, -1)]
  }
  if (!value) throw new Error(`Missing token --${name}`)
  return value.split(/\s+/).map(Number)
}

const luminance = (rgb) => {
  const [r, g, b] = rgb.map((c) => {
    const v = c / 255
    return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4
  })
  return 0.2126 * r + 0.7152 * g + 0.0722 * b
}

const contrast = (vars, a, b) => {
  const [x, y] = [luminance(channels(vars, a)), luminance(channels(vars, b))].sort((p, q) => q - p)
  return (x + 0.05) / (y + 0.05)
}

const TEXT = 4.5
const NON_TEXT = 3
const SURFACE_GAP = 1.3

const pairs = [
  ['ink', 'surface', TEXT],
  ['ink-muted', 'surface', TEXT],
  ['ink-subtle', 'surface-subtle', TEXT],
  ['primary', 'surface', TEXT],
  ['primary-foreground', 'primary', TEXT],
  ['accent-foreground', 'accent', TEXT],
  ['warning-strong', 'surface', TEXT],
  ['warning-foreground', 'warning-subtle', TEXT],
  ['warning-foreground', 'surface', TEXT],
  ['secondary-900', 'warning', TEXT],
  ['danger-strong', 'surface', TEXT],
  ['success-strong', 'surface', TEXT],
  ['ink-inverse', 'surface-inverse', TEXT],
  ['border-focus', 'surface', NON_TEXT],
  ['surface-inverse', 'surface-subtle', SURFACE_GAP],
  // Marco del shell: texto y marca activa sobre el marino, y el lienzo debe despegarse del marco.
  ['ink-inverse', 'frame', TEXT],
  ['accent-400', 'frame', NON_TEXT],
  ['surface-subtle', 'frame', SURFACE_GAP],
]

// The navy sidebar uses fixed steps: secondary-900 in light, surface-inverse in dark.
const sidebarPairs = [
  ['secondary-100', 'secondary-900', TEXT],
  ['secondary-200', 'secondary-900', TEXT],
  ['secondary-100', 'surface-inverse', TEXT],
  ['secondary-200', 'surface-inverse', TEXT],
  ['secondary-300', 'surface-inverse', NON_TEXT],
  ['ink-inverse', 'primary-600', TEXT],
]

describe.each(Object.entries(themes))('tokens del tema %s', (theme, vars) => {
  it.each(pairs)('%s sobre %s alcanza %d:1', (fg, bg, min) => {
    expect(contrast(vars, fg, bg)).toBeGreaterThanOrEqual(min)
  })

  it.each(sidebarPairs)('barra lateral: %s sobre %s alcanza %d:1', (fg, bg, min) => {
    expect(contrast(vars, fg, bg)).toBeGreaterThanOrEqual(min)
  })

  it('distingue "por vencer" del acento de marca', () => {
    expect(channels(vars, 'warning')).not.toEqual(channels(vars, 'accent'))
  })
})

describe('escalas de marca', () => {
  it.each(['primary', 'secondary', 'accent', 'neutral'])('%s tiene los pasos 100 a 900', (name) => {
    for (let step = 100; step <= 900; step += 100) {
      expect(channels(light, `${name}-${step}`)).toHaveLength(3)
    }
  })
})
