import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import WorkOrderEvidenceModal from '../../src/pages/operations/components/WorkOrderEvidenceModal.vue'

describe('WorkOrderEvidenceModal', () => {
  it('muestra todas las evidencias y permite cerrarlo', async () => {
    const wrapper = mount(WorkOrderEvidenceModal, {
      attachTo: document.body,
      props: {
        order: {
          number: 'OT-2026-000003',
          evidenceCount: 2,
          evidence: [
            {
              id: 10,
              originalName: 'foto-reparacion.jpg',
              mimeType: 'image/jpeg',
              sizeKb: 120.5,
              description: 'Antes del cierre',
              createdAt: '2026-08-06 15:30',
              isImage: true,
              previewUrl: '/mantenimiento/equipos/1/adjuntos/10/descargar',
              downloadUrl: '/mantenimiento/equipos/1/adjuntos/10/descargar',
            },
            {
              id: 11,
              originalName: 'comprobante.pdf',
              mimeType: 'application/pdf',
              source: 'ot_document_import',
              sizeKb: 88,
              description: '',
              createdAt: '2026-08-06 15:31',
              isImage: false,
              previewUrl: '/mantenimiento/equipos/1/ordenes/44/importaciones/11/documento',
              downloadUrl: '/mantenimiento/equipos/1/ordenes/44/importaciones/11/documento',
            },
          ],
        },
      },
    })

    expect(document.body.textContent).toContain('OT-2026-000003')
    expect(document.body.textContent).toContain('2 archivos asociados')
    expect(document.body.textContent).toContain('foto-reparacion.jpg')
    expect(document.body.textContent).toContain('comprobante.pdf')
    expect(document.body.querySelectorAll('a[href*="/adjuntos/"]').length).toBeGreaterThanOrEqual(3)
    expect(document.body.querySelectorAll('a[href*="/importaciones/"]').length).toBe(2)

    const closeButton = document.body.querySelector('button[aria-label="Cerrar evidencias"]')
    expect(closeButton).not.toBeNull()
    closeButton.click()
    await wrapper.vm.$nextTick()

    expect(wrapper.emitted('close')).toHaveLength(1)
    wrapper.unmount()
  })

  it('mantiene el modal sin tarjetas cuando la OT no tiene evidencia', () => {
    const wrapper = mount(WorkOrderEvidenceModal, {
      props: { order: { number: 'OT-2026-000004', evidenceCount: 0, evidence: [] } },
    })

    expect(wrapper.findAll('article')).toHaveLength(0)
    expect(document.body.textContent).toContain('0 archivos asociados')
    wrapper.unmount()
  })
})
