<script setup>
import ApplicationShell from './components/ApplicationShell.vue'
import CompanyAiControls from './pages/admin/components/CompanyAiControls.vue'

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
  <component :is="shellComponent" :shell="shell">
    <component :is="pageComponent" v-bind="pageProps" />
    <CompanyAiControls
      v-if="Array.isArray(pageProps?.data?.aiCompanyControls)"
      :companies="pageProps.data.aiCompanyControls"
      :csrf="pageProps.data.csrf"
    />
  </component>
</template>
