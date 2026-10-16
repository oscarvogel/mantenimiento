<script setup>
import ApplicationShell from '../../../components/ApplicationShell.vue'

defineProps({
  shell: { type: Object, required: true },
  scrollCta: { type: Object, default: null },
})
</script>

<template>
  <ApplicationShell :shell="shell" :scroll-cta="scrollCta">
    <div class="client-pilot-profile__notice" role="status">
      <span class="client-pilot-profile__notice-dot" aria-hidden="true"></span>
      Prototipo de revisión · Empresa demo
    </div>
    <slot />
  </ApplicationShell>
</template>
