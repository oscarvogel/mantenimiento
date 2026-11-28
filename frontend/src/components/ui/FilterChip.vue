<script setup>
// Píldora con conteo que filtra: enlace si el filtro va por URL, botón si es local.
defineProps({
  label: { type: String, required: true },
  count: { type: [Number, String], default: null },
  href: { type: String, default: null },
  active: { type: Boolean, default: false },
})
const emit = defineEmits(['select'])
</script>

<template>
  <component
    :is="href ? 'a' : 'button'"
    :href="href ?? undefined"
    :type="href ? undefined : 'button'"
    :aria-current="href && active ? 'true' : undefined"
    :aria-pressed="href ? undefined : String(active)"
    class="ui-interactive inline-flex h-9 items-center gap-2 whitespace-nowrap rounded-pill px-3.5 text-sm font-semibold shadow-card focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-border-focus"
    :class="active ? 'bg-action text-action-foreground' : 'bg-surface-raised text-ink-muted hover:text-ink'"
    @click="href ? undefined : emit('select')"
  >
    {{ label }}
    <span v-if="count !== null" class="font-bold tabular-nums" :class="active ? '' : 'text-ink'">{{ count }}</span>
  </component>
</template>
