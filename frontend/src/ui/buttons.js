// Shared button and field classes (docs/DESIGN_TOKENS.md). They work on <button>, <a>
// and submit inputs alike. Size is part of each constant on purpose: stacking a size
// class over another padding class leaves the winner to Tailwind's CSS order.

// Rediseño de interiores (26/11): píldoras; el principal en marino (`action`), no en azul.
const base = 'ui-interactive inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-pill font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-border-focus focus-visible:ring-offset-2 focus-visible:ring-offset-surface-raised disabled:cursor-not-allowed disabled:opacity-50'
const md = 'min-h-11 px-5 py-2.5 text-sm'
const sm = 'min-h-9 px-3.5 py-1.5 text-xs'

const variant = {
  primary: 'bg-action text-action-foreground hover:bg-action/90 active:bg-action/80',
  secondary: 'bg-surface-raised text-ink shadow-card ring-1 ring-border-subtle hover:bg-surface-muted',
  danger: 'bg-surface-raised text-danger-strong ring-1 ring-danger/30 hover:bg-danger-subtle',
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

// Campo sobre fondo suave, sin borde visible; en píldora salvo el textarea, que va con esquinas grandes.
export const fieldClass = 'block min-h-11 w-full rounded-2xl border border-transparent bg-surface-muted px-4 py-2 text-sm text-ink placeholder:text-ink-subtle [&:not(textarea)]:rounded-pill file:mr-3 file:rounded-pill file:border-0 file:bg-action file:px-3.5 file:py-1.5 file:text-sm file:font-semibold file:text-action-foreground file:transition-colors hover:file:bg-action/90 focus:border-border-focus focus:outline-none focus:ring-2 focus:ring-primary/15 disabled:cursor-not-allowed disabled:opacity-60 aria-[invalid=true]:border-danger aria-[invalid=true]:ring-2 aria-[invalid=true]:ring-danger/15'
