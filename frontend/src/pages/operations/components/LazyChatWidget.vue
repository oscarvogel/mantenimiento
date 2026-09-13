<script setup>
import { defineAsyncComponent, onMounted, ref } from 'vue'
import ChatRobot from './ChatRobot.vue'

const ChatWidget = defineAsyncComponent(() => import('./ChatWidget.vue'))
const loaded = ref(false)
const unread = ref(0)
const appBaseUrl = (document.body?.dataset?.baseUrl ?? '/').replace(/\/?$/, '/')
const CHATBOT_BASE_PATH = `${appBaseUrl}mantenimiento/chatbot`

const loadBriefing = async () => {
  try {
    const res = await fetch(`${CHATBOT_BASE_PATH}/briefing`, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' },
    })
    if (!res.ok) return
    const data = await res.json()
    unread.value = Number(data.briefing?.unread ?? 0)
  } catch (_) {
    // El badge es complementario y no debe afectar la carga del portal.
  }
}

const load = () => {
  loaded.value = true
}

onMounted(loadBriefing)
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
    <span
      v-if="unread > 0"
      class="absolute -right-1 -top-1 flex min-h-5 min-w-5 items-center justify-center rounded-full bg-danger px-1 text-[10px] font-bold text-danger-foreground"
      aria-label="Alertas pendientes"
    >
      {{ unread > 99 ? '99+' : unread }}
    </span>
  </button>
</template>
