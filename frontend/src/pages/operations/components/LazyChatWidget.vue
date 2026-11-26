<script setup>
import { defineAsyncComponent, onMounted, ref } from 'vue'
import ChatLauncher from './ChatLauncher.vue'

defineProps({
  launcherTarget: { type: Object, default: null },
})

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
  <ChatWidget v-if="loaded" :auto-open="true" :launcher-target="launcherTarget" />
  <ChatLauncher v-else :target="launcherTarget" :unread="unread" @open="load" />
</template>
