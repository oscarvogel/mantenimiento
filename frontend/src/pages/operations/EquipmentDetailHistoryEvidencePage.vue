<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import EquipmentDetailPage from './EquipmentDetailPage.vue'
import WorkOrderEvidenceModal from './components/WorkOrderEvidenceModal.vue'
import { secondaryButton } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })
const selectedOrder = ref(null)
let observer = null

const installEvidenceButtons = () => {
  const orders = props.data.workOrderHistory?.items ?? []
  if (orders.length === 0) return

  const rows = document.querySelectorAll('#equipment-panel-historial tbody tr')
  rows.forEach((row, index) => {
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
    button.addEventListener('click', () => {
      selectedOrder.value = order
    })
    actions.insertBefore(button, actions.lastElementChild)
  })
}

onMounted(async () => {
  await nextTick()
  installEvidenceButtons()
  const panel = document.querySelector('#equipment-panel-historial')
  if (panel) {
    observer = new MutationObserver(installEvidenceButtons)
    observer.observe(panel, { childList: true, subtree: true })
  }
})

onBeforeUnmount(() => observer?.disconnect())
</script>

<template>
  <EquipmentDetailPage :data="data" />
  <WorkOrderEvidenceModal v-if="selectedOrder" :order="selectedOrder" @close="selectedOrder = null" />
</template>
