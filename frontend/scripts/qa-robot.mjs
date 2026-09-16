import assert from 'node:assert/strict'
import { mkdir } from 'node:fs/promises'
import { chromium } from 'playwright'

// Isolated frontend only; no credentials, API requests or backend writes.
const url = process.env.ROBOT_PREVIEW_URL ?? 'http://127.0.0.1:5173/dev/robot.html'
const output = new URL('../../writable/chatbot-gsap-qa/', import.meta.url)
await mkdir(output, { recursive: true })
const browser = await chromium.launch({ headless: true })
const errors = []
try {
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } })
  page.on('pageerror', (error) => errors.push(error.message))
  page.on('console', (message) => { if (message.type() === 'error' || message.text().includes('GSAP target')) errors.push(message.text()) })
  for (const variant of ['fab', 'full']) {
  await page.setViewportSize({ width: 1280, height: 1000 })
  await page.emulateMedia({ reducedMotion: 'no-preference' })
  await page.goto(url)
  if (variant === 'full') await page.getByRole('button', { name: 'Cuerpo completo', exact: true }).click()
  await page.locator('.robot-preview__large svg').waitFor()
  await page.waitForFunction(() => document.querySelector('img')?.naturalWidth > 0)
  const animated = page.locator('.robot-preview__large svg')
  const select = (name) => page.getByRole('button', { name, exact: true }).click()
  const capture = (name) => page.screenshot({ path: new URL(`${variant}-${name}.png`, output).pathname.replace(/^\/(\w:)/, '$1'), fullPage: true })
  await capture('desktop-light')

  // The head remains stationary while thinking: eyes and antennae move independently.
  await select('Pensando')
  await page.waitForFunction(() => document.querySelector('.robot-preview__large [data-part="antenna-left"]').hasAttribute('transform'))
  const before = await animated.locator('[data-part="eyes"]').getAttribute('transform')
  await page.waitForTimeout(350)
  assert.notEqual(await animated.locator('[data-part="eyes"]').getAttribute('transform'), before)
  assert.equal(await animated.locator('[data-part="robot"]').getAttribute('transform'), null)
  if (variant === 'full') {
    const board = animated.locator('[data-part="clipboard-assembly"]')
    assert.equal(await board.locator('[data-part="hand-right"]').count(), 1)
    const boardBefore = await board.getAttribute('transform')
    await page.waitForTimeout(350)
    assert.notEqual(await board.getAttribute('transform'), boardBefore)
    await select('Completado')
    await page.waitForTimeout(200)
    assert.notEqual(await animated.locator('[data-part="arm-left"]').getAttribute('transform'), 'matrix(1,0,0,1,0,0)')
    await capture('success')
    await select('Pensando')
  }
  await page.getByRole('button', { name: 'Tema oscuro' }).click()
  await capture('desktop-dark')

  for (const [name, state] of [['Cargando', 'loading'], ['Completado', 'success'], ['Error', 'error'], ['Sin conexión', 'offline'], ['En reposo', 'idle']]) {
    await select(name)
    assert.equal(await animated.getAttribute('data-state'), state)
  }
  for (let i = 0; i < 8; i++) { await select('Error'); await select('Pensando'); await select('Completado') }

  // Live preference changes cancel motion, while state expressions remain visible.
  await page.emulateMedia({ reducedMotion: 'reduce' })
  await select('Pensando')
  await page.waitForTimeout(200)
  const reduced = await animated.evaluate((node) => node.outerHTML)
  await page.waitForTimeout(400)
  assert.equal(await animated.evaluate((node) => node.outerHTML), reduced)
  assert.ok([null, 'matrix(1,0,0,1,0,0)'].includes(await animated.locator('[data-part="eyes"]').getAttribute('transform')))
  assert.equal(await animated.locator('[data-part="status-symbol"]').count(), 1)

  const ids = await page.locator('svg [id]').evaluateAll((nodes) => nodes.map((node) => node.id))
  assert.equal(new Set(ids).size, ids.length)
  await page.getByRole('button', { name: 'Abrir asistente IA' }).focus()
  assert.equal(await page.evaluate(() => document.activeElement.getAttribute('aria-label')), 'Abrir asistente IA')

  await page.setViewportSize({ width: 375, height: 812 })
  assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth))
  await capture('mobile-dark-reduced')
  await page.getByRole('button', { name: 'Tema claro' }).click()
  await capture('mobile-light-reduced')
  }
  assert.deepEqual(errors, [])
  console.log('Robot QA: estados, piezas independientes, múltiples instancias, temas, móvil, foco y reduced-motion OK.')
  console.log(`Capturas: ${output.pathname}`)
} finally {
  await browser.close()
}
