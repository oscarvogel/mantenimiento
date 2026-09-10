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

afterEach(() => {
  document.body.innerHTML = ''
  document.body.className = ''
})
