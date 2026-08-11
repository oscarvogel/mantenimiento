import { chromium } from 'playwright'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { dirname, resolve } from 'node:path'

const here = dirname(fileURLToPath(import.meta.url))
const root = resolve(here, '../..')
const source = pathToFileURL(resolve(root, 'assets/pwa/icon.svg')).href
const browser = await chromium.launch({ headless: true })
try {
  for (const size of [192, 512]) {
    const page = await browser.newPage({ viewport: { width: size, height: size }, deviceScaleFactor: 1 })
    await page.goto(source)
    await page.screenshot({ path: resolve(root, `assets/pwa/icon-${size}.png`), omitBackground: true })
    await page.close()
  }
} finally {
  await browser.close()
}
