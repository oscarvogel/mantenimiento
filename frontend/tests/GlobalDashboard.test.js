import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import GlobalDashboard from '../src/components/GlobalDashboard.vue'
import { normalizeDashboardPayload } from '../src/adapters/dashboardPayload.js'

const globalPayload = {
  mode: 'global',
  user: { name: 'Superadministrador', isSuperAdmin: true },
  global: {
    metrics: {
      companiesActive: 4,
      companiesTotal: 5,
      equipmentActive: 28,
      equipmentTotal: 31,
      expirationOverdue: 3,
      expirationUpcoming30: 7,
      pendingReadings: 6,
      notificationAlerts: 2,
    },
    attention: [
      {
        companyId: 1,
        company: 'Forestal Garuhapé',
        label: 'Vencimientos vencidos',
        count: 3,
        tone: 'danger',
        actionUrl: '/superadmin',
        actionLabel: 'Ver empresa',
      },
    ],
    activity: {
      readingsToday: 8,
      renewalsToday: 2,
      evidenceToday: 4,
      whatsappSentToday: 13,
      emailSentToday: 7,
      chatQueriesToday: 5,
      chatResponsesToday: 5,
    },
    companies: [
      {
        id: 1,
        name: 'Forestal Garuhapé',
        equipment: 17,
        overdue: 3,
        upcoming: 5,
        pendingReadings: 4,
        notificationAlerts: 1,
        tone: 'danger',
        actionUrl: '/superadmin',
      },
    ],
    communications: {
      whatsapp: { status: 'Configurado', tone: 'success' },
      email: { status: 'Configurado', tone: 'success' },
      cron: { status: 'Operativo', tone: 'success', lastRun: '2026-09-25 07:30:00' },
    },
    drivers: { active: 12, pendingReadings: 6, readingsToday: 8 },
    chatbot: { queriesToday: 5, responsesToday: 5 },
    links: {
      companies: '/superadmin',
      notifications: '/superadmin/configuracion/notificaciones',
      chatAudit: '/superadmin?section=chat-audit',
    },
  },
}

describe('GlobalDashboard', () => {
  it('mantiene la composición operativa aprobada', () => {
    const normalized = normalizeDashboardPayload(globalPayload)
    const wrapper = mount(GlobalDashboard, {
      props: { data: normalized.global },
      global: {
        directives: {
          reveal: {},
        },
      },
    })

    expect(wrapper.text()).toContain('Empresas activas')
    expect(wrapper.text()).toContain('Equipos activos')
    expect(wrapper.text()).toContain('Vencimientos vencidos')
    expect(wrapper.text()).toContain('Próximos 30 días')
    expect(wrapper.text()).toContain('KM pendientes')
    expect(wrapper.text()).toContain('Alertas de notificación')
    expect(wrapper.text()).toContain('Requieren atención')
    expect(wrapper.text()).toContain('Actividad de hoy')
    expect(wrapper.text()).toContain('Estado por empresa')
    expect(wrapper.text()).toContain('Comunicaciones')
    expect(wrapper.text()).toContain('Choferes y KM')
    expect(wrapper.text()).toContain('Chatbot')
    expect(wrapper.text()).toContain('Forestal Garuhapé')
    expect(wrapper.find('a[href="/superadmin/configuracion/notificaciones"]').exists()).toBe(true)
  })

  it('normaliza faltantes sin inventar métricas', () => {
    const dashboard = normalizeDashboardPayload({ mode: 'global', global: {} })

    expect(dashboard.global.metrics.companiesActive).toBe(0)
    expect(dashboard.global.metrics.equipmentActive).toBe(0)
    expect(dashboard.global.attention).toEqual([])
    expect(dashboard.global.activity.whatsappSentToday).toBe(0)
    expect(dashboard.global.communications.whatsapp.status).toBe('Sin datos')
  })
})
