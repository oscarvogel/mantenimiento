<script setup>
import { ref } from 'vue'
import SuperAdminPage from './SuperAdminPage.vue'
import DemoCompanyPanel from './components/DemoCompanyPanel.vue'
import ChatAuditPage from './ChatAuditPage.vue'

defineProps({
  data: {
    type: Object,
    required: true,
  },
})

const section = ref(new URLSearchParams(window.location.search).get('section') === 'chat-audit' ? 'chat-audit' : 'administration')
</script>

<template>
  <div class="space-y-5">
    <nav class="flex flex-wrap gap-2 rounded-xl border border-slate-200 bg-white p-2" aria-label="Administración global">
      <button
        class="rounded-lg px-4 py-2 text-sm font-semibold"
        :class="section === 'administration' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100'"
        type="button"
        @click="section = 'administration'"
      >Administración</button>
      <button
        class="rounded-lg px-4 py-2 text-sm font-semibold"
        :class="section === 'chat-audit' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100'"
        type="button"
        @click="section = 'chat-audit'"
      >Auditoría del chatbot</button>
    </nav>

    <template v-if="section === 'administration'">
      <DemoCompanyPanel :data="data" />
      <SuperAdminPage :data="data" />
    </template>

    <ChatAuditPage
      v-else
      :data="{
        title: 'Auditoría global del chatbot',
        subtitle: 'Conversaciones de todas las empresas',
        showCompanyFilter: true,
        apiUrl: data.endpoints?.chatbotAudit,
      }"
    />
  </div>
</template>
