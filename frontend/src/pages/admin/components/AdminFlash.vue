<script setup>
import { CheckCircleIcon, ExclamationCircleIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import { ref } from 'vue'

const props = defineProps({
  success: {
    type: String,
    default: '',
  },
  error: {
    type: String,
    default: '',
  },
})

const successVisible = ref(Boolean(props.success))
const errorVisible = ref(Boolean(props.error))
</script>

<template>
  <div v-if="successVisible && success" class="mb-5 flex items-start gap-3 rounded-xl border border-success/25 bg-success-subtle px-4 py-3 text-sm text-success-strong" role="status">
    <CheckCircleIcon class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
    <p class="min-w-0 flex-1 font-medium">{{ success }}</p>
    <button type="button" class="-m-1 rounded-lg p-1 text-success-strong hover:bg-success/10" aria-label="Cerrar mensaje" @click="successVisible = false">
      <XMarkIcon class="size-5" aria-hidden="true" />
    </button>
  </div>

  <div v-if="errorVisible && error" class="mb-5 flex items-start gap-3 rounded-xl border border-danger/25 bg-danger-subtle px-4 py-3 text-sm text-danger-strong" role="alert">
    <ExclamationCircleIcon class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
    <p class="min-w-0 flex-1 font-medium">{{ error }}</p>
    <button type="button" class="-m-1 rounded-lg p-1 text-danger-strong hover:bg-danger/10" aria-label="Cerrar mensaje" @click="errorVisible = false">
      <XMarkIcon class="size-5" aria-hidden="true" />
    </button>
  </div>
</template>
