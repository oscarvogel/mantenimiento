<template>
  <button
    type="button"
    @click="toggleRecording"
    :class="[
      'ui-interactive flex min-h-10 min-w-10 items-center justify-center rounded-full transition-colors',
      isRecording ? 'animate-pulse bg-danger text-danger-foreground' : 'bg-surface-muted text-ink-muted hover:bg-border-strong'
    ]"
    :aria-label="isRecording ? 'Detener grabación de voz' : 'Grabar mensaje de voz'"
    :aria-pressed="isRecording"
    :title="isRecording ? 'Detener' : 'Grabar voz'"
  >
    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
      <path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3z" />
      <path d="M17 11c0 2.76-2.24 5-5 5s-5-2.24-5-5H5c0 3.53 2.61 6.43 6 6.92V21h2v-3.08c3.39-.49 6-3.39 6-6.92h-2z" />
    </svg>
  </button>
</template>

<script setup>
import { ref, onUnmounted } from 'vue'

const emit = defineEmits(['transcript'])

const isRecording = ref(false)
let recognition = null

const toggleRecording = () => {
  if (isRecording.value) {
    recognition?.stop()
    isRecording.value = false
    return
  }

  const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition
  if (!SpeechRecognition) {
    alert('Tu navegador no soporta reconocimiento de voz.')
    return
  }

  recognition = new SpeechRecognition()
  recognition.lang = 'es-AR'
  recognition.continuous = false
  recognition.interimResults = false

  recognition.onresult = (event) => {
    const transcript = event.results[0][0].transcript
    emit('transcript', transcript)
    isRecording.value = false
  }

  recognition.onerror = () => { isRecording.value = false }
  recognition.onend = () => { isRecording.value = false }

  recognition.start()
  isRecording.value = true
}

onUnmounted(() => { recognition?.abort() })
</script>
