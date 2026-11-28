<script setup>
// "Mi cuenta": el cierre de sesión. El tema vive al pie de la barra lateral.
import { ArrowRightStartOnRectangleIcon, ChevronDownIcon, UserIcon } from '@heroicons/vue/24/outline'
import { onBeforeUnmount, ref, watch } from 'vue'

const props = defineProps({
  user: { type: Object, required: true },
  logout: { type: Object, default: null },
})

const open = ref(false)
const root = ref(null)
const button = ref(null)

const close = (returnFocus = false) => {
  open.value = false
  if (returnFocus) button.value?.focus()
}
const onKeydown = (event) => {
  if (event.key === 'Escape') close(true)
}
const onPointerDown = (event) => {
  if (!root.value?.contains(event.target)) close()
}

watch(open, (isOpen) => {
  const method = isOpen ? 'addEventListener' : 'removeEventListener'
  document[method]('keydown', onKeydown)
  document[method]('pointerdown', onPointerDown)
})
onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKeydown)
  document.removeEventListener('pointerdown', onPointerDown)
})
</script>

<template>
  <div ref="root" class="relative">
    <button
      ref="button"
      type="button"
      class="ui-interactive flex h-11 items-center gap-2 rounded-pill bg-secondary-900 px-3 text-sm font-semibold text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-border-focus focus-visible:ring-offset-2 dark:bg-neutral-100 dark:text-secondary-900 sm:px-4"
      aria-haspopup="true"
      :aria-expanded="open ? 'true' : 'false'"
      aria-controls="account-menu"
      @click="open = !open"
    >
      <UserIcon class="size-5" aria-hidden="true" />
      <span class="hidden sm:inline">Mi cuenta</span>
      <ChevronDownIcon class="size-4 transition-transform" :class="{ 'rotate-180': open }" aria-hidden="true" />
    </button>

    <div
      v-show="open"
      id="account-menu"
      class="absolute right-0 top-full z-50 mt-2 w-64 rounded-card border border-border-subtle bg-surface-raised p-2 shadow-card"
    >
      <p class="truncate px-3 pb-2 pt-1 text-sm font-semibold text-ink">{{ props.user.name }}</p>
      <form v-if="props.logout" :action="props.logout.href" :method="props.logout.method === 'post' ? 'post' : 'get'">
        <input v-if="props.logout.method === 'post' && props.logout.csrfName" type="hidden" :name="props.logout.csrfName" :value="props.logout.csrfValue" />
        <button
          type="submit"
          class="ui-interactive flex min-h-11 w-full items-center gap-3 rounded-lg px-3 text-left text-sm font-medium text-ink hover:bg-surface-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-border-focus"
        >
          <ArrowRightStartOnRectangleIcon class="size-5 text-ink-muted" aria-hidden="true" />
          Cerrar sesión
        </button>
      </form>
    </div>
  </div>
</template>
