<script>
import { createApp, nextTick } from 'vue'
import EquipmentDetailPage from './EquipmentDetailPage.vue'
import WorkOrderEvidenceModal from './components/WorkOrderEvidenceModal.vue'
import { secondaryButton } from './helpers.js'

const installEnhancer = () => {
  if (EquipmentDetailPage.__workOrderEvidenceEnhancerInstalled) return
  EquipmentDetailPage.__workOrderEvidenceEnhancerInstalled = true

  const originalMounted = EquipmentDetailPage.mounted
  const originalBeforeUnmount = EquipmentDetailPage.beforeUnmount

  EquipmentDetailPage.mounted = async function enhancedMounted(...args) {
    if (typeof originalMounted === 'function') originalMounted.apply(this, args)
    else if (Array.isArray(originalMounted)) originalMounted.forEach((hook) => hook.apply(this, args))

    await nextTick()
    const state = { observer: null, modalApp: null, modalHost: null }
    this.__workOrderEvidenceState = state

    const closeModal = () => {
      state.modalApp?.unmount()
      state.modalHost?.remove()
      state.modalApp = null
      state.modalHost = null
    }

    const openModal = (order) => {
      closeModal()
      const host = document.createElement('div')
      document.body.appendChild(host)
      state.modalHost = host
      state.modalApp = createApp(WorkOrderEvidenceModal, {
        order,
        onClose: closeModal,
      })
      state.modalApp.mount(host)
    }

    const installEvidenceButtons = () => {
      const orders = this.data?.workOrderHistory?.items ?? []
      if (orders.length === 0) return

      document.querySelectorAll('#equipment-panel-historial tbody tr').forEach((row, index) => {
        const order = orders[index]
        if (!order || !order.evidenceCount || !Array.isArray(order.evidence) || order.evidence.length === 0) return

        const actions = row.querySelector('td:last-child > div')
        if (!actions || actions.querySelector('[data-work-order-evidence]')) return

        const button = document.createElement('button')
        button.type = 'button'
        button.dataset.workOrderEvidence = String(order.id)
        button.className = secondaryButton
        button.textContent = order.evidenceCount > 1 ? `Evidencia (${order.evidenceCount})` : 'Evidencia'
        button.setAttribute('aria-label', `Ver evidencia de ${order.number}`)
        button.addEventListener('click', () => openModal(order))
        actions.insertBefore(button, actions.lastElementChild)
      })
    }

    installEvidenceButtons()
    const panel = document.querySelector('#equipment-panel-historial')
    if (panel) {
      state.observer = new MutationObserver(installEvidenceButtons)
      state.observer.observe(panel, { childList: true, subtree: true })
    }
  }

  EquipmentDetailPage.beforeUnmount = function enhancedBeforeUnmount(...args) {
    const state = this.__workOrderEvidenceState
    state?.observer?.disconnect()
    state?.modalApp?.unmount()
    state?.modalHost?.remove()

    if (typeof originalBeforeUnmount === 'function') originalBeforeUnmount.apply(this, args)
    else if (Array.isArray(originalBeforeUnmount)) originalBeforeUnmount.forEach((hook) => hook.apply(this, args))
  }
}

installEnhancer()

export default EquipmentDetailPage
</script>
