import { readdirSync, readFileSync, statSync } from 'node:fs'
import { dirname, join, resolve, sep } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

// El layout es plano: el bundle publicado vive en <raiz>/assets/dashboard y lo
// consume app/Views/app.php leyendo el manifest de Vite. Si el manifest y los
// archivos del disco dejan de coincidir, produccion sirve una UI que no
// corresponde con el source versionado (issue #432).
const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..')
const DASHBOARD = join(ROOT, 'assets', 'dashboard')
const MANIFEST_PATH = join(DASHBOARD, '.vite', 'manifest.json')
const ASSETS_DIR = join(DASHBOARD, 'assets')

function readManifest() {
  return JSON.parse(readFileSync(MANIFEST_PATH, 'utf8'))
}

/** Cierre transitivo de todo lo que el manifest declara alcanzable. */
function referencedFiles(manifest) {
  const referenced = new Set()

  for (const entry of Object.values(manifest)) {
    if (entry?.file) referenced.add(entry.file)
    for (const css of entry?.css ?? []) referenced.add(css)
    for (const imported of [...(entry?.imports ?? []), ...(entry?.dynamicImports ?? [])]) {
      const importedEntry = manifest[imported]
      if (importedEntry?.file) referenced.add(importedEntry.file)
    }
  }

  return referenced
}

function committedBundleFiles() {
  return readdirSync(ASSETS_DIR)
    .filter((name) => statSync(join(ASSETS_DIR, name)).isFile())
    .map((name) => `assets/${name}`)
    .sort()
}

describe('assets/dashboard versionado', () => {
  it('expone el manifest de Vite con la entrada principal', () => {
    const manifest = readManifest()
    const entry = manifest['src/main.js']

    expect(entry).toBeDefined()
    expect(entry.isEntry).toBe(true)
    expect(entry.file).toMatch(/^assets\/main-[A-Za-z0-9_-]+\.js$/)
  })

  it('resuelve en disco cada archivo que el manifest declara', () => {
    const manifest = readManifest()
    const referenced = referencedFiles(manifest)

    expect(referenced.size).toBeGreaterThan(0)
    for (const file of referenced) {
      expect(statSync(join(DASHBOARD, file)).isFile(), `falta ${file}`).toBe(true)
    }
  })

  it('no deja bundles huerfanos versionados junto al manifest', () => {
    const referenced = referencedFiles(readManifest())
    const orphans = committedBundleFiles().filter((file) => !referenced.has(file))

    // Un huerfano versionado se publicaria en el webroot sin estar en el
    // manifest: nadie lo carga, pero ocupa espacio y confunde el rollback.
    expect(orphans).toEqual([])
  })

  it('solo versiona assets dentro de assets/dashboard/assets', () => {
    const stray = readdirSync(DASHBOARD).filter((name) => !['.vite', 'assets'].includes(name))

    expect(stray).toEqual([])
  })

  it('mantiene los nombres de archivo con hash de contenido de Vite', () => {
    for (const file of committedBundleFiles()) {
      expect(file, `no parece un bundle de Vite: ${file}`).toMatch(
        /^assets\/[A-Za-z0-9_.-]+-[A-Za-z0-9_-]{8}\.(js|css)$/,
      )
    }
  })

  it('no deja rutas que escapen del directorio del dashboard', () => {
    // El manifest se interpola contra FCPATH en la vista. Un ".." o una ruta
    // absoluta permitiria leer o servir archivos fuera de assets/dashboard.
    for (const rel of referencedFiles(readManifest())) {
      expect(rel.startsWith('assets/'), `ruta relativa inesperada: ${rel}`).toBe(true)
      expect(rel.split('/').some((part) => part === '..')).toBe(false)
      expect(rel.includes(':')).toBe(false)
      expect(resolve(DASHBOARD, rel).startsWith(DASHBOARD + sep)).toBe(true)
    }
  })
})

describe('consumo del manifest desde la vista PHP', () => {
  it('app.php resuelve la misma clave de entrada que usa el manifest', () => {
    const view = readFileSync(join(ROOT, 'app', 'Views', 'app.php'), 'utf8')
    const manifest = readManifest()
    const entry = manifest['src/main.js']

    expect(view).toContain('assets/dashboard/.vite/manifest.json')
    expect(view).toContain("$manifest['src/main.js']")
    expect(statSync(join(DASHBOARD, entry.file)).isFile()).toBe(true)
    for (const stylesheet of entry.css ?? []) {
      expect(statSync(join(DASHBOARD, stylesheet)).isFile()).toBe(true)
    }
  })
})
