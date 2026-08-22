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
      <div v-if="message.role === 'assistant'" class="prose prose-sm max-w-none break-words">
        <template v-for="(line, lineIndex) in renderedLines" :key="`line-${lineIndex}`">
          <template v-for="(token, tokenIndex) in line" :key="`token-${lineIndex}-${tokenIndex}`">
            <a
              v-if="token.type === 'link'"
              :href="token.href"
              class="font-medium text-blue-700 underline decoration-blue-400 underline-offset-2 hover:text-blue-900"
              target="_blank"
              rel="noopener noreferrer"
            >{{ token.label }}</a>
            <span v-else>{{ token.text }}</span>
          </template>
          <br v-if="lineIndex < renderedLines.length - 1">
        </template>
      </div>
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

const safeHref = (rawHref) => {
  const href = String(rawHref || '').trim()
  if (!href) return null

  try {
    if (/^https?:\/\//i.test(href)) {
      // Una URL válida no puede contener un segundo esquema HTTP embebido.
      // Esto corta explícitamente regresiones del tipo baseA + baseB.
      const schemes = href.match(/https?:\/\//gi) || []
      if (schemes.length !== 1) return null

      const url = new URL(href)
      return ['http:', 'https:'].includes(url.protocol) ? url.href : null
    }

    if (href.startsWith('/mantenimiento/')) {
      const origin = typeof window !== 'undefined' ? window.location.origin : ''
      return origin ? new URL(href, `${origin}/`).href : href
    }
  } catch (_) {
    return null
  }

  return null
}

const tokenizeLine = (line) => {
  const tokens = []
  // Markdown links seguros + URLs absolutas + rutas históricas relativas del sistema.
  const pattern = /\[([^\]]+)\]\((https?:\/\/[^\s)]+|\/mantenimiento\/[^\s)]+)\)|(https?:\/\/[^\s<>()]+|\/mantenimiento\/[A-Za-z0-9_\-\/.?&=#%]+)/g
  let lastIndex = 0
  let match

  while ((match = pattern.exec(line)) !== null) {
    if (match.index > lastIndex) {
      tokens.push({ type: 'text', text: line.slice(lastIndex, match.index) })
    }

    const markdownLabel = match[1]
    const rawHref = match[2] || match[3]
    const href = safeHref(rawHref)
    if (href) {
      tokens.push({
        type: 'link',
        href,
        label: markdownLabel || rawHref,
      })
    } else {
      tokens.push({ type: 'text', text: match[0] })
    }
    lastIndex = pattern.lastIndex
  }

  if (lastIndex < line.length) {
    tokens.push({ type: 'text', text: line.slice(lastIndex) })
  }

  if (tokens.length === 0) {
    tokens.push({ type: 'text', text: line })
  }

  return tokens
}

const renderedLines = computed(() => String(props.message.content || '').split('\n').map(tokenizeLine))
</script>
