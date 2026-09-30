/**
 * Verifica que los bundles versionados en assets/dashboard correspondan al
 * source frontend (#432). Reproduce localmente el paso de CI que falla cuando
 * el source Vue se modifica sin regenerar y commitear los bundles.
 *
 *   npm run verify:build-sync
 *
 * Salida 0: el build reproducible no cambio assets/dashboard.
 * Salida 1: hay drift; hay que reconstruir y commitear los bundles.
 */
import { execFileSync, spawnSync } from 'node:child_process'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { build } from 'vite'

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..')
const FRONTEND = resolve(ROOT, 'frontend')
const ASSETS = 'assets/dashboard'

function git(args) {
  return execFileSync('git', ['-C', ROOT, ...args], { encoding: 'utf8' })
}

function encodingQa(mode) {
  const result = spawnSync(
    process.execPath,
    [resolve(FRONTEND, 'scripts', 'encoding-qa.mjs'), mode],
    { cwd: FRONTEND, stdio: 'inherit' },
  )
  if (result.status !== 0) {
    console.error(`BUILD_SYNC=FAIL la QA de encoding (${mode}) fallo`)
    process.exit(result.status ?? 1)
  }
}

console.log('BUILD_SYNC=1/3 QA de encoding del source')
encodingQa('source')

console.log('BUILD_SYNC=2/3 build reproducible')
try {
  await build({ root: FRONTEND, logLevel: 'warn' })
} catch (error) {
  console.error(`BUILD_SYNC=FAIL el build frontend fallo: ${error?.message ?? error}`)
  process.exit(1)
}

console.log('BUILD_SYNC=3/3 QA de encoding del build')
encodingQa('build')

// Se comparan los cambios NO staged, no contra HEAD: asi el comando sirve igual
// como paso previo a commitear (el dev ya staged los bundles) y dentro de CI
// (checkout limpio: cualquier cambio del build queda unstaged y se ve).
const modified = git(['diff', '--name-only', '--', ASSETS]).trim()
const untracked = git(['ls-files', '--others', '--exclude-standard', '--', ASSETS]).trim()
const drift = [modified, untracked].filter((block) => block !== '').join('\n')

if (drift) {
  console.error('')
  console.error(`BUILD_SYNC=FAIL ${ASSETS} no coincide con el build del source.`)
  console.error('Archivos con drift:')
  for (const line of drift.split('\n')) console.error(`  ${line}`)
  console.error('')
  console.error(`Para corregir: reconstruir y commitear ${ASSETS} junto al cambio de source.`)
  process.exit(1)
}

console.log(`BUILD_SYNC=OK los bundles versionados coinciden con el source`)
