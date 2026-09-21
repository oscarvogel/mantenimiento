<script setup>
import ChatRobotVector from './ChatRobotVector.vue'

defineProps({
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
</script>

<template>
  <span
    class="chat-robot"
    :class="[`chat-robot--${variant}`, `chat-robot--${state}`]"
    data-testid="chat-robot"
    aria-hidden="true"
  >
    <ChatRobotVector :key="variant" :variant="variant" :state="state" />
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

.chat-robot--offline {
  filter: saturate(0.82);
}

@media (prefers-reduced-motion: reduce) {
  .chat-robot {
    transition: none;
  }
}
</style>
