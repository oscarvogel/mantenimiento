import { readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

/**
 * Vite writes absolute paths for dependency entries in its manifest. Keep the
 * checked-in manifest identical across Windows and Linux build environments.
 */
export function normalizeManifest(manifest) {
  const normalizePath = (value) => {
    if (typeof value !== 'string') return value

    const path = value.replaceAll('\\', '/')
    const marker = '/frontend/node_modules/'
    const index = path.toLowerCase().lastIndexOf(marker)

    if (index >= 0) return `node_modules/${path.slice(index + marker.length)}`
    if (path.toLowerCase().startsWith('frontend/node_modules/')) {
      return `node_modules/${path.slice('frontend/node_modules/'.length)}`
    }

    return value
  }

  const entries = Object.entries(manifest).map(([key, entry]) => [
    normalizePath(key),
    Object.fromEntries(Object.entries(entry).map(([field, value]) => [
      field,
      Array.isArray(value) ? value.map(normalizePath) : normalizePath(value),
    ])),
  ])

  entries.sort(([left], [right]) => (left < right ? -1 : left > right ? 1 : 0))
  return Object.fromEntries(entries)
}

export function normalizeManifestFile(path) {
  const manifest = JSON.parse(readFileSync(path, 'utf8'))
  writeFileSync(path, `${JSON.stringify(normalizeManifest(manifest), null, 2)}\n`)
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const manifestPath = new URL('../../assets/dashboard/.vite/manifest.json', import.meta.url)
  normalizeManifestFile(manifestPath)
}
