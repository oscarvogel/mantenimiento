<script setup>
import { EnvelopeIcon, GlobeAltIcon, LockClosedIcon, PaperAirplaneIcon } from '@heroicons/vue/24/outline'
import AdminPageHeading from './components/AdminPageHeading.vue'
import CsrfField from './components/CsrfField.vue'

defineProps({
  data: {
    type: Object,
    required: true,
  },
})

const toneClass = (tone) => ({
  success: 'bg-success-subtle text-success',
  warning: 'bg-warning-subtle text-warning-strong',
  muted: 'bg-surface-muted text-ink-muted',
}[tone] ?? 'bg-surface-muted text-ink-muted')
</script>

<template>
  <div class="space-y-6">
    <AdminPageHeading
      eyebrow="Administración global"
      title="Configuración de notificaciones"
      description="Configurá la infraestructura de envío una sola vez. Los destinatarios siguen definiéndose por empresa y usuario."
    />

    <section
      v-if="data.migration?.required"
      class="rounded-xl border border-warning/40 bg-warning-subtle p-5 shadow-card sm:p-6"
    >
      <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h2 class="font-semibold text-warning-strong">Falta preparar la base de datos</h2>
          <p class="mt-1 text-sm text-warning-strong">
            Esta función necesita una migración nueva. No hace falta consola: podés aplicarla desde acá.
          </p>
        </div>
        <form method="post" :action="data.actions.migrate">
          <CsrfField :csrf="data.csrf" />
          <button
            type="submit"
            class="inline-flex min-h-11 items-center justify-center rounded-lg bg-warning px-4 py-2.5 text-sm font-semibold text-warning-foreground"
          >
            Aplicar migraciones
          </button>
        </form>
      </div>
    </section>

    <div class="rounded-xl border border-border bg-surface-raised px-5 py-4 text-sm text-ink-muted shadow-card">
      <strong class="text-ink">Origen actual:</strong>
      {{ data.settings.source === 'database' ? 'configuración guardada en el sistema' : 'variables de entorno (.env)' }}.
      <span v-if="data.settings.updatedAt"> Última modificación: {{ data.settings.updatedAt }}.</span>
      Los secretos nunca se muestran en pantalla.
    </div>

    <form method="post" :action="data.actions.save" class="space-y-6">
      <CsrfField :csrf="data.csrf" />

      <section class="overflow-hidden rounded-xl border border-border bg-surface-raised shadow-card">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border-subtle px-5 py-4 sm:px-6">
          <div class="flex items-center gap-3">
            <span class="flex size-10 items-center justify-center rounded-lg bg-primary-subtle text-primary">
              <EnvelopeIcon class="size-5" aria-hidden="true" />
            </span>
            <div>
              <h2 class="font-semibold text-ink">Correo SMTP</h2>
              <p class="text-sm text-ink-muted">Canal usado para resúmenes, vencimientos y pruebas de correo.</p>
            </div>
          </div>
          <span class="rounded-full px-3 py-1 text-xs font-semibold" :class="toneClass(data.status.smtp.tone)">{{ data.status.smtp.label }}</span>
        </div>

        <div class="grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-4 sm:p-6">
          <label class="flex items-start gap-3 rounded-lg border border-border bg-surface-subtle p-3 sm:col-span-2 lg:col-span-4">
            <input type="hidden" name="smtp_enabled" value="0" />
            <input type="checkbox" name="smtp_enabled" value="1" :checked="data.settings.smtpEnabled" class="mt-0.5 size-4 rounded border-border-strong text-primary focus:ring-primary" />
            <span><span class="block text-sm font-medium text-ink">Habilitar correo</span><span class="mt-1 block text-xs text-ink-muted">Si está deshabilitado, el sistema no intentará enviar por este canal.</span></span>
          </label>

          <label class="block">
            <span class="mb-1.5 block text-sm font-medium text-ink">Protocolo</span>
            <select name="smtp_protocol" :value="data.settings.smtpProtocol" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink">
              <option value="smtp">SMTP</option>
              <option value="mail">PHP mail()</option>
              <option value="sendmail">Sendmail</option>
            </select>
          </label>
          <label class="block lg:col-span-2">
            <span class="mb-1.5 block text-sm font-medium text-ink">Servidor SMTP</span>
            <input name="smtp_host" maxlength="255" :value="data.settings.smtpHost" placeholder="smtp.midominio.com" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink" />
          </label>
          <label class="block">
            <span class="mb-1.5 block text-sm font-medium text-ink">Puerto</span>
            <input type="number" name="smtp_port" min="1" max="65535" :value="data.settings.smtpPort" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink" />
          </label>

          <label class="block lg:col-span-2">
            <span class="mb-1.5 block text-sm font-medium text-ink">Usuario</span>
            <input name="smtp_user" maxlength="255" :value="data.settings.smtpUser" autocomplete="off" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink" />
          </label>
          <label class="block lg:col-span-2">
            <span class="mb-1.5 block text-sm font-medium text-ink">Contraseña</span>
            <input type="password" name="smtp_pass" autocomplete="new-password" :placeholder="data.settings.smtpPasswordConfigured ? 'Configurada · dejar vacío para conservar' : 'Ingresar contraseña'" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink" />
          </label>

          <label class="block">
            <span class="mb-1.5 block text-sm font-medium text-ink">Cifrado</span>
            <select name="smtp_crypto" :value="data.settings.smtpCrypto" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink">
              <option value="">Ninguno</option>
              <option value="tls">TLS / STARTTLS</option>
              <option value="ssl">SSL</option>
            </select>
          </label>
          <label class="block">
            <span class="mb-1.5 block text-sm font-medium text-ink">Timeout (seg.)</span>
            <input type="number" name="smtp_timeout" min="1" max="120" :value="data.settings.smtpTimeout" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink" />
          </label>
          <label class="block lg:col-span-2">
            <span class="mb-1.5 block text-sm font-medium text-ink">Email remitente</span>
            <input type="email" name="smtp_from_email" maxlength="255" :value="data.settings.smtpFromEmail" placeholder="alertas@midominio.com" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink" />
          </label>
          <label class="block lg:col-span-2">
            <span class="mb-1.5 block text-sm font-medium text-ink">Nombre remitente</span>
            <input name="smtp_from_name" maxlength="255" :value="data.settings.smtpFromName" placeholder="Mantenimiento" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink" />
          </label>
        </div>
      </section>

      <section class="overflow-hidden rounded-xl border border-border bg-surface-raised shadow-card">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border-subtle px-5 py-4 sm:px-6">
          <div class="flex items-center gap-3">
            <span class="flex size-10 items-center justify-center rounded-lg bg-accent-subtle text-accent-active"><GlobeAltIcon class="size-5" /></span>
            <div><h2 class="font-semibold text-ink">Web Push</h2><p class="text-sm text-ink-muted">Notificaciones del navegador y PWA.</p></div>
          </div>
          <span class="rounded-full px-3 py-1 text-xs font-semibold" :class="toneClass(data.status.webPush.tone)">{{ data.status.webPush.label }}</span>
        </div>
        <div class="grid gap-4 p-5 sm:grid-cols-2 sm:p-6">
          <label class="flex items-start gap-3 rounded-lg border border-border bg-surface-subtle p-3 sm:col-span-2">
            <input type="hidden" name="webpush_enabled" value="0" />
            <input type="checkbox" name="webpush_enabled" value="1" :checked="data.settings.webPushEnabled" class="mt-0.5 size-4 rounded border-border-strong text-primary" />
            <span class="text-sm font-medium text-ink">Habilitar Web Push</span>
          </label>
          <label class="block sm:col-span-2"><span class="mb-1.5 block text-sm font-medium text-ink">Subject</span><input name="webpush_subject" :value="data.settings.webPushSubject" placeholder="mailto:alertas@midominio.com" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink" /></label>
          <label class="block"><span class="mb-1.5 block text-sm font-medium text-ink">Clave pública VAPID</span><textarea name="webpush_public_key" rows="3" :value="data.settings.webPushPublicKey" class="w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink"></textarea></label>
          <label class="block"><span class="mb-1.5 block text-sm font-medium text-ink">Clave privada VAPID</span><textarea name="webpush_private_key" rows="3" :placeholder="data.settings.webPushPrivateKeyConfigured ? 'Configurada · dejar vacío para conservar' : 'Ingresar clave privada'" class="w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink"></textarea></label>
        </div>
      </section>

      <section class="overflow-hidden rounded-xl border border-border bg-surface-raised shadow-card">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border-subtle px-5 py-4 sm:px-6">
          <div class="flex items-center gap-3">
            <span class="flex size-10 items-center justify-center rounded-lg bg-success-subtle text-success"><PaperAirplaneIcon class="size-5" /></span>
            <div><h2 class="font-semibold text-ink">WhatsApp</h2><p class="text-sm text-ink-muted">Configuración global preparada para Vogel WhatsApp API.</p></div>
          </div>
          <span class="rounded-full px-3 py-1 text-xs font-semibold" :class="toneClass(data.status.whatsApp.tone)">{{ data.status.whatsApp.label }}</span>
        </div>
        <div class="grid gap-4 p-5 sm:grid-cols-2 sm:p-6">
          <label class="flex items-start gap-3 rounded-lg border border-border bg-surface-subtle p-3 sm:col-span-2">
            <input type="hidden" name="whatsapp_enabled" value="0" />
            <input type="checkbox" name="whatsapp_enabled" value="1" :checked="data.settings.whatsAppEnabled" class="mt-0.5 size-4 rounded border-border-strong text-primary" />
            <span class="text-sm font-medium text-ink">Habilitar WhatsApp</span>
          </label>
          <label class="block sm:col-span-2"><span class="mb-1.5 block text-sm font-medium text-ink">URL del gateway</span><input name="whatsapp_api_url" maxlength="500" :value="data.settings.whatsAppApiUrl" placeholder="https://..." class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink" /></label>
          <label class="block"><span class="mb-1.5 block text-sm font-medium text-ink">API key</span><input type="password" name="whatsapp_api_key" autocomplete="new-password" :placeholder="data.settings.whatsAppApiKeyConfigured ? 'Configurada · dejar vacío para conservar' : 'Ingresar API key'" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink" /></label>
          <label class="block"><span class="mb-1.5 block text-sm font-medium text-ink">Instancia</span><input name="whatsapp_instance_id" maxlength="100" :value="data.settings.whatsAppInstanceId" placeholder="default" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink" /></label>
        </div>
      </section>

      <div class="flex items-center gap-3 rounded-xl border border-border bg-surface-raised p-4 shadow-card">
        <LockClosedIcon class="size-5 text-ink-muted" />
        <p class="flex-1 text-sm text-ink-muted">Las contraseñas y claves privadas se guardan cifradas y nunca vuelven al navegador.</p>
        <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground">Guardar configuración</button>
      </div>
    </form>

    <section class="rounded-xl border border-border bg-surface-raised p-5 shadow-card sm:p-6">
      <h2 class="font-semibold text-ink">Probar correo</h2>
      <p class="mt-1 text-sm text-ink-muted">Usa la configuración ya guardada. Guardá primero cualquier cambio pendiente.</p>
      <form method="post" :action="data.actions.testEmail" class="mt-4 flex flex-col gap-3 sm:flex-row">
        <CsrfField :csrf="data.csrf" />
        <input type="email" name="test_email" required :value="data.settings.smtpFromEmail" placeholder="destino@empresa.com" class="min-h-11 flex-1 rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink" />
        <button type="submit" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-primary px-4 py-2.5 text-sm font-semibold text-primary">
          <PaperAirplaneIcon class="size-5" /> Enviar prueba
        </button>
      </form>
    </section>
  </div>
</template>
