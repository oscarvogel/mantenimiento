// Shared button and field classes (docs/DESIGN_TOKENS.md). They work on <button>, <a>
// and submit inputs alike. Size is part of each constant on purpose: stacking a size
// class over another padding class leaves the winner to Tailwind's CSS order.

const base = 'ui-interactive inline-flex items-center justify-center gap-2 rounded-lg font-semibold disabled:cursor-not-allowed disabled:opacity-50'
const md = 'min-h-11 px-4 py-2.5 text-sm'
const sm = 'min-h-9 px-3 py-1.5 text-xs'

const variant = {
  primary: 'ui-glare bg-primary text-primary-foreground shadow-sm hover:bg-primary-hover active:bg-primary-active',
  secondary: 'border border-border-strong bg-surface-raised text-ink hover:bg-surface-muted',
  danger: 'border border-danger/30 bg-surface-raised text-danger-strong hover:bg-danger-subtle',
  ghost: 'text-primary hover:bg-primary-subtle',
}

const button = (name, size) => `${base} ${size} ${variant[name]}`

export const primaryButton = button('primary', md)
export const secondaryButton = button('secondary', md)
export const dangerButton = button('danger', md)
export const ghostButton = button('ghost', md)

export const primaryButtonSm = button('primary', sm)
export const secondaryButtonSm = button('secondary', sm)
export const dangerButtonSm = button('danger', sm)
export const ghostButtonSm = button('ghost', sm)

// Square button for a lone icon: the caller must give it an aria-label.
export const iconButton = `${base} size-11 text-ink-muted hover:bg-surface-muted hover:text-ink`

export const fieldClass = 'block min-h-11 w-full rounded-lg border border-border bg-surface-raised px-3 py-2 text-sm text-ink shadow-sm placeholder:text-ink-subtle file:mr-3 file:rounded-md file:border-0 file:bg-primary file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-primary-foreground file:transition-colors hover:file:bg-primary-hover focus:border-border-focus focus:outline-none focus:ring-2 focus:ring-primary/15 disabled:cursor-not-allowed disabled:bg-surface-muted disabled:text-ink-subtle aria-[invalid=true]:border-danger aria-[invalid=true]:ring-danger/15'
