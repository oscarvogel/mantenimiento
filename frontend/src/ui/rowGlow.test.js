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

  it('si cambió el estado, la etiqueta arranca con el color anterior; si no, no se toca', () => {
    const order = (text, tone) => `<article data-glow-row><a href="/o/${text}">OT ${text}</a><span data-status-tone="${tone}">x</span></article>`
    visit(order(1, 'info') + order(2, 'info'))
    rememberSuccessfulSave(Date.now() + 10)
    // La 1 pasa a otro estado; la 2 cambia de texto pero sigue con el mismo tono.
    visit(order(1, 'success') + order('2 editada', 'info').replace('/o/2 editada', '/o/2'), { now: Date.now() + 20 })

    const [first, second] = document.querySelectorAll('[data-status-tone]')
    expect(first.classList).toContain('ui-status-changed')
    expect(first.dataset.statusFrom).toBe('info')
    expect(second.classList).not.toContain('ui-status-changed')

    // Al terminar la etiqueta, la fila sigue brillando: su fin no se confunde con el de la fila.
    first.dispatchEvent(new Event('animationend', { bubbles: true }))
    expect(first.classList).not.toContain('ui-status-changed')
    expect(first.dataset.statusFrom).toBeUndefined()
    expect(first.closest('article').classList).toContain('ui-row-glow')
  })

  it('también brillan las tarjetas del celular', () => {
    const cards = (text) => `<ul class="divide-y md:hidden"><li><a href="/o/1">${text}</a></li></ul>`
    visit(cards('Orden 1 · Emitida'))
    rememberSuccessfulSave(Date.now() + 10)
    expect(visit(cards('Orden 1 · En proceso'), { now: Date.now() + 20 })).toEqual(['/o/1'])
  })
})
