export function installPreventiveOrderFlow(root, payload) {
  const plans = payload?.data?.plans?.items ?? []

  for (const plan of plans) {
    const button = root.querySelector(`[data-testid="generate-order-${plan.id}"]`)
    const form = button?.closest('form')

    if (plan.openOrder?.printUrl && form) {
      const link = document.createElement('a')
      link.href = plan.openOrder.printUrl
      link.target = '_blank'
      link.rel = 'noopener noreferrer'
      link.className = button.className
      link.dataset.testid = `open-order-${plan.id}`
      link.textContent = `Ver OT ${plan.openOrder.number || `#${plan.openOrder.id}`}`
      form.replaceWith(link)
      continue
    }

    if (!form || !button) continue

    form.target = '_blank'
    form.addEventListener('submit', () => {
      button.disabled = true
      button.textContent = 'Abriendo OT…'
      button.setAttribute('aria-busy', 'true')
    }, { once: true })
  }
}
