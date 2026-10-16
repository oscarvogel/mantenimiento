<script setup>
import CompanyAiControls from './pages/admin/components/CompanyAiControls.vue'

defineProps({
  pageComponent: { type: Object, required: true },
  pageProps: { type: Object, default: () => ({}) },
})
</script>

<template>
  <component :is="pageComponent" v-bind="pageProps" />
  <CompanyAiControls
    v-if="Array.isArray(pageProps?.data?.aiCompanyControls)"
    :companies="pageProps.data.aiCompanyControls"
    :csrf="pageProps.data.csrf"
  />
</template>
