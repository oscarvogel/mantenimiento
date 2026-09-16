import assert from 'node:assert/strict'
import { mkdir, writeFile } from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { chromium } from 'playwright'

const here = path.dirname(fileURLToPath(import.meta.url))
const projectRoot = path.resolve(here, '..', '..')
const outputDir = path.join(projectRoot, 'writable', 'motion-qa')
const baseUrl = process.env.QA_BASE_URL ?? 'http://127.0.0.1:8080'
const routes = (process.env.MOTION_QA_ROUTES ?? '/dashboard,/mantenimiento/ordenes,/mantenimiento/solicitudes,/mantenimiento/importaciones')
  .split(',')
  .map((route) => route.trim())
  .filter(Boolean)
const tenantEmail = process.env.QA_TENANT_EMAIL
const tenantPassword = process.env.QA_TENANT_PASSWORD
const edgePath = process.env.QA_EDGE_PATH ?? 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe'

await mkdir(outputDir, { recursive: true })

const browser = await chromium.launch({ headless: true, executablePath: edgePath })
const results = { routes: [], consoleErrors: [], requestFailures: [], reducedMotion: [] }

function monitor(page, route) {
  page.on('pageerror', (error) => results.consoleErrors.push({ route, text: error.message }))
  page.on('console', (message) => {
    if (message.type() === 'error' && !message.text().includes('Failed to load resource')) {
      results.consoleErrors.push({ route, text: message.text() })
    }
  })
  page.on('response', (response) => {
    if (response.status() >= 400 && !response.url().endsWith('/favicon.ico')) {
      results.requestFailures.push({ route, url: response.url(), error: `HTTP ${response.status()}` })
    }
  })
  page.on('requestfailed', (request) => {
    results.requestFailures.push({ route, url: request.url(), error: request.failure()?.errorText ?? 'unknown' })
  })
}

async function login(page) {
  if (!tenantEmail || !tenantPassword) return false
  await page.goto(`${baseUrl}/login`, { waitUntil: 'networkidle' })
  if (!await page.locator('input[name="email"]').count()) return false
  await page.locator('input[name="email"]').fill(tenantEmail)
  await page.locator('input[name="password"]').fill(tenantPassword)
  await Promise.all([
    page.waitForURL(/\/dashboard$/),
    page.locator('button[type="submit"]').click(),
  ])
  return true
}

async function inspect(page, route, reducedMotion) {
  const response = await page.goto(`${baseUrl}${route}`, { waitUntil: 'networkidle' })
  assert.ok(response?.ok(), `${route} respondió ${response?.status()}`)
  const geometry = await page.evaluate(() => ({
    bodyWidth: document.body.scrollWidth,
    viewportWidth: document.documentElement.clientWidth,
    visibleDialogs: [...document.querySelectorAll('[role="dialog"]')].filter((node) => node.offsetParent !== null).length,
    motionElements: document.querySelectorAll('[data-motion-item], [data-notification-item], [data-notification-panel]').length,
  }))
  assert.ok(geometry.bodyWidth <= geometry.viewportWidth + 1, `${route} tiene overflow horizontal`)
  results.routes.push({ route, reducedMotion, ...geometry })
}

try {
  const context = await browser.newContext({ viewport: { width: 1280, height: 800 } })
  const page = await context.newPage()
  monitor(page, 'auth')
  await login(page)
  for (const route of routes) {
    monitor(page, route)
    await page.emulateMedia({ reducedMotion: 'reduce' })
    await inspect(page, route, true)
    await page.emulateMedia({ reducedMotion: 'no-preference' })
    await inspect(page, route, false)
  }
  await context.close()
} finally {
  await browser.close()
}

await writeFile(path.join(outputDir, 'results.json'), `${JSON.stringify(results, null, 2)}\n`, 'utf8')

if (results.consoleErrors.length || results.requestFailures.length) {
  throw new Error(`Motion QA registró ${results.consoleErrors.length} errores y ${results.requestFailures.length} requests fallidos.`)
}

console.log(`Motion QA OK: ${results.routes.length} visitas, reduced-motion y movimiento normal.`)
