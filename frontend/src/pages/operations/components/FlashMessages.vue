<script setup>
import { CheckCircleIcon, ExclamationCircleIcon } from '@heroicons/vue/24/outline'

defineProps({ flash: { type: Object, default: () => ({}) } })
</script>

<template>
  <div v-if="flash.success" role="status" class="mb-5 flex gap-3 rounded-xl border border-success/20 bg-success-subtle p-4 text-sm text-success-strong">
    <CheckCircleIcon class="size-5 shrink-0" aria-hidden="true" />
    <p>{{ flash.success }}</p>
  </div>
  <div v-if="flash.error" role="alert" class="mb-5 flex gap-3 rounded-xl border border-danger/20 bg-danger-subtle p-4 text-sm text-danger-strong">
    <ExclamationCircleIcon class="size-5 shrink-0" aria-hidden="true" />
    <p>{{ flash.error }}</p>
  </div>
</template>
