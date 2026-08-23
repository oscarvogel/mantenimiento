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

const escapeHtml = (value) => String(value)
  .replaceAll('&', '&amp;')
  .replaceAll('<', '&lt;')
  .replaceAll('>', '&gt;')
  .replaceAll('"', '&quot;')
  .replaceAll("'", '&#039;')

const safeHref = (value) => {
  try {
    const url = new URL(String(value).trim(), window.location.origin)
    if (!['http:', 'https:'].includes(url.protocol)) return null

    // Compatibilidad con respuestas antiguas: antes el modelo podia emitir
    // /mantenimiento/planes o /mantenimiento/equipos, pero en el deploy plano
    // esas rutas viven bajo el grupo /mantenimiento adicional.
    if (
      url.origin === window.location.origin
      && window.location.pathname.startsWith('/mantenimiento/')
      && /^\/mantenimiento\/(?:planes|equipos)(?:\/|$)/.test(url.pathname)
    ) {
      url.pathname = `/mantenimiento${url.pathname}`
    }

    return url.href
  } catch (_) {
    return null
  }
}

const renderLink = (label, href, links) => {
  const absolute = safeHref(href)
  if (!absolute) return `${label} (${href})`

  const token = `@@CHAT_LINK_${links.length}@@`
  links.push(`<a href="${escapeHtml(absolute)}" target="_self" rel="noopener noreferrer" class="text-blue-700 underline break-words">${escapeHtml(label)}</a>`)
  return token
}

const renderMarkdown = (value) => {
  const links = []
  let text = String(value ?? '')

  // Primero extraemos Markdown para no transformar también el href interno.
  text = text.replace(/\[([^\]\n]+)\]\(([^)\s]+)\)/g, (_, label, href) => renderLink(label, href, links))

  // También hacemos clickeables las URLs que el proveedor entregue sin Markdown.
  text = text.replace(/(?<![\w"'=])((?:https?:\/\/|\/mantenimiento\/)[^\s<>()]+)/g, (match) => {
    const trailing = match.match(/[.,;:!?¿¡]+$/)?.[0] ?? ''
    const href = trailing ? match.slice(0, -trailing.length) : match
    return renderLink(href, href, links) + trailing
  })

  text = escapeHtml(text)
    .replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>')
    .replace(/(?<!\*)\*([^*\n]+)\*(?!\*)/g, '<em>$1</em>')
    .replace(/\n/g, '<br>')

  return text.replace(/@@CHAT_LINK_(\d+)@@/g, (_, index) => links[Number(index)] ?? '')
}

const renderedContent = computed(() => {
  return renderMarkdown(props.message.content)
})
</script>
