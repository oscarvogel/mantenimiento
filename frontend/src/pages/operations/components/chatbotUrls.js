export function chatbotEndpoint(path, baseUrl = document.body?.dataset?.baseUrl ?? '/') {
  const normalizedBaseUrl = baseUrl.endsWith('/') ? baseUrl : `${baseUrl}/`
  const normalizedPath = path.replace(/^\/+/, '')

  return `${normalizedBaseUrl}mantenimiento/chatbot/${normalizedPath}`
}
