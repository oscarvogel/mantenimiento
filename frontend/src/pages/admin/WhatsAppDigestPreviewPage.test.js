import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import WhatsAppDigestPreviewPage from './WhatsAppDigestPreviewPage.vue'

describe('WhatsAppDigestPreviewPage', () => {
  it('lets a superadmin select a company and inspect each recipient message without sending it', () => {
    const wrapper = mount(WhatsAppDigestPreviewPage, {
      props: {
        data: {
          companies: [{ id: 12, name: 'TSA', whatsappEnabled: true }],
          selectedCompanyId: 12,
          selectedCompanyName: 'TSA',
          companyWhatsAppEnabled: true,
          globalWhatsAppEnabled: true,
          whatsAppAvailable: true,
          pilotEnabled: false,
          previews: [{
            userId: 8,
            userName: 'Carlos Gómez',
            target: 'Celular del usuario',
            itemCount: 2,
            message: '*TSA · Mantenimiento*\nHola Carlos 👋',
          }],
          actions: { preview: '/superadmin/whatsapp/resumen-diario' },
        },
      },
    })

    expect(wrapper.get('select[name="empresa_id"]').element.value).toBe('12')
    expect(wrapper.text()).toContain('Carlos Gómez')
    expect(wrapper.text()).toContain('Celular del usuario')
    expect(wrapper.get('pre').text()).toContain('Hola Carlos 👋')
    expect(wrapper.text()).toContain('La vista previa no envía mensajes')
    expect(wrapper.find('button[type="submit"]').text()).toContain('Ver mensajes')
  })

  it('explains when the selected company has no pending digest recipients', () => {
    const wrapper = mount(WhatsAppDigestPreviewPage, {
      props: {
        data: {
          companies: [{ id: 12, name: 'TSA', whatsappEnabled: true }],
          selectedCompanyId: 12,
          selectedCompanyName: 'TSA',
          companyWhatsAppEnabled: true,
          globalWhatsAppEnabled: true,
          whatsAppAvailable: true,
          pilotEnabled: false,
          previews: [],
          actions: { preview: '/superadmin/whatsapp/resumen-diario' },
        },
      },
    })

    expect(wrapper.text()).toContain('No hay mensajes pendientes')
  })
})
