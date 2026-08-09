import { chromium } from 'playwright'
import { mkdir, writeFile } from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const here = path.dirname(fileURLToPath(import.meta.url))
const projectRoot = path.resolve(here, '..', '..')
const outputDir = path.join(projectRoot, 'docs', 'screenshots', 'ui')
const baseUrl = process.env.QA_BASE_URL ?? 'http://127.0.0.1:8080'
const tenantEmail = process.env.QA_TENANT_EMAIL
const tenantPassword = process.env.QA_TENANT_PASSWORD
const superadminEmail = process.env.QA_SUPERADMIN_EMAIL
const superadminPassword = process.env.QA_SUPERADMIN_PASSWORD
const edgePath = process.env.QA_EDGE_PATH ?? 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe'

if (!tenantEmail || !tenantPassword || !superadminEmail || !superadminPassword) {
  throw new Error('Definí QA_TENANT_EMAIL, QA_TENANT_PASSWORD, QA_SUPERADMIN_EMAIL y QA_SUPERADMIN_PASSWORD.')
}

await mkdir(outputDir, { recursive: true })

const browser = await chromium.launch({ headless: true, executablePath: edgePath })
const results = { screenshots: [], consoleErrors: [], requestFailures: [], checks: [] }

async function login(page, email, password) {
  await page.goto(`${baseUrl}/login`, { waitUntil: 'networkidle' })
  await page.locator('input[name="email"]').fill(email)
  await page.locator('input[name="password"]').fill(password)
  await Promise.all([
    page.waitForURL(/\/dashboard$/),
    page.locator('button[type="submit"]').click(),
  ])
}

function monitor(page, label) {
  page.on('console', (message) => {
    if (message.type() === 'error') results.consoleErrors.push({ label, text: message.text() })
  })
  page.on('requestfailed', (request) => {
    results.requestFailures.push({ label, url: request.url(), error: request.failure()?.errorText ?? 'unknown' })
  })
}

async function payload(page) {
  const text = await page.locator('#maintenance-app-data').textContent()
  return JSON.parse(text ?? '{}')
}

async function capture(context, route, name, viewportName) {
  const page = await context.newPage()
  monitor(page, `${viewportName}:${name}`)
  const response = await page.goto(`${baseUrl}${route}`, { waitUntil: 'networkidle' })
  if (!response?.ok()) throw new Error(`${route} respondió ${response?.status()}`)
  await page.locator('#main-content').waitFor({ state: 'visible' })

  const geometry = await page.evaluate(() => ({
    bodyWidth: document.body.scrollWidth,
    viewportWidth: document.documentElement.clientWidth,
    mainCount: document.querySelectorAll('main').length,
    title: document.title,
  }))
  results.checks.push({ route, viewportName, ...geometry })
  if (geometry.bodyWidth > geometry.viewportWidth + 1) {
    throw new Error(`${route} tiene overflow horizontal: ${geometry.bodyWidth}px > ${geometry.viewportWidth}px`)
  }
  if (geometry.mainCount !== 1) throw new Error(`${route} debe tener un único main; tiene ${geometry.mainCount}`)

  if (viewportName === 'mobile') {
    const menuButton = page.getByRole('button', { name: /Abrir men/i })
    await menuButton.click()
    const closeControls = page.locator('[aria-label="Cerrar menú principal"]')
    await closeControls.first().waitFor({ state: 'visible' })
    const locked = await page.evaluate(() => document.body.classList.contains('overflow-hidden'))
    if (!locked) throw new Error(`${route} no bloqueó el body con el menú móvil abierto`)
    await page.keyboard.press('Escape')
    await closeControls.first().waitFor({ state: 'detached' })
  }

  const target = path.join(outputDir, `${name}-${viewportName}.png`)
  await page.screenshot({ path: target, fullPage: true })
  results.screenshots.push(path.relative(projectRoot, target).replaceAll('\\', '/'))
  await page.close()
}

async function tenantRun(viewport, viewportName) {
  const context = await browser.newContext({ viewport, deviceScaleFactor: 1, reducedMotion: 'reduce' })
  const authPage = await context.newPage()
  await login(authPage, tenantEmail, tenantPassword)
  await authPage.goto(`${baseUrl}/mantenimiento/equipos`, { waitUntil: 'networkidle' })
  const assetsPayload = await payload(authPage)
  const equipmentId = assetsPayload?.data?.equipment?.items?.[0]?.id ?? null
  await authPage.goto(`${baseUrl}/mantenimiento/importaciones`, { waitUntil: 'networkidle' })
  const importsPayload = await payload(authPage)
  const importId = importsPayload?.data?.imports?.items?.[0]?.id ?? null
  await authPage.close()

  const routes = [
    ['/dashboard', 'dashboard'],
    ['/mantenimiento/equipos', 'equipos'],
    ['/mantenimiento', 'servicios'],
    ['/mantenimiento/importaciones', 'importaciones'],
    ['/administracion/sucursales', 'sucursales'],
    ['/administracion/usuarios', 'usuarios'],
    ['/reportes', 'reportes'],
  ]
  if (equipmentId) routes.splice(2, 0, [`/mantenimiento/equipos/${equipmentId}`, 'ficha-equipo'])
  if (importId) routes.splice(5, 0, [`/mantenimiento/importaciones/${importId}`, 'detalle-importacion'])

  for (const [route, name] of routes) await capture(context, route, name, viewportName)
  await context.close()
}

async function superadminRun(viewport, viewportName) {
  const context = await browser.newContext({ viewport, deviceScaleFactor: 1, reducedMotion: 'reduce' })
  const authPage = await context.newPage()
  await login(authPage, superadminEmail, superadminPassword)
  await authPage.close()
  await capture(context, '/superadmin', 'superadmin', viewportName)
  await context.close()
}

try {
  await tenantRun({ width: 1440, height: 900 }, 'desktop')
  await superadminRun({ width: 1440, height: 900 }, 'desktop')
  await tenantRun({ width: 390, height: 844 }, 'mobile')
  await superadminRun({ width: 390, height: 844 }, 'mobile')
} finally {
  await browser.close()
}

await writeFile(path.join(outputDir, 'visual-qa-results.json'), `${JSON.stringify(results, null, 2)}\n`, 'utf8')

if (results.consoleErrors.length || results.requestFailures.length) {
  throw new Error(`QA registró ${results.consoleErrors.length} errores de consola y ${results.requestFailures.length} requests fallidos.`)
}

process.stdout.write(`${JSON.stringify(results, null, 2)}\n`)
