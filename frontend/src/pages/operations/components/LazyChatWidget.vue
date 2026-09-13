<script setup>
import { defineAsyncComponent, ref } from 'vue'
import ChatRobot from './ChatRobot.vue'

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
    <ChatRobot variant="fab" />
  </button>
</template>
