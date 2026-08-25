import { describe, expect, it } from 'vitest'
import { chatbotEndpoint } from '../../src/pages/operations/components/chatbotUrls'

describe('chatbotEndpoint', () => {
  it('builds chatbot URLs from the root base used by staging', () => {
    expect(chatbotEndpoint('conversaciones', '/')).toBe('/mantenimiento/chatbot/conversaciones')
  })

  it('builds chatbot URLs from the subdirectory base used by Ferozo', () => {
    expect(chatbotEndpoint('mensajes', 'https://vogelconsultoria.com.ar/mantenimiento/'))
      .toBe('https://vogelconsultoria.com.ar/mantenimiento/mantenimiento/chatbot/mensajes')
  })

  it('reads the base URL exposed by any authenticated server-rendered page', () => {
    document.body.dataset.baseUrl = 'https://vogelconsultoria.com.ar/mantenimiento/'

    expect(chatbotEndpoint('historial'))
      .toBe('https://vogelconsultoria.com.ar/mantenimiento/mantenimiento/chatbot/historial')
  })
})
