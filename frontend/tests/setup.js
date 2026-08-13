import { afterEach } from 'vitest'
import { config } from '@vue/test-utils'

config.global.stubs = {
  transition: false,
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
