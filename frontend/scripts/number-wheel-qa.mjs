/**
 * Verificación en navegador real del guard de rueda.
 *
 * jsdom no reproduce el comportamiento nativo del navegador (un input[type=number]
 * con el foco puesto cambia de valor al girar la rueda), así que esta prueba se
 * hace con Chromium y eventos de rueda confiables.
 *
 * Comprueba dos cosas:
 *   1. sin el guard, el navegador cambia el valor (comportamiento nativo);
 *   2. con el guard, el valor no cambia y la pantalla se sigue desplazando.
 *
 * Uso: npm run qa:wheel
 */
import { createServer } from 'node:http'
import { readFile } from 'node:fs/promises'
import { extname, join, normalize } from 'node:path'
import { fileURLToPath } from 'node:url'
import { chromium } from 'playwright'

const root = fileURLToPath(new URL('..', import.meta.url))

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
}

const PAGE = `<!DOCTYPE html>
<html><body style="margin:0; height:4000px">
  <input id="precio" type="number" step="0.01" value="11.999" style="width:220px;height:40px">
  <input id="cantidad" type="text" value="11.999" style="width:220px;height:40px">
</body></html>`

const PAGE_WITH_GUARD = `<!DOCTYPE html>
<html><body style="margin:0; height:4000px">
  <input id="precio" type="number" step="0.01" value="11.999" style="width:220px;height:40px">
  <input id="cantidad" type="text" value="11.999" style="width:220px;height:40px">
  <script type="module">
    import { installNumberInputWheelGuard } from '/src/ui/numberInputWheel.js'
    installNumberInputWheelGuard()
  </script>
</body></html>`

const server = createServer(async (request, response) => {
  const url = request.url.split('?')[0]

  if (url === '/wheel-qa.html') {
    response.writeHead(200, { 'content-type': MIME['.html'] })
    response.end(PAGE)
    return
  }

  if (url === '/wheel-qa-guard.html') {
    response.writeHead(200, { 'content-type': MIME['.html'] })
    response.end(PAGE_WITH_GUARD)
    return
  }

  try {
    const filePath = join(root, normalize(url).replace(/^([/\\])+/, ''))
    const body = await readFile(filePath)
    response.writeHead(200, { 'content-type': MIME[extname(filePath)] ?? 'application/octet-stream' })
    response.end(body)
  } catch {
    response.writeHead(404)
    response.end('no encontrado')
  }
})

await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve))
const { port } = server.address()

const browser = await chromium.launch()
const page = await browser.newPage()
const failures = []

async function girarSobrePrecio(path) {
  await page.goto(`http://127.0.0.1:${port}${path}`)
  await page.evaluate(() => {
    window.scrollTo(0, 0)
    document.getElementById('precio').value = '11.999'
  })
  await page.focus('#precio')

  const box = await page.locator('#precio').boundingBox()
  await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2)
  await page.mouse.wheel(0, 120)
  await page.waitForTimeout(150)

  return {
    valor: await page.inputValue('#precio'),
    scrollY: await page.evaluate(() => window.scrollY),
  }
}

const nativo = await girarSobrePrecio('/wheel-qa.html')
console.log(`sin guard  -> valor=${nativo.valor} scrollY=${nativo.scrollY}`)
if (nativo.valor === '11.999') {
  failures.push('el navegador no reprodujo el cambio de valor: la prueba no es representativa')
}

const guardado = await girarSobrePrecio('/wheel-qa-guard.html')
console.log(`con guard  -> valor=${guardado.valor} scrollY=${guardado.scrollY}`)
if (guardado.valor !== '11.999') {
  failures.push(`el guard no evitó el cambio de valor (quedó ${guardado.valor})`)
}
if (guardado.scrollY <= 0) {
  failures.push('con el guard la pantalla dejó de desplazarse')
}

await browser.close()
server.close()

if (failures.length > 0) {
  console.error(`\nQA de rueda FALLÓ:\n- ${failures.join('\n- ')}`)
  process.exit(1)
}

console.log('\nQA de rueda OK: la rueda no cambia los campos numéricos y la pantalla se desplaza.')
