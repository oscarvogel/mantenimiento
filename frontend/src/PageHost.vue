<script setup>
import ApplicationShell from './components/ApplicationShell.vue'
import PageContent from './PageContent.vue'

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
  shellComponent: {
    type: Object,
    default: ApplicationShell,
  },
})
</script>

<template>
  <ApplicationShell v-if="shellComponent === ApplicationShell" :shell="shell">
    <PageContent :page-component="pageComponent" :page-props="pageProps" />
  </ApplicationShell>
  <component v-else :is="shellComponent" :shell="shell">
    <PageContent :page-component="pageComponent" :page-props="pageProps" />
  </component>
</template>
