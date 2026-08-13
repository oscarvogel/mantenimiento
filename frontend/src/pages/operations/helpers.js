export const fieldClass = 'block min-h-11 w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-ink shadow-sm placeholder:text-ink-subtle focus:border-border-focus focus:outline-none focus:ring-2 focus:ring-primary/15 disabled:cursor-not-allowed disabled:bg-surface-muted disabled:text-ink-subtle'

export const primaryButton = 'ui-interactive inline-flex min-h-11 items-center justify-center rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground shadow-sm hover:bg-primary-hover active:bg-primary-active disabled:cursor-not-allowed disabled:opacity-50'

export const secondaryButton = 'ui-interactive inline-flex min-h-10 items-center justify-center rounded-lg border border-border-strong bg-white px-3.5 py-2 text-sm font-semibold text-ink hover:bg-surface-muted disabled:cursor-not-allowed disabled:opacity-50'

export const dangerButton = 'ui-interactive inline-flex min-h-10 items-center justify-center rounded-lg border border-danger/30 bg-white px-3.5 py-2 text-sm font-semibold text-danger-strong hover:bg-danger-subtle disabled:cursor-not-allowed disabled:opacity-50'

export const localDateValue = (date) => {
  const year = String(date.getFullYear()).padStart(4, '0')
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')

  return `${year}-${month}-${day}`
}

export const today = () => localDateValue(new Date())

export const nowLocal = () => {
  const date = new Date(Date.now() - new Date().getTimezoneOffset() * 60_000)
  return date.toISOString().slice(0, 16)
}
