<script setup>
import { InboxIcon } from '@heroicons/vue/24/outline'

defineProps({ title: { type: String, required: true }, description: { type: String, default: '' } })
</script>

<template>
  <div class="flex flex-col items-center px-5 py-10 text-center">
    <span class="flex size-11 items-center justify-center rounded-full bg-surface-muted text-ink-subtle"><InboxIcon class="size-5" aria-hidden="true" /></span>
    <p class="mt-3 text-sm font-semibold text-ink">{{ title }}</p>
    <p v-if="description" class="mt-1 max-w-md text-sm text-ink-muted">{{ description }}</p>
  </div>
</template>
