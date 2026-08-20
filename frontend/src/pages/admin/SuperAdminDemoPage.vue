<script setup>
import SuperAdminPage from './SuperAdminPage.vue'
import DemoCompanyPanel from './components/DemoCompanyPanel.vue'

defineProps({
  data: {
    type: Object,
    required: true,
  },
})
</script>

<template>
  <div>
    <DemoCompanyPanel :data="data" />
    <SuperAdminPage :data="data" />
  </div>
</template>
