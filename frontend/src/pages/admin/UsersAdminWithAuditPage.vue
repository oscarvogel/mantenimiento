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
    <nav class="flex flex-wrap gap-2 rounded-xl border border-slate-200 bg-white p-2" aria-label="Administración de empresa">
      <button
        class="rounded-lg px-4 py-2 text-sm font-semibold"
        :class="section === 'users' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100'"
        type="button"
        @click="section = 'users'"
      >Usuarios y acceso</button>
      <button
        class="rounded-lg px-4 py-2 text-sm font-semibold"
        :class="section === 'chat-audit' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100'"
        type="button"
        @click="section = 'chat-audit'"
      >Historial del chatbot</button>
    </nav>

    <UsersAdminPage v-if="section === 'users'" :data="data" />

    <ChatAuditPage
      v-else
      :data="{
        title: 'Historial del chatbot',
        subtitle: `Conversaciones de ${data.company?.name || 'tu empresa'}`,
        showCompanyFilter: false,
      }"
    />
  </div>
</template>
