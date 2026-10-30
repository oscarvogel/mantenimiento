import { describe, expect, it } from 'vitest'
import { normalizeManifest } from '../scripts/normalize-vite-manifest.mjs'

describe('normalizeManifest', () => {
  it('normalizes dependency paths from Windows and Linux builds to the same key', () => {
    const windows = normalizeManifest({
      'C:\\Programacion\\mantenimiento\\frontend\\node_modules\\gsap\\index.js': {
        file: 'assets/gsap-123.js',
        src: 'C:\\Programacion\\mantenimiento\\frontend\\node_modules\\gsap\\index.js',
      },
    })
    const linux = normalizeManifest({
      '/home/runner/work/mantenimiento/mantenimiento/frontend/node_modules/gsap/index.js': {
        file: 'assets/gsap-123.js',
        src: '/home/runner/work/mantenimiento/mantenimiento/frontend/node_modules/gsap/index.js',
      },
    })

    expect(windows).toEqual(linux)
    expect(Object.keys(windows)).toEqual(['node_modules/gsap/index.js'])
  })

  it('leaves project source keys and emitted bundle names unchanged', () => {
    expect(normalizeManifest({
      'src/main.js': {
        file: 'assets/main-123.js',
        imports: ['_vendor-456.js'],
      },
    })).toEqual({
      'src/main.js': {
        file: 'assets/main-123.js',
        imports: ['_vendor-456.js'],
      },
    })
  })
})
