<template>
  <button
    v-if="!isOpen"
    @click="toggle"
    class="ui-interactive fixed bottom-6 right-6 z-50 flex size-14 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-lg hover:bg-primary-hover"
    aria-label="Abrir asistente IA"
    title="Abrir asistente IA"
  >
    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 14.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
    </svg>
  </button>

  <div
    v-if="isOpen"
    class="fixed inset-x-4 bottom-4 z-50 flex h-[min(500px,calc(100dvh-2rem))] flex-col rounded-2xl border border-border bg-surface-raised shadow-2xl sm:inset-x-auto sm:right-6 sm:w-96"
    role="dialog"
    aria-modal="true"
    aria-labelledby="chat-widget-title"
  >
    <div class="flex items-center justify-between rounded-t-2xl border-b border-primary/30 bg-primary px-4 py-3 text-primary-foreground">
      <div class="flex items-center gap-2">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
        </svg>
        <span id="chat-widget-title" class="font-medium text-sm">Asistente IA</span>
        <span v-if="!isConnected" class="rounded bg-danger px-1.5 py-0.5 text-[10px] text-danger-foreground">offline</span>
      </div>
      <button type="button" @click="toggle" class="ui-interactive min-h-10 min-w-10 rounded-lg text-white/80 hover:text-white" aria-label="Cerrar asistente" title="Cerrar asistente">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>
    </div>

    <div ref="messagesContainer" class="flex-1 overflow-y-auto p-4 space-y-3" role="log" aria-live="polite" aria-relevant="additions text">
      <div
        v-if="historyTruncated"
        class="text-center text-[11px] text-ink-subtle"
      >
        Mostrando los últimos {{ CHAT_VISIBLE_HISTORY_LIMIT }} mensajes
      </div>
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
      <div v-if="loading && streamingText === ''" role="status" aria-live="polite" class="py-2 text-center text-sm text-ink-subtle">
        Pensando...
      </div>
    </div>

    <div v-if="lastError" class="bg-red-50 border-t border-red-200 px-3 py-2 text-xs text-red-700" role="alert">
      {{ lastError }}
      <button type="button" class="ui-interactive ml-2 min-h-8 rounded px-1 underline" @click="lastError = ''">Descartar</button>
    </div>

    <div class="border-t border-border p-3">
      <form @submit.prevent="sendMessage" class="flex items-center gap-2">
        <ChatVoiceButton @transcript="onVoiceTranscript" />
        <label for="chat-message-input" class="sr-only">Mensaje para el asistente</label>
        <input
          id="chat-message-input"
          v-model="input"
          type="text"
          placeholder="Escribí tu mensaje..."
          class="min-w-0 flex-1 rounded-lg border border-border-strong bg-surface px-3 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-border-focus"
          :disabled="loading"
        />
        <button
          type="submit"
          :disabled="!input.trim() || loading"
          class="ui-interactive flex min-h-10 min-w-10 items-center justify-center rounded-lg bg-primary text-primary-foreground hover:bg-primary-hover disabled:opacity-50"
          aria-label="Enviar mensaje"
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

const REQUEST_TIMEOUT_MS = 30000
const CHAT_STORAGE_KEY = 'mantenimiento.chatbot.conv'
const CHAT_VISIBLE_HISTORY_LIMIT = 10
const CHATBOT_BASE_PATH = '/mantenimiento/mantenimiento/chatbot'

const props = defineProps({
  autoOpen: { type: Boolean, default: false },
})

const isOpen = ref(props.autoOpen)
const messages = ref([])
const pendingToolCalls = ref([])
const input = ref('')
const loading = ref(false)
const streamingText = ref('')
const conversationId = ref(null)
const lastError = ref('')
const isConnected = ref(true)
const messagesContainer = ref(null)
const historyTruncated = ref(false)
let tempIdCounter = 0
let activeController = null
let hasRestoredSession = false

const scrollToBottom = () => {
  nextTick(() => {
    if (messagesContainer.value) {
      messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight
    }
  })
}

const ensureConversation = () => {
  if (!isOpen.value) return
  if (conversationId.value !== null) {
    scrollToBottom()
    return
  }

  if (!hasRestoredSession) {
    hasRestoredSession = true
    restoreConversation().then((restored) => {
      if (!restored && conversationId.value === null) {
        startConversation()
      }
    })
  }
}

const toggle = () => {
  isOpen.value = !isOpen.value
  if (isOpen.value) ensureConversation()
}

const getCsrfToken = () => {
  const meta = document.querySelector('meta[name="csrf-token"]')
  if (meta) return meta.getAttribute('content')
  const csrfField = document.querySelector('input[name^="csrf"]')
  return csrfField ? csrfField.value : ''
}

