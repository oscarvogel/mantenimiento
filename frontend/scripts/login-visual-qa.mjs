import { chromium } from 'playwright'
import { mkdir, writeFile } from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const here = path.dirname(fileURLToPath(import.meta.url))
const projectRoot = path.resolve(here, '..', '..')
const outputDir = path.join(projectRoot, 'docs', 'screenshots', 'login')
const baseUrl = process.env.QA_BASE_URL ?? 'http://127.0.0.1:8080'
const edgePath = process.env.QA_EDGE_PATH ?? 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe'

await mkdir(outputDir, { recursive: true })

const browser = await chromium.launch({ headless: true, executablePath: edgePath })
const results = { screenshots: [], consoleErrors: [], requestFailures: [], httpErrors: [], checks: [] }

async function capture(viewport, name) {
  const context = await browser.newContext({ viewport, deviceScaleFactor: 1, reducedMotion: 'reduce' })
  const page = await context.newPage()

  page.on('console', (message) => {
    if (message.type() === 'error') results.consoleErrors.push({ name, text: message.text(), location: message.location() })
  })
  page.on('requestfailed', (request) => {
    results.requestFailures.push({ name, url: request.url(), error: request.failure()?.errorText ?? 'unknown' })
  })
  page.on('response', (response) => {
    if (response.status() >= 400) {
      results.httpErrors.push({ name, url: response.url(), status: response.status() })
    }
  })

  const response = await page.goto(`${baseUrl}/login`, { waitUntil: 'networkidle' })
  if (!response?.ok()) throw new Error(`/login respondió ${response?.status()}`)

  await page.getByRole('heading', { level: 1, name: 'Ingreso' }).waitFor({ state: 'visible' })
  await page.locator('input[name="email"]').fill('usuario@empresa.test')
  await page.locator('input[name="password"]').fill('clave-segura')
  await page.getByRole('button', { name: 'Mostrar contraseña' }).click()
  if (await page.locator('input[name="password"]').getAttribute('type') !== 'text') {
    throw new Error('El control de visibilidad de contraseña no respondió.')
  }
  await page.getByRole('button', { name: 'Ocultar contraseña' }).click()

  const geometry = await page.evaluate(() => ({
    bodyWidth: document.body.scrollWidth,
    viewportWidth: document.documentElement.clientWidth,
    bodyHeight: document.body.scrollHeight,
    viewportHeight: document.documentElement.clientHeight,
    mainCount: document.querySelectorAll('main').length,
    formCount: document.querySelectorAll('form[action*="login/authenticate"]').length,
    csrfPresent: Boolean(document.querySelector('form input[type="hidden"][name]')),
    backgroundLoaded: Array.from(document.images).every((image) => image.complete),
  }))
  results.checks.push({ name, viewport, ...geometry })

  if (geometry.bodyWidth > geometry.viewportWidth + 1) {
    throw new Error(`${name} tiene overflow horizontal: ${geometry.bodyWidth}px > ${geometry.viewportWidth}px`)
  }
  if (geometry.mainCount !== 1 || geometry.formCount !== 1 || !geometry.csrfPresent) {
    throw new Error(`${name} no conserva la estructura funcional esperada.`)
  }

  await page.locator('input[name="email"]').fill('')
  await page.locator('input[name="password"]').fill('')
  const target = path.join(outputDir, `login-${name}.png`)
  await page.screenshot({ path: target, fullPage: true })
  results.screenshots.push(path.relative(projectRoot, target).replaceAll('\\', '/'))
  await context.close()
}

try {
  await capture({ width: 1906, height: 943 }, 'desktop')
  await capture({ width: 390, height: 844 }, 'mobile')
} finally {
  await browser.close()
}

await writeFile(path.join(outputDir, 'login-visual-qa-results.json'), `${JSON.stringify(results, null, 2)}\n`, 'utf8')

if (results.consoleErrors.length || results.requestFailures.length || results.httpErrors.length) {
  throw new Error(`QA registró ${results.consoleErrors.length} errores de consola, ${results.requestFailures.length} requests fallidos y ${results.httpErrors.length} respuestas HTTP con error.`)
}

process.stdout.write(`${JSON.stringify(results, null, 2)}\n`)
