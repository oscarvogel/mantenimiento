<script setup>
import ApplicationShell from './components/ApplicationShell.vue'

defineProps({
  shell: {
    type: Object,
    required: true,
  },
  pageComponent: {
    type: Object,
    required: true,
  },
  pageProps: {
    type: Object,
    default: () => ({}),
  },
})
</script>

<template>
  <ApplicationShell :shell="shell">
    <component :is="pageComponent" v-bind="pageProps" />
  </ApplicationShell>
</template>
