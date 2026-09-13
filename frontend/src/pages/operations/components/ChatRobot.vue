<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { getTheme } from '../../../ui/theme.js'

const props = defineProps({
  variant: {
    type: String,
    default: 'fab',
    validator: (value) => ['fab', 'full'].includes(value),
  },
  state: {
    type: String,
    default: 'idle',
    validator: (value) => ['idle', 'thinking', 'loading', 'success', 'error', 'offline'].includes(value),
  },
})

const baseUrl = (document.body?.dataset?.baseUrl ?? '/').replace(/\/?$/, '/')
const theme = ref(getTheme())
const assetUrl = computed(() => {
  if (props.state === 'idle') {
    return `${baseUrl}assets/brand/chatbot/robot-${props.variant}.svg`
  }

  return `${baseUrl}assets/brand/chatbot/robot-${props.variant}-${props.state}-${theme.value}.svg`
})

const syncTheme = (event) => {
  theme.value = event.detail?.theme ?? getTheme()
}

onMounted(() => window.addEventListener('maintenance:theme-change', syncTheme))
onBeforeUnmount(() => window.removeEventListener('maintenance:theme-change', syncTheme))
</script>

<template>
  <span
    class="chat-robot"
    :class="[`chat-robot--${variant}`, `chat-robot--${state}`]"
    data-testid="chat-robot"
    aria-hidden="true"
  >
    <img
      :src="assetUrl"
      alt=""
      class="chat-robot__image"
      :width="variant === 'full' ? 128 : 48"
      :height="variant === 'full' ? 128 : 48"
      loading="eager"
      decoding="async"
    >
  </span>
</template>

<style scoped>
.chat-robot {
  display: inline-flex;
  flex: none;
  align-items: center;
  justify-content: center;
  transition: transform 180ms ease, filter 180ms ease;
}

.chat-robot__image {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: contain;
}

.chat-robot--fab {
  width: 2.75rem;
  height: 2.75rem;
  padding: 0.1rem;
  border-radius: 9999px;
  background: rgb(var(--surface-raised));
  box-shadow: inset 0 0 0 1px rgb(var(--border) / 0.7);
}

.chat-robot--full {
  width: min(8rem, 42vw);
  height: min(8rem, 42vw);
}

.chat-robot--fab:hover {
  transform: translateY(-1px) scale(1.04);
  filter: saturate(1.05);
}

.chat-robot--idle .chat-robot__image {
  animation: chat-robot-breathe 5s ease-in-out infinite;
}

.chat-robot--thinking .chat-robot__image {
  animation: chat-robot-thinking 1.35s ease-in-out infinite;
}

.chat-robot--loading .chat-robot__image {
  animation: chat-robot-loading 1.25s ease-in-out infinite;
}

.chat-robot--success .chat-robot__image {
  animation: chat-robot-success 420ms ease-out both;
}

.chat-robot--error .chat-robot__image {
  animation: chat-robot-error 360ms ease-out both;
}

.chat-robot--offline {
  filter: saturate(0.82);
}

@keyframes chat-robot-breathe {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-1px); }
}

@keyframes chat-robot-thinking {
  0%, 100% { transform: translateY(0) rotate(0deg); }
  50% { transform: translateY(-2px) rotate(-1deg); }
}

@keyframes chat-robot-loading {
  0%, 100% { transform: translateY(0) scale(1); }
  50% { transform: translateY(-1px) scale(1.015); }
}

@keyframes chat-robot-success {
  0% { transform: scale(0.96); }
  70% { transform: scale(1.035); }
  100% { transform: scale(1); }
}

@keyframes chat-robot-error {
  0%, 100% { transform: translateX(0); }
  35% { transform: translateX(-1px); }
  70% { transform: translateX(1px); }
}

@media (prefers-reduced-motion: reduce) {
  .chat-robot,
  .chat-robot__image {
    animation: none;
    transition: none;
  }
}
</style>
