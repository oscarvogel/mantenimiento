<script setup>
import { computed, ref, useId } from 'vue'
import { InformationCircleIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  label: { type: String, required: true },
  text: { type: String, required: true },
})

const id = `context-help-${useId()}`
const focused = ref(false)
const hovered = ref(false)
const pinned = ref(false)
const visible = computed(() => focused.value || hovered.value || pinned.value)
const toggle = () => { pinned.value = !pinned.value }
const close = () => { pinned.value = false; focused.value = false }
</script>

<template>
  <span class="relative inline-flex shrink-0 align-middle" @mouseenter="hovered = true" @mouseleave="hovered = false">
    <button
      type="button"
      :aria-label="label"
      :aria-describedby="id"
      :aria-expanded="visible"
      class="inline-flex size-5 items-center justify-center rounded-full text-info-strong hover:bg-info-subtle focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary"
      @click.stop="toggle"
      @focus="focused = true"
      @blur="focused = false"
      @keydown.esc="close"
    >
      <InformationCircleIcon class="size-4" aria-hidden="true" />
    </button>
    <span
      :id="id"
      role="tooltip"
      class="absolute bottom-full left-0 z-50 mb-2 w-64 max-w-[calc(100vw-3rem)] rounded-lg border border-border-strong bg-slate-950 px-3 py-2 text-left text-xs font-normal leading-5 text-white shadow-xl transition-opacity"
      :class="visible ? 'visible opacity-100' : 'invisible opacity-0'"
    >{{ text }}</span>
  </span>
</template>
