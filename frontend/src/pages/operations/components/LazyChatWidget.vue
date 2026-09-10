<script setup>
import { defineAsyncComponent, ref } from 'vue'

const ChatWidget = defineAsyncComponent(() => import('./ChatWidget.vue'))
const loaded = ref(false)

const load = () => {
  loaded.value = true
}
</script>

<template>
  <ChatWidget v-if="loaded" :auto-open="true" />
  <button
    v-else
    type="button"
    class="ui-interactive fixed bottom-6 right-6 z-50 flex size-14 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-lg hover:bg-primary-hover"
    aria-label="Abrir asistente IA"
    title="Abrir asistente IA"
    @click="load"
  >
    <svg class="size-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 14.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8s-4.03 8-9 8Z" />
    </svg>
  </button>
</template>

