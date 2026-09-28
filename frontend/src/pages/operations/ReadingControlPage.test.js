import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import ReadingControlPage from './ReadingControlPage.vue'

const row = (overrides = {}) => ({
  equipmentId: 10,
  equipmentCode: 'CAM-01',
  equipmentPlate: 'AA123BB',
  typeName: 'Camión',
  branchId: 7,
  branchName: 'TSA Argentina',
  controlsKm: true,
  driverEmployeeId: 55,
  driverName: 'Pérez, Juan',
  driverPhone: '5493514449999',
  lastKm: 185000,
  lastReadingAt: '2026-09-20 08:30:00',
  daysSinceLastReading: 8,
  equipmentUrl: '/mantenimiento/equipos/10',
  hasDriver: true,
  hasValidPhone: true,
  hasReading: true,
  canClaim: true,
  ...overrides,
})

const data = (overrides = {}) => ({
  results: [row()],
  catalogs: {
    branches: [{ id: 7, name: 'TSA Argentina' }],
    types: [{ id: 3, name: 'Camión' }],
    statusOptions: [{ key: 'all', label: 'Todos' }],
    sortOptions: [{ key: 'age_asc', label: 'Más antiguos primero' }],
  },
  routes: {
    index: '/mantenimiento/mantenimiento/lecturas/control',
    claim: '/mantenimiento/mantenimiento/lecturas/control/reclamar',
    equipment: '/mantenimiento/equipos',
    quickReadings: '/mantenimiento/lecturas/rapidas',
  },
  claim: { enabled: true, reason: null },
  csrf: { name: 'csrf_test', hash: 'tok-123' },
  summary: { total: 1, sinLectura: 0, antiguos: 1, alDia: 0 },
  pagination: { page: 1, perPage: 25, total: 1, totalPages: 1 },
  filters: { q: '', branchId: '', typeId: '', filter: 'all', sort: 'age_asc' },
  ...overrides,
})

const mountPage = (overrides) => mount(ReadingControlPage, { props: { data: data(overrides) } })

const claimButton = (wrapper) =>
  wrapper.findAll('button').find((b) => b.text().includes('Reclamar por WhatsApp'))

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('ReadingControlPage · consulta de lecturas', () => {
  it('sigue mostrando la grilla de lecturas con su antigüedad', () => {
    const wrapper = mountPage()
    const rows = wrapper.findAll('tbody tr')

    expect(rows).toHaveLength(1)
    expect(wrapper.text()).toContain('185.000')
    expect(wrapper.text()).toContain('2026-09-20 08:30')
    expect(wrapper.text()).toContain('hace 8 días')
  })

  it('mantiene la columna de antigüedad y los filtros', () => {
    const wrapper = mountPage()

    expect(wrapper.text()).toContain('Antigüedad')
    expect(wrapper.find('#reading-control-filter').exists()).toBe(true)
    expect(wrapper.find('#reading-control-sort').exists()).toBe(true)
  })
})

describe('ReadingControlPage · botón de reclamo', () => {
  it('muestra el botón cuando hay chofer con teléfono válido', () => {
    expect(claimButton(mountPage())).toBeDefined()
  })

  it('oculta el botón cuando la fila no tiene chofer', () => {
    const wrapper = mountPage({
      results: [row({ canClaim: false, hasDriver: false, driverName: '(sin chofer)' })],
    })

    expect(claimButton(wrapper)).toBeUndefined()
    expect(wrapper.text()).toContain('Sin chofer')
  })

  it('oculta el botón cuando el chofer no tiene teléfono', () => {
    const wrapper = mountPage({
      results: [row({ canClaim: false, hasValidPhone: false, driverPhone: null })],
    })

    expect(claimButton(wrapper)).toBeUndefined()
    expect(wrapper.text()).toContain('Sin teléfono')
  })

  it('oculta el botón cuando el operador no tiene permiso o WhatsApp no está disponible', () => {
    const wrapper = mountPage({ claim: { enabled: false, reason: 'Las notificaciones por WhatsApp no están habilitadas para esta empresa.' } })

    expect(claimButton(wrapper)).toBeUndefined()
    expect(wrapper.text()).toContain('No disponible')
    expect(wrapper.text()).toContain('Las notificaciones por WhatsApp no están habilitadas')
  })

  it('no envía nada al abrir la pantalla', async () => {
    const fetchSpy = vi.fn()
    vi.stubGlobal('fetch', fetchSpy)

    mountPage()
    await flushPromises()

    expect(fetchSpy).not.toHaveBeenCalled()
  })
})

