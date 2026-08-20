<template>
  <div class="flex" :class="message.role === 'user' ? 'justify-end' : 'justify-start'">
    <div
      class="max-w-[80%] rounded-xl px-4 py-2 text-sm"
      :class="message.role === 'user'
        ? 'bg-blue-600 text-white'
        : message.role === 'assistant'
          ? 'bg-gray-100 text-gray-800'
          : 'bg-yellow-50 text-yellow-800 text-xs'"
    >
      <div v-if="message.role === 'assistant'" class="prose prose-sm max-w-none" v-html="renderedContent" />
      <div v-else>{{ message.content }}</div>
      <div v-if="message.role === 'assistant' && streaming" class="inline-block w-2 h-4 bg-gray-400 animate-pulse ml-0.5" />
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  message: { type: Object, required: true },
  streaming: { type: Boolean, default: false },
})

const renderedContent = computed(() => {
  let text = (props.message.content || '')
    .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
    .replace(/\*(.*?)\*/g, '<em>$1</em>')
    .replace(/\n/g, '<br>')
  return text
})
</script>