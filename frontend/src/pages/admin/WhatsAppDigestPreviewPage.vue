<script setup>
import AdminPageHeading from './components/AdminPageHeading.vue'

defineProps({
  data: {
    type: Object,
    required: true,
  },
})
</script>

<template>
  <div class="space-y-6">
    <AdminPageHeading
      eyebrow="Administración global · WhatsApp"
      title="Previsualizar resumen diario"
      description="Elegí una empresa para ver el texto que recibiría cada usuario con avisos pendientes. La vista previa no envía mensajes ni modifica notificaciones."
    />

    <section class="rounded-2xl border border-border bg-surface-raised p-4 shadow-sm sm:p-6">
      <form :action="data.actions.preview" method="get" class="flex flex-col gap-3 sm:flex-row sm:items-end">
        <label for="digest-preview-company" class="grid flex-1 gap-1.5 text-sm font-semibold text-ink">
          Empresa
          <select
            id="digest-preview-company"
            name="empresa_id"
            class="min-h-11 rounded-lg border border-border-strong bg-surface px-3 text-sm font-normal text-ink"
            required
          >
            <option value="">Seleccioná una empresa</option>
            <option
              v-for="company in data.companies"
              :key="company.id"
              :value="company.id"
              :selected="Number(data.selectedCompanyId) === company.id"
            >
              {{ company.name }}{{ company.whatsappEnabled ? '' : ' · WhatsApp deshabilitado' }}
            </option>
          </select>
        </label>
        <button type="submit" class="min-h-11 rounded-lg bg-primary px-5 py-2.5 text-sm font-semibold text-white hover:bg-primary-hover focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
          Ver mensajes
        </button>
      </form>
    </section>

    <template v-if="data.selectedCompanyId">
      <div class="flex flex-wrap gap-2 text-xs font-semibold">
        <span class="rounded-full px-3 py-1" :class="data.companyWhatsAppEnabled ? 'bg-success-subtle text-success-strong' : 'bg-warning-subtle text-warning-strong'">
          WhatsApp {{ data.companyWhatsAppEnabled ? 'habilitado para la empresa' : 'deshabilitado para la empresa' }}
        </span>
        <span class="rounded-full px-3 py-1" :class="data.globalWhatsAppEnabled && data.whatsAppAvailable ? 'bg-success-subtle text-success-strong' : 'bg-warning-subtle text-warning-strong'">
          {{ data.globalWhatsAppEnabled && data.whatsAppAvailable ? 'Canal listo' : 'Canal global no disponible' }}
        </span>
        <span v-if="data.pilotEnabled" class="rounded-full bg-warning-subtle px-3 py-1 text-warning-strong">
          Modo piloto activo: el envío real se dirigiría al teléfono piloto
        </span>
      </div>

      <section v-if="data.previews.length" class="space-y-4" aria-live="polite">
        <header>
          <h2 class="text-lg font-bold text-ink">Mensajes para {{ data.selectedCompanyName }}</h2>
          <p class="mt-1 text-sm text-ink-muted">Cada tarjeta corresponde a un resumen personalizado por usuario.</p>
        </header>
        <article
          v-for="preview in data.previews"
          :key="preview.userId"
          class="overflow-hidden rounded-2xl border border-border bg-surface-raised shadow-sm"
        >
          <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border-subtle px-4 py-3 sm:px-5">
            <div>
              <h3 class="font-semibold text-ink">{{ preview.userName }}</h3>
              <p class="mt-0.5 text-xs text-ink-muted">{{ preview.target }} · {{ preview.itemCount }} tema(s) pendiente(s)</p>
            </div>
            <span class="rounded-full bg-success-subtle px-3 py-1 text-xs font-semibold text-success-strong">Solo vista previa</span>
          </div>
          <pre class="max-h-[34rem] overflow-auto whitespace-pre-wrap break-words p-4 text-sm leading-6 text-ink sm:p-5">{{ preview.message }}</pre>
        </article>
      </section>
      <div v-else class="rounded-2xl border border-border bg-surface-raised p-5 text-sm text-ink-muted" role="status">
        No hay mensajes pendientes para usuarios habilitados de {{ data.selectedCompanyName }}.
      </div>
    </template>
  </div>
</template>
