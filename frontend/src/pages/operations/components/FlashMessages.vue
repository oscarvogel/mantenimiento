<script setup>
/**
 * Resultado de la última acción (`flash` del servidor). Sin esto, una
 * validación rechazada vuelve a la pantalla sin decir por qué.
 */
defineProps({ flash: { type: Object, default: () => ({}) } })
</script>

<template>
  <div v-if="flash?.success || flash?.error || flash?.warning" class="mb-4 space-y-2" data-testid="flash-messages">
    <div v-if="flash.error" class="rounded-xl border border-red-300 bg-red-50 px-4 py-3 text-sm font-medium text-red-900" role="alert">{{ flash.error }}</div>
    <div v-if="flash.warning" class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900" role="status">{{ flash.warning }}</div>
    <div v-if="flash.success" class="rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-900" role="status">{{ flash.success }}</div>
  </div>
</template>