describe('ReadingControlPage · confirmación y envío', () => {
  it('pide confirmación con chofer, equipo, km, fecha y días', async () => {
    const wrapper = mountPage()
    await claimButton(wrapper).trigger('click')

    const dialog = wrapper.find('[role="dialog"]')
    expect(dialog.exists()).toBe(true)
    expect(dialog.text()).toContain('Enviar recordatorio a')
    expect(dialog.text()).toContain('CAM-01')
    expect(dialog.text()).toContain('185.000')
    expect(dialog.text()).toContain('2026-09-20 08:30')
    expect(dialog.text()).toContain('8 días')
    expect(dialog.text()).toContain('Cancelar')
    expect(dialog.text()).toContain('Enviar WhatsApp')
  })

  it('centra el diálogo en el viewport con altura máxima y scroll interno', async () => {
    const wrapper = mountPage()
    await claimButton(wrapper).trigger('click')

    const overlay = wrapper.find('[role="dialog"]')
    expect(overlay.exists()).toBe(true)
    const overlayClasses = overlay.classes()
    expect(overlayClasses).toContain('fixed')
    expect(overlayClasses).toContain('inset-0')
    expect(overlayClasses).toContain('items-center')
    expect(overlayClasses).toContain('justify-center')
    expect(overlayClasses).not.toContain('items-end')

    const panel = overlay.find('div')
    expect(panel.exists()).toBe(true)
    const panelClasses = panel.classes()
    expect(panelClasses).toContain('max-h-[90vh]')
    expect(panelClasses).toContain('overflow-y-auto')
  })

  it('envía solo equipmentId y el token CSRF, nunca un teléfono', async () => {
    const fetchSpy = vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ ok: true, message: 'Reclamo enviado por WhatsApp.', messageId: 'wamid.1' }),
    })
    vi.stubGlobal('fetch', fetchSpy)

    const wrapper = mountPage()
    await claimButton(wrapper).trigger('click')
    await wrapper.findAll('[role="dialog"] button').find((b) => b.text().includes('Enviar WhatsApp')).trigger('click')
    await flushPromises()

    const [url, options] = fetchSpy.mock.calls[0]
    expect(url).toBe('/mantenimiento/mantenimiento/lecturas/control/reclamar')
    expect(options.method).toBe('POST')

    const body = options.body
    expect(body.get('equipmentId')).toBe('10')
    expect(body.get('csrf_test')).toBe('tok-123')
    expect([...body.keys()]).not.toContain('phone')
    expect([...body.keys()]).not.toContain('telefono')
  })

  it('muestra estado de carga y evita el doble clic', async () => {
    let resolveFetch
    const fetchSpy = vi.fn(() => new Promise((resolve) => { resolveFetch = resolve }))
    vi.stubGlobal('fetch', fetchSpy)

    const wrapper = mountPage()
    await claimButton(wrapper).trigger('click')

    const sendButton = () => wrapper.findAll('[role="dialog"] button').find((b) => b.text().includes('Enviar') || b.text().includes('Enviando'))
    await sendButton().trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Enviando')
    expect(sendButton().attributes('disabled')).toBeDefined()

    // Segundo clic durante el envío: no debe dispararse otra petición.
    await sendButton().trigger('click')
    await flushPromises()
    expect(fetchSpy).toHaveBeenCalledTimes(1)

    resolveFetch({ ok: true, json: async () => ({ ok: true, message: 'Reclamo enviado por WhatsApp.' }) })
    await flushPromises()
  })

  it('confirma el éxito y avisa cuando fue solo al teléfono piloto', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({
        ok: true,
        message: 'Reclamo registrado y enviado solo al teléfono piloto.',
        pilotMode: 'piloto',
      }),
    }))

    const wrapper = mountPage()
    await claimButton(wrapper).trigger('click')
    await wrapper.findAll('[role="dialog"] button').find((b) => b.text().includes('Enviar WhatsApp')).trigger('click')
    await flushPromises()

    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Reclamo registrado y enviado solo al teléfono piloto')
    expect(wrapper.text()).toContain('no al chofer real')
  })

  it('muestra el error del servidor sin cerrar el diálogo', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
      ok: false,
      status: 422,
      json: async () => ({ ok: false, error: 'El equipo no tiene un chofer activo asignado.' }),
    }))

    const wrapper = mountPage()
    await claimButton(wrapper).trigger('click')
    await wrapper.findAll('[role="dialog"] button').find((b) => b.text().includes('Enviar WhatsApp')).trigger('click')
    await flushPromises()

    expect(wrapper.find('[role="dialog"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('El equipo no tiene un chofer activo asignado')
  })

  it('informa un fallo de red sin romper la pantalla', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('network')))

    const wrapper = mountPage()
    await claimButton(wrapper).trigger('click')
    await wrapper.findAll('[role="dialog"] button').find((b) => b.text().includes('Enviar WhatsApp')).trigger('click')
    await flushPromises()

    expect(wrapper.findAll('tbody tr').length).toBe(1)
    expect(wrapper.text()).toContain('No se pudo completar la solicitud')
  })

  it('permite cancelar sin enviar', async () => {
    const fetchSpy = vi.fn()
    vi.stubGlobal('fetch', fetchSpy)

    const wrapper = mountPage()
    await claimButton(wrapper).trigger('click')
    await wrapper.findAll('[role="dialog"] button').find((b) => b.text().includes('Cancelar')).trigger('click')
    await flushPromises()

    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
    expect(fetchSpy).not.toHaveBeenCalled()
  })
})
