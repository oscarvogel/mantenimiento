<script setup>
// The single modal of the app, on the native <dialog>: the browser traps focus, closes
// on Escape, returns focus to the opener and renders above everything (top layer).
// Mount it with v-if in the parent, or drive it with `open`; closed, it leaves the DOM.
import { XMarkIcon } from '@heroicons/vue/24/outline'
import { ref, useId, watch } from 'vue'

const props = defineProps({
  title: { type: String, required: true },
  description: { type: String, default: '' },
  // Small uppercase context line above the title, e.g. "Renovación".
  eyebrow: { type: String, default: '' },
  open: { type: Boolean, default: true },
  // While saving, Escape and the backdrop must not discard what the user typed.
  persistent: { type: Boolean, default: false },
  size: { type: String, default: 'md', validator: (value) => ['sm', 'md', 'lg', 'xl'].includes(value) },
  titleId: { type: String, default: '' },
  closeLabel: { type: String, default: 'Cerrar' },
})
const emit = defineEmits(['close'])
// The root is a Teleport: attributes such as data-testid go on the <dialog> explicitly.
defineOptions({ inheritAttrs: false })

const dialog = ref(null)
const generatedId = useId()
const widths = { sm: 'max-w-md', md: 'max-w-lg', lg: 'max-w-2xl', xl: 'max-w-4xl' }

// The element exists only while open: show it as modal as soon as it mounts.
// Native showModal focuses the first focusable, the close button; start on the first
// field instead, unless the content marks one with `autofocus`.
watch(dialog, (element) => {
  if (!element || element.open) return
  element.showModal?.()
  if (element.querySelector('[autofocus]')) return
  element.querySelector('input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled])')?.focus()
}, { flush: 'post' })

const requestClose = () => {
  if (!props.persistent) emit('close')
}

// Escape fires `cancel`: the parent decides by unmounting or flipping `open`.
const onCancel = (event) => {
  event.preventDefault()
  requestClose()
}

// A click whose target is the <dialog> itself landed on the backdrop.
const onClick = (event) => {
  if (event.target === dialog.value) requestClose()
}
</script>

<template>
  <Teleport to="body">
    <!-- role/aria-modal repeat what showModal implies, for older readers and existing tests. -->
    <dialog
      v-if="open"
      ref="dialog"
      role="dialog"
      aria-modal="true"
      :aria-labelledby="titleId || generatedId"
      class="m-auto max-h-[calc(100dvh-2rem)] w-[calc(100vw-2rem)] overflow-y-auto rounded-xl border border-border bg-surface-raised p-0 text-ink shadow-xl backdrop:bg-secondary-900/60"
      :class="widths[size]"
      v-bind="$attrs"
      @cancel="onCancel"
      @click="onClick"
    >
      <header class="sticky top-0 z-10 flex items-start justify-between gap-4 border-b border-border-subtle bg-surface-raised px-6 py-4">
        <div class="min-w-0">
          <p v-if="eyebrow" class="text-xs font-bold uppercase tracking-wide text-primary">{{ eyebrow }}</p>
          <h2 :id="titleId || generatedId" class="text-lg font-bold text-ink" :class="{ 'mt-1': eyebrow }">{{ title }}</h2>
          <p v-if="description" class="mt-1 text-sm text-ink-muted">{{ description }}</p>
          <slot name="subtitle" />
        </div>
        <button
          type="button"
          class="ui-interactive grid size-11 shrink-0 place-items-center rounded-lg text-ink-muted hover:bg-surface-muted hover:text-ink disabled:opacity-50"
          :aria-label="closeLabel"
          :disabled="persistent"
          @click="requestClose"
        >
          <XMarkIcon class="size-5" aria-hidden="true" />
        </button>
      </header>
      <!-- No padding here: each form already brings its own. -->
      <slot />
    </dialog>
  </Teleport>
</template>
