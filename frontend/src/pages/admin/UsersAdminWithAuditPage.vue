<script setup>
import { ref } from 'vue'
import UsersAdminPage from './UsersAdminPage.vue'
import ChatAuditPage from './ChatAuditPage.vue'

const props = defineProps({
  data: {
    type: Object,
    required: true,
  },
})

const section = ref(new URLSearchParams(window.location.search).get('section') === 'chat-audit' ? 'chat-audit' : 'users')
</script>

<template>
  <div class="space-y-5">
    <div class="ui-tabs" role="tablist" aria-label="Administración de empresa">
      <button type="button" role="tab" :aria-selected="section === 'users'" @click="section = 'users'">Usuarios y acceso</button>
      <button type="button" role="tab" :aria-selected="section === 'chat-audit'" @click="section = 'chat-audit'">Historial del chatbot</button>
    </div>

    <UsersAdminPage v-if="section === 'users'" :data="data" />

    <ChatAuditPage
      v-else
      :data="{
        title: 'Historial del chatbot',
        subtitle: `Conversaciones de ${data.company?.name || 'tu empresa'}`,
        showCompanyFilter: false,
        apiUrl: data.chatAudit?.apiUrl,
      }"
    />
  </div>
</template>
