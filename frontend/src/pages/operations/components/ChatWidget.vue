<template>
  <button
    v-if="!isOpen"
    @click="toggle"
    class="fixed bottom-6 right-6 z-50 w-14 h-14 bg-blue-600 text-white rounded-full shadow-lg hover:bg-blue-700 flex items-center justify-center transition-colors"
    title="Abrir asistente IA"
  >
    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
    </svg>
  </button>

  <div
    v-if="isOpen"
    class="fixed bottom-6 right-6 z-50 w-96 h-[500px] bg-white rounded-2xl shadow-2xl border border-gray-200 flex flex-col"
  >
    <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 bg-blue-600 text-white rounded-t-2xl">
      <div class="flex items-center gap-2">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
        </svg>
        <span class="font-medium text-sm">Asistente IA</span>
      </div>
      <button @click="toggle" class="text-white/80 hover:text-white">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>
    </div>

    <div ref="messagesContainer" class="flex-1 overflow-y-auto p-4 space-y-3">
      <ChatMessage
        v-for="msg in messages"
        :key="msg.tempId"
        :message="msg"
        :streaming="msg.streaming"
      />
      <ChatToolConfirm
        v-for="(tc, idx) in pendingToolCalls"
        :key="`tc-${idx}`"
        :tool-call="tc"
        @confirm="confirmTool(tc)"
        @cancel="cancelTool(tc)"
      />
      <div v-if="loading" class="text-center text-gray-400 text-sm py-2">
        Pensando...
      </div>
    </div>

    <div class="border-t border-gray-200 p-3">
      <form @submit.prevent="sendMessage" class="flex items-center gap-2">
        <ChatVoiceButton @transcript="onVoiceTranscript" />
        <input
          v-model="input"
          type="text"
          placeholder="Escribí tu mensaje..."
          class="flex-1 text-sm border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
          :disabled="loading"
        />
        <button
          type="submit"
          :disabled="!input.trim() || loading"
          class="w-8 h-8 bg-blue-600 text-white rounded-lg flex items-center justify-center hover:bg-blue-700 disabled:opacity-50"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
          </svg>
        </button>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, nextTick } from 'vue'
import ChatMessage from './ChatMessage.vue'
import ChatToolConfirm from './ChatToolConfirm.vue'
import ChatVoiceButton from './ChatVoiceButton.vue'

const isOpen = ref(false)
const messages = ref([])
const pendingToolCalls = ref([])
const input = ref('')
const loading = ref(false)
const conversationId = ref(null)
const messagesContainer = ref(null)
let tempIdCounter = 0

const toggle = () => {
  isOpen.value = !isOpen.value
  if (isOpen.value && conversationId.value === null) {
    startConversation()
  }
}

const scrollToBottom = () => {
  nextTick(() => {
    if (messagesContainer.value) {
      messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight
    }
  })
}

const getCsrfToken = () => {
  const meta = document.querySelector('meta[name="csrf-token"]')
  if (meta) return meta.getAttribute('content')
  const input = document.querySelector('input[name="csrf_token"]')
  return input ? input.value : ''
}

const startConversation = async () => {
  try {
    const res = await fetch('/mantenimiento/chatbot/conversaciones', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-CSRF-TOKEN': getCsrfToken(), 'Accept': 'application/json' },
    })
    const data = await res.json()
    conversationId.value = data.conversation.id
    messages.value.push({
      tempId: ++tempIdCounter,
      role: 'assistant',
      content: 'Hola, soy tu asistente de mantenimiento. ¿En qué puedo ayudarte?',
    })
  } catch (e) {
    console.error('Error starting conversation', e)
  }
}

const sendMessage = async () => {
  if (!input.value.trim() || loading.value) return

  const userMsg = { tempId: ++tempIdCounter, role: 'user', content: input.value }
  messages.value.push(userMsg)
  const sentContent = input.value
  input.value = ''
  loading.value = true
  scrollToBottom()

  try {
    const body = new FormData()
    body.append('conversationId', conversationId.value)
    body.append('content', sentContent)

    const res = await fetch('/mantenimiento/chatbot/mensajes', {
      method: 'POST',
      body,
      credentials: 'same-origin',
      headers: { 'X-CSRF-TOKEN': getCsrfToken(), 'Accept': 'application/json' },
    })

    const data = await res.json()

    if (data.messages) {
      for (const msg of data.messages) {
        if (msg.role === 'assistant') {
          messages.value.push({ ...msg, tempId: ++tempIdCounter })
        }
      }
    }

    if (data.pendingToolCalls) {
      pendingToolCalls.value = data.pendingToolCalls
    }
  } catch (e) {
    messages.value.push({
      tempId: ++tempIdCounter,
      role: 'assistant',
      content: 'Disculpá, hubo un error. Intentá de nuevo.',
    })
  } finally {
    loading.value = false
    scrollToBottom()
  }
}

const confirmTool = async (toolCall) => {
  pendingToolCalls.value = pendingToolCalls.value.filter(tc => tc.id !== toolCall.id)
  loading.value = true

  try {
    const body = new FormData()
    body.append('conversationId', conversationId.value)
    body.append('toolCalls', JSON.stringify([toolCall]))

    const res = await fetch('/mantenimiento/chatbot/confirmar', {
      method: 'POST',
      body,
      credentials: 'same-origin',
      headers: { 'X-CSRF-TOKEN': getCsrfToken(), 'Accept': 'application/json' },
    })

    const data = await res.json()
    if (data.messages) {
      for (const msg of data.messages) {
        messages.value.push({ ...msg, tempId: ++tempIdCounter })
      }
    }
  } catch (e) {
    messages.value.push({
      tempId: ++tempIdCounter,
      role: 'assistant',
      content: 'Error al ejecutar la acción.',
    })
  } finally {
    loading.value = false
    scrollToBottom()
  }
}

const cancelTool = (toolCall) => {
  pendingToolCalls.value = pendingToolCalls.value.filter(tc => tc.id !== toolCall.id)
}

const onVoiceTranscript = (text) => {
  input.value = text
  sendMessage()
}
</script>