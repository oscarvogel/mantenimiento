import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import AutomationsPage from './AutomationsPage.vue'

const overview = {
  processes: [
    {
      key: 'preventive-alerts',
      label: 'Vencimientos y avisos preventivos',
      description: 'Planes próximos o vencidos.',
      enabled: null,
      schedule: 'Diario 07:00 (configuración de la aplicación)',
      lastRunAt: '2026-11-05 06:10:00',
      lastOutcome: 'CON_FALLOS',
      nextRunAt: null,
      pending: 3,
      failed: 1,
      sent: 8,
    },
    {
      key: 'reading-reminders',
      label: 'Recordatorios de lectura',
      description: 'Recordatorios semanales.',
      enabled: null,
      schedule: 'Dentro del ciclo de notificaciones',
      lastRunAt: null,
      lastOutcome: null,
      nextRunAt: null,
      pending: 0,
      failed: 0,
      sent: 0,
    },
  ],
  deliveries: {
    email: { pending: 1, failed: 1, sent: 8, lastActivityAt: '2026-11-05 06:05:00' },
    webpush: { pending: 0, failed: 0, sent: 0, lastActivityAt: null },
    whatsapp: { pending: 2, failed: 0, sent: 3, lastActivityAt: '2026-11-05 08:00:00' },
  },
  limitations: ['La pantalla no confirma que el programador del hosting esté activo.', 'No se muestran teléfonos ni correos.'],
}

const mountPage = (data = { overview }) => mount(AutomationsPage, {
  props: {
    data: { overview: data.overview, portalUrl: data.portalUrl ?? '/inicio' },
  },
})

describe('AutomationsPage', () => {
  it('separates configuration, observed execution and delivery state', () => {
    const wrapper = mountPage()

    expect(wrapper.get('h1').text()).toContain('Automatizaciones')
    expect(wrapper.get('a[href="/inicio"]').text()).toContain('Volver al inicio')
    expect(wrapper.text()).toContain('Sólo lectura')
    expect(wrapper.text()).toContain('Vencimientos y avisos preventivos')
    expect(wrapper.text()).toContain('Diario 07:00')
    expect(wrapper.text()).toContain('Requiere atención')
    expect(wrapper.text()).toContain('Revisar 1 entrega(s) fallida(s).')
    expect(wrapper.text()).toContain('Recordatorios de lectura')
    expect(wrapper.text()).toContain('Sin evidencia de ejecución')
    expect(wrapper.text()).toContain('Sin datos suficientes')
    expect(wrapper.text()).toContain('Entregas por canal')
    expect(wrapper.text()).toContain('Correo electrónico')
    expect(wrapper.text()).toContain('Web Push')
    expect(wrapper.text()).toContain('WhatsApp')
    expect(wrapper.text()).toContain('La pantalla no confirma')
    expect(wrapper.findAll('form')).toHaveLength(0)
    expect(wrapper.findAll('button')).toHaveLength(0)
    expect(wrapper.text()).not.toMatch(/[\w.+-]+@[\w.-]+\.\w+|\+54\d/i)
  })

  it('does not announce success when there is no execution evidence', () => {
    const wrapper = mountPage({
      overview: {
        processes: [{
          key: 'telemetry-sync', label: 'Sincronización de telemetría', description: 'Telemetría.',
          enabled: null, schedule: null, lastRunAt: null, lastOutcome: null, nextRunAt: null,
          pending: 0, failed: 0, sent: 0,
        }],
        deliveries: {
          email: { pending: 0, failed: 0, sent: 0, lastActivityAt: null },
          webpush: { pending: 0, failed: 0, sent: 0, lastActivityAt: null },
          whatsapp: { pending: 0, failed: 0, sent: 0, lastActivityAt: null },
        },
        limitations: [],
      },
    })

    expect(wrapper.text()).toContain('Sin evidencia de ejecución')
    expect(wrapper.text()).toContain('Sin datos suficientes')
    expect(wrapper.text()).toContain('Todavía no hay ejecución registrada')
    expect(wrapper.text()).not.toContain('Sin fallas registradas')
  })

  it('shows a truthful empty state when there is no evidence for the scope', () => {
    const wrapper = mountPage({
      overview: { processes: [], deliveries: {}, limitations: ['No tenés sucursales autorizadas.'] },
    })

    expect(wrapper.text()).toContain('No hay evidencia de automatizaciones para tu alcance.')
    expect(wrapper.text()).toContain('No tenés sucursales autorizadas.')
  })
})
