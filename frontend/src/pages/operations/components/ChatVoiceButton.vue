<template>
  <button
    @click="toggleRecording"
    :class="[
      'w-8 h-8 rounded-full flex items-center justify-center transition-colors',
      isRecording ? 'bg-red-500 text-white animate-pulse' : 'bg-gray-200 text-gray-600 hover:bg-gray-300'
    ]"
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