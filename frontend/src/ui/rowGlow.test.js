import { beforeEach, describe, expect, it } from 'vitest'
import { installRowGlow, rememberSuccessfulSave } from './rowGlow.js'

const table = (rows) => `<table><tbody>${rows.map(([href, text]) => `<tr><td><a href="${href}">${text}</a></td></tr>`).join('')}</tbody></table>`

// Simula ver la lista, salir (foto) y volver.
function visit(html, options) {
  document.body.innerHTML = `<main>${html}</main>`
  const glowing = installRowGlow(document.querySelector('main'), options)
  window.dispatchEvent(new Event('pagehide'))
  return glowing.map((row) => row.querySelector('a')?.getAttribute('href') ?? row.textContent.trim())
}

describe('brillo en filas nuevas o editadas', () => {
  beforeEach(() => sessionStorage.clear())

  it('después de guardar con éxito brillan la fila editada y la nueva, no las demás', () => {
    expect(visit(table([['/e/1', 'Camión 1'], ['/e/2', 'Camión 2']]))).toEqual([])

    rememberSuccessfulSave(Date.now() + 10)
    const glowing = visit(table([['/e/1', 'Camión 1'], ['/e/2', 'Camión 2 editado'], ['/e/3', 'Camión 3']]), { now: Date.now() + 20 })

    expect(glowing).toEqual(['/e/2', '/e/3'])
    expect(document.querySelectorAll('.ui-row-glow')).toHaveLength(2)
  })

  it('sin un guardado en el medio no brilla nada, aunque la lista haya cambiado', () => {
    visit(table([['/e/1', 'Camión 1']]))
    expect(visit(table([['/e/1', 'Camión 1 cambiado por otro']]))).toEqual([])
  })

  it('con muchos cambios (otro orden, otra página) no brilla nada', () => {
    visit(table([1, 2, 3, 4, 5, 6].map((n) => [`/e/${n}`, `Camión ${n}`])))
    rememberSuccessfulSave(Date.now() + 10)
    expect(visit(table([7, 8, 9, 10, 11, 12].map((n) => [`/e/${n}`, `Camión ${n}`])), { now: Date.now() + 20 })).toEqual([])
  })

  it('cada lista tiene su foto: otra página no compara contra esta', () => {
    visit(table([['/e/1', 'Camión 1']]), { page: '/equipos' })
    rememberSuccessfulSave(Date.now() + 10)
    expect(visit(table([['/e/1', 'Otro']]), { page: '/equipos?pagina=2', now: Date.now() + 20 })).toEqual([])
  })

  it('también brillan las tarjetas del celular', () => {
    const cards = (text) => `<ul class="divide-y md:hidden"><li><a href="/o/1">${text}</a></li></ul>`
    visit(cards('Orden 1 · Emitida'))
    rememberSuccessfulSave(Date.now() + 10)
    expect(visit(cards('Orden 1 · En proceso'), { now: Date.now() + 20 })).toEqual(['/o/1'])
  })
})
