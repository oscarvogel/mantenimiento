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
        <span v-if="!isConnected" class="text-[10px] bg-red-500 text-white px-1.5 py-0.5 rounded">offline</span>
      </div>
      <button @click="toggle" class="text-white/80 hover:text-white" title="Cerrar asistente">
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
      <div v-if="loading && streamingText === ''" class="text-center text-gray-400 text-sm py-2">
        Pensando...
      </div>
    </div>

    <div v-if="lastError" class="bg-red-50 border-t border-red-200 px-3 py-2 text-xs text-red-700">
      {{ lastError }}
      <button class="ml-2 underline" @click="lastError = ''">Descartar</button>
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
          title="Enviar"
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
import { ref, nextTick, onMounted, onBeforeUnmount } from 'vue'
import ChatMessage from './ChatMessage.vue'
import ChatToolConfirm from './ChatToolConfirm.vue'
import ChatVoiceButton from './ChatVoiceButton.vue'

const isOpen = ref(false)
const messages = ref([])
const pendingToolCalls = ref([])
const input = ref('')
const loading = ref(false)
const streamingText = ref('')
const conversationId = ref(null)
const lastError = ref('')
const isConnected = ref(true)
const messagesContainer = ref(null)
let tempIdCounter = 0
let activeController = null

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
  const csrfField = document.querySelector('input[name^="csrf"]')
  return csrfField ? csrfField.value : ''
}

const startConversation = async () => {
  try {
    const res = await fetch('/mantenimiento/chatbot/conversaciones', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-CSRF-TOKEN': getCsrfToken(), 'Accept': 'application/json' },
    })
    if (!res.ok) {
      throw new Error(`HTTP ${res.status}`)
    }
    const data = await res.json()
    conversationId.value = data.conversation.id
    messages.value.push({
      tempId: ++tempIdCounter,
      role: 'assistant',
      content: 'Hola, soy tu asistente de mantenimiento. ¿En qué puedo ayudarte?',
    })
  } catch (e) {
    lastError.value = 'No pude iniciar la conversación. Reintentá más tarde.'
  }
}

const abortActive = () => {
  if (activeController) {
    activeController.abort()
    activeController = null
  }
}

const sendMessage = async () => {
  if (!input.value.trim() || loading.value) return

  abortActive()

  const userMsg = { tempId: ++tempIdCounter, role: 'user', content: input.value }
  messages.value.push(userMsg)
  const sentContent = input.value
  input.value = ''
  loading.value = true
  streamingText.value = ''
  pendingToolCalls.value = []
  lastError.value = ''
  scrollToBottom()

  const streamingMsg = {
    tempId: ++tempIdCounter,
    role: 'assistant',
    content: '',
    streaming: true,
  }
  messages.value.push(streamingMsg)
  scrollToBottom()

  try {
    const body = new FormData()
    body.append('conversationId', String(conversationId.value ?? ''))
    body.append('content', sentContent)

    activeController = new AbortController()
    const res = await fetch('/mantenimiento/chatbot/mensajes/stream', {
      method: 'POST',
      body,
      credentials: 'same-origin',
      headers: { 'X-CSRF-TOKEN': getCsrfToken(), 'Accept': 'text/event-stream' },
      signal: activeController.signal,
    })

    if (!res.ok || !res.body) {
      throw new Error(`HTTP ${res.status}`)
    }

    isConnected.value = true
    await readSse(res.body, streamingMsg)
  } catch (e) {
    if (e.name === 'AbortError') {
      streamingText.value = '(cancelado)'
    } else {
      isConnected.value = false
      lastError.value = 'No pude comunicarme con el asistente. Reintentá.'
    }
    streamingMsg.content = streamingText.value
    streamingMsg.streaming = false
  } finally {
    loading.value = false
    streamingText.value = ''
    activeController = null
    scrollToBottom()
  }
}

const readSse = async (body, streamingMsg) => {
  const reader = body.getReader()
  const decoder = new TextDecoder('utf-8')
  let buffer = ''

  while (true) {
    const { value, done } = await reader.read()
    if (done) break
    buffer += decoder.decode(value, { stream: true })

    let sep
    while ((sep = buffer.indexOf('\n\n')) !== -1) {
      const block = buffer.slice(0, sep)
      buffer = buffer.slice(sep + 2)
      handleSseBlock(block, streamingMsg)
      scrollToBottom()
    }
  }
}

const handleSseBlock = (block, streamingMsg) => {
  let event = 'message'
  let data = ''
  for (const line of block.split('\n')) {
    if (line.startsWith('event:')) {
      event = line.slice(6).trim()
    } else if (line.startsWith('data:')) {
      data += (data ? '\n' : '') + line.slice(5).trim()
    }
  }
  if (!data) return

  if (event === 'chunk') {
    streamingText.value += data
    streamingMsg.content = streamingText.value
  } else if (event === 'pending_tools') {
    try {
      const tools = JSON.parse(data)
      pendingToolCalls.value = Array.isArray(tools) ? tools : [tools]
    } catch (e) {
      console.warn('SSE pending_tools no parseable', e)
    }
  } else if (event === 'error') {
    lastError.value = data
  } else if (event === 'done') {
    streamingMsg.content = streamingText.value || '(respuesta vacía)'
    streamingMsg.streaming = false
  }
}

const confirmTool = async (toolCall) => {
  abortActive()
  pendingToolCalls.value = pendingToolCalls.value.filter((tc) => tc.id !== toolCall.id)
  loading.value = true
  streamingText.value = ''
  lastError.value = ''
  scrollToBottom()

  const streamingMsg = {
    tempId: ++tempIdCounter,
    role: 'assistant',
    content: '',
    streaming: true,
  }
  messages.value.push(streamingMsg)
  scrollToBottom()

  try {
    const body = new FormData()
    body.append('conversationId', String(conversationId.value ?? ''))
    body.append('toolCalls', JSON.stringify([toolCall]))

    activeController = new AbortController()
    const res = await fetch('/mantenimiento/chatbot/mensajes/stream', {
      method: 'POST',
      body,
      credentials: 'same-origin',
      headers: { 'X-CSRF-TOKEN': getCsrfToken(), 'Accept': 'text/event-stream' },
      signal: activeController.signal,
    })

    if (!res.ok || !res.body) {
      throw new Error(`HTTP ${res.status}`)
    }
    isConnected.value = true
    await readSse(res.body, streamingMsg)
  } catch (e) {
    if (e.name !== 'AbortError') {
      isConnected.value = false
      lastError.value = 'No pude ejecutar la acción confirmada.'
    }
    streamingMsg.content = streamingText.value
    streamingMsg.streaming = false
  } finally {
    loading.value = false
    streamingText.value = ''
    activeController = null
    scrollToBottom()
  }
}

const cancelTool = (toolCall) => {
  pendingToolCalls.value = pendingToolCalls.value.filter((tc) => tc.id !== toolCall.id)
}

const onVoiceTranscript = (text) => {
  input.value = text
  sendMessage()
}

onMounted(() => {
  window.addEventListener('beforeunload', abortActive)
})

onBeforeUnmount(() => {
  abortActive()
  window.removeEventListener('beforeunload', abortActive)
})
</script>