const startConversation = async () => {
  try {
    const res = await fetch(`${CHATBOT_BASE_PATH}/conversaciones`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'X-CSRF-TOKEN': getCsrfToken(), 'Accept': 'application/json' },
    })
    if (!res.ok) {
      throw new Error(`HTTP ${res.status}`)
    }
    const data = await res.json()
    conversationId.value = data.conversation.id
    try {
      window.localStorage.setItem(CHAT_STORAGE_KEY, String(conversationId.value))
    } catch (_) { /* ignorar errores de storage */ }
    messages.value.push({
      tempId: ++tempIdCounter,
      role: 'assistant',
      content: 'Hola, soy tu asistente de mantenimiento. ¿En qué puedo ayudarte?',
    })
    scrollToBottom()
  } catch (e) {
    lastError.value = 'No pude iniciar la conversación. Reintentá más tarde.'
  }
}

const restoreConversation = async () => {
  let stored = null
  try {
    stored = window.localStorage.getItem(CHAT_STORAGE_KEY)
  } catch (_) { /* ignorar */ }
  if (!stored) return false
  const convId = parseInt(stored, 10)
  if (!convId || isNaN(convId)) return false

  try {
    const res = await fetch(`${CHATBOT_BASE_PATH}/historial?conversationId=${convId}`, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' },
    })
    if (!res.ok) {
      try { window.localStorage.removeItem(CHAT_STORAGE_KEY) } catch (_) {}
      return false
    }
    const data = await res.json()
    if (!data.messages || data.messages.length === 0) {
      try { window.localStorage.removeItem(CHAT_STORAGE_KEY) } catch (_) {}
      return false
    }

    conversationId.value = convId
    const recentMessages = data.messages.slice(-CHAT_VISIBLE_HISTORY_LIMIT)
    historyTruncated.value = data.messages.length > CHAT_VISIBLE_HISTORY_LIMIT
    messages.value = recentMessages.map((m) => ({
      tempId: ++tempIdCounter,
      role: m.role,
      content: m.content ?? '',
    }))

    if (data.csrf?.hash) {
      const meta = document.querySelector('meta[name="csrf-token"]')
      if (meta) meta.setAttribute('content', data.csrf.hash)
    }
    scrollToBottom()
    return true
  } catch (_) {
    return false
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
    const timeoutId = setTimeout(() => {
      if (activeController) {
        try { activeController.abort('timeout') } catch (_) { /* noop */ }
      }
    }, REQUEST_TIMEOUT_MS)

    const csrfToken = getCsrfToken()
    const res = await fetch(`${CHATBOT_BASE_PATH}/mensajes`, {
      method: 'POST',
      body,
      credentials: 'same-origin',
      headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
      signal: activeController.signal,
    })
    clearTimeout(timeoutId)

    if (!res.ok) {
      const errorBody = await res.text().catch(() => '')
      throw new Error(`HTTP ${res.status}: ${errorBody.substring(0, 200)}`)
    }

    const data = await res.json()
    if (data.error) {
      throw new Error(data.error)
    }

    isConnected.value = true
    let assistantText = '(sin respuesta)'
    if (Array.isArray(data.messages)) {
      const assistantMsg = data.messages.find((m) => m.role === 'assistant')
      assistantText = assistantMsg?.content ?? '(respuesta vacía)'
    }
    const idx = messages.value.findIndex((m) => m.tempId === streamingMsg.tempId)
    if (idx >= 0) {
      messages.value[idx] = {
        tempId: streamingMsg.tempId,
        role: 'assistant',
        content: assistantText,
        streaming: false,
      }
    }
  } catch (e) {
    console.error('[chatbot] sendMessage error:', e.name, e.message)
    if (e.name === 'AbortError') {
      streamingMsg.content = '(cancelado o tiempo agotado)'
    } else {
      isConnected.value = false
      lastError.value = `No pude comunicarme con el asistente. (${e.message}). Reintentá.`
    }
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
    const res = await fetch(`${CHATBOT_BASE_PATH}/mensajes`, {
      method: 'POST',
      body,
      credentials: 'same-origin',
      headers: { 'X-CSRF-TOKEN': getCsrfToken(), 'Accept': 'application/json' },
      signal: activeController.signal,
    })

    if (!res.ok) {
      throw new Error(`HTTP ${res.status}`)
    }
    const data = await res.json()
    if (data.error) {
      throw new Error(data.error)
    }
    isConnected.value = true
    if (Array.isArray(data.messages)) {
      const assistantMsg = data.messages.find((m) => m.role === 'assistant')
      streamingMsg.content = assistantMsg?.content ?? '(respuesta vacía)'
    }
    streamingMsg.streaming = false
  } catch (e) {
    if (e.name !== 'AbortError') {
      isConnected.value = false
      lastError.value = 'No pude ejecutar la acción confirmada.'
    }
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
  if (isOpen.value) ensureConversation()
  window.addEventListener('beforeunload', abortActive)
})

onBeforeUnmount(() => {
  abortActive()
  window.removeEventListener('beforeunload', abortActive)
})
</script>
