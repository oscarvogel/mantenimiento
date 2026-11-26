import { afterEach } from 'vitest'
import { config } from '@vue/test-utils'
import { scrollRevealDirective } from '../src/ui/scrollReveal.js'

config.global.stubs = {
  transition: false,
}
config.global.directives = {
  reveal: scrollRevealDirective,
}

if (typeof HTMLFormElement !== 'undefined') {
  HTMLFormElement.prototype.requestSubmit = function requestSubmit() {
    this.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
  }
}

// jsdom has <dialog> but not its modal methods; mimic the `open` attribute they toggle.
if (typeof HTMLDialogElement !== 'undefined') {
  HTMLDialogElement.prototype.showModal ??= function showModal() { this.setAttribute('open', '') }
  HTMLDialogElement.prototype.close ??= function close() {
    this.removeAttribute('open')
    this.dispatchEvent(new Event('close'))
  }
}

afterEach(() => {
  document.body.innerHTML = ''
  document.body.className = ''
})
