<script setup>
import { ref } from 'vue'
import { BellAlertIcon, BuildingOffice2Icon, ChatBubbleLeftRightIcon, IdentificationIcon, PlusIcon, ShieldCheckIcon, UserGroupIcon, UserPlusIcon } from '@heroicons/vue/24/outline'
import AdminMetric from './components/AdminMetric.vue'
import AdminPageHeading from './components/AdminPageHeading.vue'
import CsrfField from './components/CsrfField.vue'
import StatusBadge from './components/StatusBadge.vue'
import PaginationBar from '../operations/components/PaginationBar.vue'

defineProps({
  data: {
    type: Object,
    required: true,
  },
})

const isRoleAssigned = (user, roleId) => user.assignedRoleIds.includes(Number(roleId))

const activeSection = ref('summary')
const showCreateCompany = ref(false)
const showCreateAdministrator = ref(false)

const sections = [
  { key: 'summary', label: 'Resumen' },
  { key: 'companies', label: 'Empresas' },
  { key: 'users', label: 'Usuarios' },
  { key: 'notifications', label: 'Notificaciones' },
]
</script>

<template>
  <div class="admin-superadmin">
    <AdminPageHeading
      eyebrow="Administración global"
      title="Empresas y acceso de usuarios"
      description="Gestioná las organizaciones del sistema y controlá cada traslado sin perder trazabilidad."
    >
      <template #aside>
        <div class="inline-flex items-center gap-2 self-start rounded-lg border border-danger/20 bg-danger-subtle px-3 py-2 text-sm font-semibold text-danger-strong">
          <ShieldCheckIcon class="size-5" aria-hidden="true" />
          Alcance global
        </div>
      </template>
    </AdminPageHeading>

    <nav class="mb-6 flex flex-wrap gap-2 rounded-xl border border-border bg-surface-raised p-2 shadow-sm" aria-label="Secciones de administración global">
      <button
        v-for="section in sections"
        :key="section.key"
        type="button"
        class="inline-flex min-h-10 items-center justify-center rounded-lg px-4 py-2 text-sm font-semibold transition-colors"
        :class="activeSection === section.key ? 'bg-primary text-primary-foreground shadow-sm' : 'text-ink-muted hover:bg-surface-muted hover:text-ink'"
        @click="activeSection = section.key"
      >
        {{ section.label }}
      </button>
    </nav>

    <section v-if="activeSection === 'summary'" aria-label="Resumen de administración" class="mb-6 grid gap-3 sm:grid-cols-3">
      <AdminMetric label="Empresas" :value="data.metrics.companiesTotal" />
      <AdminMetric label="Empresas activas" :value="data.metrics.companiesActive" tone="success" />
      <AdminMetric label="Usuarios" :value="data.metrics.usersTotal" tone="muted" />
    </section>

    <section v-if="activeSection === 'summary'" class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
      <button type="button" class="group rounded-xl border border-border bg-surface-raised p-5 text-left shadow-sm transition hover:border-primary/30 hover:bg-primary-subtle/30" @click="activeSection = 'companies'">
        <BuildingOffice2Icon class="size-7 text-primary" aria-hidden="true" />
        <h2 class="mt-4 font-semibold text-ink">Empresas</h2>
        <p class="mt-1 text-sm leading-6 text-ink-muted">Datos fiscales, estado y destinatarios de alertas.</p>
        <span class="mt-4 inline-flex text-sm font-semibold text-primary">Administrar empresas →</span>
      </button>
      <button type="button" class="group rounded-xl border border-border bg-surface-raised p-5 text-left shadow-sm transition hover:border-primary/30 hover:bg-primary-subtle/30" @click="activeSection = 'users'">
        <UserGroupIcon class="size-7 text-primary" aria-hidden="true" />
        <h2 class="mt-4 font-semibold text-ink">Usuarios</h2>
        <p class="mt-1 text-sm leading-6 text-ink-muted">Empresa asignada, roles y permisos efectivos.</p>
        <span class="mt-4 inline-flex text-sm font-semibold text-primary">Administrar usuarios →</span>
      </button>
      <button type="button" class="group rounded-xl border border-border bg-surface-raised p-5 text-left shadow-sm transition hover:border-primary/30 hover:bg-primary-subtle/30" @click="activeSection = 'notifications'">
        <BellAlertIcon class="size-7 text-warning-strong" aria-hidden="true" />
        <h2 class="mt-4 font-semibold text-ink">Notificaciones</h2>
        <p class="mt-1 text-sm leading-6 text-ink-muted">Procesá alertas y revisá el canal de correo por empresa.</p>
        <span class="mt-4 inline-flex text-sm font-semibold text-primary">Gestionar notificaciones →</span>
      </button>
      <div class="rounded-xl border border-border bg-surface-raised p-5 shadow-sm">
        <ShieldCheckIcon class="size-7 text-success" aria-hidden="true" />
        <h2 class="mt-4 font-semibold text-ink">Alcance global</h2>
        <p class="mt-1 text-sm leading-6 text-ink-muted">Los cambios realizados aquí impactan en todas las empresas habilitadas.</p>
      </div>
    </section>

    <section v-if="(activeSection === 'summary' || activeSection === 'notifications') && data.migrations" class="mb-8 rounded-xl border border-primary/30 bg-primary-subtle p-5 shadow-card sm:p-6" aria-labelledby="migration-process-title">
      <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <h2 id="migration-process-title" class="font-semibold text-ink">Base de datos · migraciones</h2>
          <p class="mt-1 text-sm leading-6 text-ink-muted">
            {{ data.migrations.pendingCount > 0 ? `${data.migrations.pendingCount} migración(es) pendiente(s).` : 'Base de datos al día. No hay migraciones pendientes.' }}
          </p>
        </div>
        <form v-if="data.migrations.pendingCount > 0" method="post" :action="data.actions.applyMigrations" data-confirm data-confirm-title="¿Aplicar migraciones pendientes?" data-confirm-text="Se ejecutarán únicamente las migraciones que todavía no fueron aplicadas." data-confirm-button="Aplicar migraciones" class="shrink-0">
          <CsrfField :csrf="data.csrf" />
          <button type="submit" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-primary/90">
            Aplicar {{ data.migrations.pendingCount }} pendiente(s)
          </button>
        </form>
      </div>

      <div class="mt-4 grid gap-3 md:grid-cols-3">
        <div class="rounded-lg border border-border bg-surface-raised p-4">
          <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Fix #319</p>
          <p class="mt-1 text-sm font-semibold" :class="data.migrations.target319Registered ? 'text-success' : 'text-warning-strong'">
            {{ data.migrations.target319Registered ? 'Migración registrada' : 'Migración NO registrada' }}
          </p>
        </div>
        <div class="rounded-lg border border-border bg-surface-raised p-4">
          <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Duplicados activos</p>
          <p class="mt-1 text-sm font-semibold text-ink">{{ data.migrations.duplicateActiveGroups }} grupo(s) · {{ data.migrations.duplicateActiveRows }} registro(s)</p>
        </div>
        <div class="rounded-lg border border-border bg-surface-raised p-4">
          <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Migraciones registradas</p>
          <p class="mt-1 text-sm font-semibold text-ink">{{ data.migrations.appliedCount }}</p>
        </div>
      </div>

      <div v-if="data.migrations.pendingCount > 0" class="mt-4 rounded-lg border border-warning/25 bg-warning-subtle p-4">
        <p class="text-sm font-semibold text-warning-foreground">Pendientes detectadas</p>
        <ul class="mt-2 space-y-1 font-mono text-xs text-ink-muted">
          <li v-for="migration in data.migrations.pending" :key="migration">{{ migration }}</li>
        </ul>
      </div>
    </section>

    <section v-if="activeSection === 'notifications' && data.permissions.companiesEdit" class="mb-8 flex flex-col gap-4 rounded-xl border border-border bg-surface-raised p-5 shadow-card sm:flex-row sm:items-center sm:justify-between sm:p-6" aria-labelledby="notification-process-title">
      <div>
        <h2 id="notification-process-title" class="font-semibold text-ink">Procesar notificaciones ahora</h2>
        <p class="mt-1 max-w-2xl text-sm leading-6 text-ink-muted">Detecta vencimientos, genera eventos pendientes y despacha Email, Web Push y WhatsApp según la configuración disponible. La idempotencia evita duplicar el mismo ciclo.</p>
      </div>
      <form method="post" :action="data.actions.dispatchNotifications" data-confirm data-confirm-title="¿Ejecutar el ciclo de notificaciones?" data-confirm-text="Se procesarán los eventos y canales pendientes con la configuración actual." data-confirm-button="Ejecutar ahora" class="shrink-0">
        <CsrfField :csrf="data.csrf" />
        <button type="submit" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground shadow-sm transition-colors hover:bg-primary-hover active:bg-primary-active">
          Ejecutar ahora
        </button>
      </form>
    </section>

    <section v-if="activeSection === 'notifications' && data.permissions.companiesEdit && data.whatsapp" class="mb-8 overflow-hidden rounded-xl border border-border bg-surface-raised shadow-card" aria-labelledby="whatsapp-config-title">
      <div class="flex items-center gap-3 border-b border-border-subtle bg-surface-subtle px-5 py-4 sm:px-6">
        <span class="flex size-10 items-center justify-center rounded-lg bg-primary-subtle text-primary">
          <ChatBubbleLeftRightIcon class="size-5" aria-hidden="true" />
        </span>
        <div class="min-w-0">
          <h2 id="whatsapp-config-title" class="font-semibold text-ink">Configuración de WhatsApp</h2>
          <p class="text-sm text-ink-muted">Estado del gateway central usado para enviar avisos a choferes.</p>
        </div>
      </div>

      <div class="grid gap-4 p-5 sm:grid-cols-2 sm:p-6 lg:grid-cols-4">
        <div class="rounded-lg border border-border bg-surface-subtle p-4">
          <span class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">Canal</span>
          <p class="mt-1 text-sm font-semibold text-ink">{{ data.whatsapp.enabled ? 'Habilitado' : 'Deshabilitado' }}</p>
        </div>
        <div class="rounded-lg border border-border bg-surface-subtle p-4">
          <span class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">API key</span>
          <p class="mt-1 text-sm font-semibold text-ink">{{ data.whatsapp.apiKeyConfigured ? 'Configurada' : 'Sin configurar' }}</p>
        </div>
        <div class="rounded-lg border border-border bg-surface-subtle p-4">
          <span class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">Instancia emisora</span>
          <p class="mt-1 break-all text-sm font-semibold text-ink">{{ data.whatsapp.instanceId || 'Sin definir' }}</p>
        </div>
        <div class="rounded-lg border border-border bg-surface-subtle p-4">
          <span class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">Estado</span>
          <p class="mt-1 text-sm font-semibold" :class="data.whatsapp.available ? 'text-success' : 'text-danger'">
            {{ data.whatsapp.available ? 'Lista para enviar' : 'Configuración incompleta' }}
          </p>
        </div>

        <div class="sm:col-span-2 lg:col-span-4 rounded-lg border border-border bg-surface-subtle p-4">
          <p class="text-sm font-medium text-ink">URL del gateway</p>
          <p class="mt-1 break-all font-mono text-xs text-ink-muted">{{ data.whatsapp.apiUrl || 'Sin configurar' }}</p>
          <p class="mt-2 text-xs leading-5 text-ink-muted">La API key no se muestra. El número emisor pertenece a la instancia conectada en Vogel WhatsApp API.</p>
        </div>

        <div class="sm:col-span-2 lg:col-span-4 rounded-lg border border-primary/25 bg-primary-subtle p-4">
          <p class="text-sm font-semibold text-ink">Un solo cron para todas las automatizaciones</p>
          <p class="mt-1 text-xs leading-5 text-ink-muted">
            Ferozo ejecuta el ciclo global de notificaciones. En cada corrida se revisan vencimientos, informes y WhatsApp. El recordatorio de km se habilita una vez por semana desde
            <strong>día {{ data.whatsapp.weeklyReminderDay }} a las {{ data.whatsapp.weeklyReminderTime }}</strong>; si esa corrida falla, la siguiente lo recupera sin duplicar la semana.
          </p>
          <p class="mt-2 text-xs font-medium" :class="data.whatsapp.pilotEnabled && data.whatsapp.pilotPhoneConfigured ? 'text-success-strong' : 'text-warning-strong'">
            {{ data.whatsapp.pilotEnabled && data.whatsapp.pilotPhoneConfigured
              ? 'Modo piloto listo: las pruebas de WhatsApp van al celular piloto.'
              : 'Modo piloto incompleto: activalo y configurá el celular piloto antes de probar.' }}
          </p>
        </div>

        <form method="post" :action="data.whatsapp.testWeeklyReminderAction" class="sm:col-span-2 lg:col-span-4 rounded-lg border border-primary/30 bg-white p-4">
          <CsrfField :csrf="data.csrf" />
          <div class="flex flex-col gap-4">
            <div>
              <p class="text-sm font-semibold text-ink">Simulador semanal de kilometraje</p>
              <p class="mt-1 text-xs leading-5 text-ink-muted">Simula lunes, miércoles o viernes sin cambiar la fecha del servidor. Sólo funciona con modo piloto activo y todo WhatsApp se redirige al teléfono piloto.</p>
            </div>
            <label class="block max-w-sm">
              <span class="mb-1.5 block text-sm font-medium text-ink">Etapa a simular</span>
              <select name="etapa" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm">
                <option value="initial">Lunes · recordatorio inicial</option>
                <option value="wednesday">Miércoles · segundo recordatorio</option>
                <option value="friday">Viernes · aviso final + escalamiento</option>
              </select>
            </label>
            <button
              type="submit"
              :disabled="!data.whatsapp.available || !data.whatsapp.pilotEnabled || !data.whatsapp.pilotPhoneConfigured"
              data-confirm
              data-confirm-title="¿Simular esta etapa semanal?"
              data-confirm-text="Se evaluará la etapa seleccionada y cualquier WhatsApp se enviará únicamente al teléfono piloto."
              data-confirm-button="Ejecutar simulación"
              class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground shadow-sm disabled:cursor-not-allowed disabled:opacity-50"
            >
              Ejecutar simulación
            </button>
          </div>
        </form>

        <form method="post" :action="data.whatsapp.testAction" class="sm:col-span-2 lg:col-span-4 flex flex-col gap-3 rounded-lg border border-border p-4 sm:flex-row sm:items-end">
          <CsrfField :csrf="data.csrf" />
          <label class="block flex-1">
            <span class="mb-1.5 block text-sm font-medium text-ink">Celular para prueba</span>
            <input name="telefono_prueba" inputmode="tel" maxlength="30" placeholder="Ej. 3764123456 o 5493764123456" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" />
          </label>
          <button type="submit" :disabled="!data.whatsapp.available" class="inline-flex min-h-11 items-center justify-center rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground shadow-sm disabled:cursor-not-allowed disabled:opacity-50">
            Enviar prueba
          </button>
        </form>
        <form method="post" :action="data.whatsapp.preparePilotAction" class="sm:col-span-2 lg:col-span-4 rounded-lg border border-warning/30 bg-warning-subtle p-4">
          <CsrfField :csrf="data.csrf" />
          <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <p class="text-sm font-semibold text-warning-strong">Vencimiento por WhatsApp · prueba del cron real</p>
              <p class="mt-1 text-xs leading-5 text-warning-strong">Paso 1: genera en la empresa demo un chofer ficticio, su asignación y un vencimiento próximo. Paso 2: usá “Ejecutar ahora” arriba; así probás exactamente el mismo ciclo global que ejecuta Ferozo, con destino seguro al teléfono piloto.</p>
            </div>
            <button type="submit" :disabled="!data.whatsapp.available" class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-lg border border-warning px-4 py-2.5 text-sm font-semibold text-warning-strong disabled:cursor-not-allowed disabled:opacity-50">
              Preparar prueba piloto
            </button>
          </div>
        </form>
      </div>
    </section>

    <section v-if="activeSection === 'notifications'" class="mb-8 overflow-hidden rounded-xl border border-border bg-surface-raised shadow-card" aria-labelledby="notification-company-status-title">
      <div class="border-b border-border-subtle px-5 py-4 sm:px-6">
        <h2 id="notification-company-status-title" class="font-semibold text-ink">Canales por empresa</h2>
        <p class="mt-1 text-sm text-ink-muted">Verificá rápidamente qué empresas tienen configurado el correo de mantenimiento.</p>
      </div>
      <div class="divide-y divide-border-subtle">
        <div v-for="company in data.companies" :key="company.id" class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
          <div class="min-w-0">
            <p class="font-semibold text-ink">{{ company.displayName }}</p>
            <p class="mt-1 truncate text-sm text-ink-muted">{{ company.notificationEmail || company.email || 'Sin destinatario configurado' }}</p>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <StatusBadge :active="company.active" active-label="Empresa activa" inactive-label="Empresa inactiva" />
            <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold" :class="company.notificationEmailEnabled && (company.notificationEmail || company.email) ? 'bg-success-subtle text-success-strong' : 'bg-warning-subtle text-warning-foreground'">
              {{ company.notificationEmailEnabled && (company.notificationEmail || company.email) ? 'Email habilitado' : 'Email pendiente' }}
            </span>
            <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold" :class="company.dailyReportEnabled || company.weeklyReportEnabled ? 'bg-primary-subtle text-primary' : 'bg-surface-muted text-ink-muted'">
              {{ company.dailyReportEnabled || company.weeklyReportEnabled ? 'Informes gerenciales activos' : 'Informes gerenciales desactivados' }}
            </span>
          </div>
        </div>
      </div>
    </section>

    <section v-if="activeSection === 'companies'" class="mb-5 flex flex-col gap-3 rounded-xl border border-border bg-surface-raised p-4 shadow-sm sm:flex-row sm:items-center sm:justify-between">
      <div>
        <h2 class="font-semibold text-ink">Empresas</h2>
        <p class="mt-1 text-sm text-ink-muted">Administrá organizaciones sin tener todos los formularios abiertos al mismo tiempo.</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <button v-if="data.permissions.companiesEdit" type="button" class="inline-flex min-h-10 items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground" @click="showCreateCompany = !showCreateCompany">
          <PlusIcon class="size-4" aria-hidden="true" />
          {{ showCreateCompany ? 'Cerrar alta' : 'Nueva empresa' }}
        </button>
        <button v-if="data.permissions.createCompanyAdministrators" type="button" class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-border-strong px-4 py-2 text-sm font-semibold text-ink hover:bg-surface-muted" @click="showCreateAdministrator = !showCreateAdministrator">
          <UserPlusIcon class="size-4" aria-hidden="true" />
          {{ showCreateAdministrator ? 'Cerrar administrador' : 'Nuevo administrador' }}
        </button>
      </div>
    </section>

    <section v-if="activeSection === 'companies' && data.permissions.companiesEdit && showCreateCompany" class="mb-8 overflow-hidden rounded-xl border border-border bg-surface-raised shadow-card" aria-labelledby="create-company-title">
      <div class="flex items-center gap-3 border-b border-border-subtle bg-surface-subtle px-5 py-4 sm:px-6">
        <span class="flex size-10 items-center justify-center rounded-lg bg-primary-subtle text-primary">
          <PlusIcon class="size-5" aria-hidden="true" />
        </span>
        <div>
          <h2 id="create-company-title" class="font-semibold text-ink">Crear empresa</h2>
          <p class="text-sm text-ink-muted">Registrá la organización antes de asignarle usuarios.</p>
        </div>
      </div>

      <form method="post" :action="data.actions.createCompany" class="grid gap-4 p-5 sm:grid-cols-2 sm:p-6 lg:grid-cols-6">
        <CsrfField :csrf="data.csrf" />
        <label class="block lg:col-span-3">
          <span class="mb-1.5 block text-sm font-medium text-ink">Razón social <span class="text-danger" aria-hidden="true">*</span></span>
          <input name="razon_social" maxlength="255" required :value="data.oldInput.razon_social" autocomplete="organization" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm placeholder:text-ink-subtle focus:border-primary focus:ring-2 focus:ring-primary/20" />
        </label>
        <label class="block lg:col-span-3">
          <span class="mb-1.5 block text-sm font-medium text-ink">Nombre de fantasía</span>
          <input name="nombre_fantasia" maxlength="255" :value="data.oldInput.nombre_fantasia" autocomplete="organization" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" />
        </label>
        <label class="block sm:col-span-1 lg:col-span-2">
          <span class="mb-1.5 block text-sm font-medium text-ink">CUIT</span>
          <input name="cuit" maxlength="20" :value="data.oldInput.cuit" inputmode="numeric" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" />
        </label>
        <label class="block sm:col-span-1 lg:col-span-2">
          <span class="mb-1.5 block text-sm font-medium text-ink">Email general</span>
          <input type="email" name="email" maxlength="255" :value="data.oldInput.email" autocomplete="email" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" />
        </label>
        <label class="block sm:col-span-1 lg:col-span-2">
          <span class="mb-1.5 block text-sm font-medium text-ink">Teléfono</span>
          <input name="telefono" maxlength="50" :value="data.oldInput.telefono" autocomplete="tel" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" />
        </label>
        <label class="block sm:col-span-2 lg:col-span-4">
          <span class="mb-1.5 block text-sm font-medium text-ink">Correo para notificaciones de mantenimiento</span>
          <input type="email" name="email_notificaciones" maxlength="255" :value="data.oldInput.email_notificaciones" placeholder="alertas@empresa.com" autocomplete="email" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm placeholder:text-ink-subtle focus:border-primary focus:ring-2 focus:ring-primary/20" />
          <span class="mt-1.5 block text-xs leading-5 text-ink-muted">Recibirá preventivos próximos o vencidos, OT y alertas operativas. Si queda vacío se usa el email general.</span>
        </label>
        <label class="flex items-start gap-3 rounded-lg border border-border bg-surface-subtle p-3 sm:col-span-2 lg:col-span-2">
          <input type="hidden" name="notificaciones_whatsapp_habilitadas" value="0" />
          <input type="checkbox" name="notificaciones_whatsapp_habilitadas" value="1" class="mt-0.5 size-4 rounded border-border-strong text-primary focus:ring-primary" />
          <span><span class="block text-sm font-medium text-ink">Habilitar WhatsApp para esta empresa</span><span class="mt-1 block text-xs leading-5 text-ink-muted">Los vencimientos podrán notificarse al chofer asignado.</span></span>
        </label>
        <label class="block sm:col-span-2 lg:col-span-2">
          <span class="mb-1.5 block text-sm font-medium text-ink">Instance ID WhatsApp</span>
          <input name="whatsapp_instance_id" maxlength="100" placeholder="default" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" />
          <span class="mt-1.5 block text-xs leading-5 text-ink-muted">Si queda vacío usa la instancia global configurada.</span>
        </label>
        <label class="block sm:col-span-2 lg:col-span-2">
          <span class="mb-1.5 block text-sm font-medium text-ink">Idioma de avisos y carga pública</span>
          <select name="idioma_notificaciones" :value="data.oldInput.idioma_notificaciones || 'ES'" required class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20">
            <option value="ES">Español</option>
            <option value="PT">Português</option>
          </select>
          <span class="mt-1.5 block text-xs leading-5 text-ink-muted">Define el idioma del recordatorio semanal y de la pantalla pública de kilometraje.</span>
        </label>
        <label class="flex items-start gap-3 rounded-lg border border-border bg-surface-subtle p-3 sm:col-span-2 lg:col-span-2">
          <input type="hidden" name="notificaciones_email_habilitadas" value="0" />
          <input type="checkbox" name="notificaciones_email_habilitadas" value="1" checked class="mt-0.5 size-4 rounded border-border-strong text-primary focus:ring-primary" />
          <span><span class="block text-sm font-medium text-ink">Enviar notificaciones por email</span><span class="mt-1 block text-xs leading-5 text-ink-muted">Podés desactivar el canal sin borrar el destinatario.</span></span>
        </label>
        <div class="sm:col-span-2 lg:col-span-6">
          <button type="submit" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground shadow-sm transition-colors hover:bg-primary-hover active:bg-primary-active">
            <BuildingOffice2Icon class="size-5" aria-hidden="true" />
            Crear empresa
          </button>
        </div>
      </form>
    </section>

    <section
      v-if="activeSection === 'companies' && data.permissions.createCompanyAdministrators && showCreateAdministrator"
      class="mb-8 overflow-hidden rounded-xl border border-border bg-surface-raised shadow-card"
      aria-labelledby="create-company-administrator-title"
    >
      <div class="flex items-center gap-3 border-b border-border-subtle bg-surface-subtle px-5 py-4 sm:px-6">
        <span class="flex size-10 items-center justify-center rounded-lg bg-accent-subtle text-accent-active">
          <UserPlusIcon class="size-5" aria-hidden="true" />
        </span>
        <div>
          <h2 id="create-company-administrator-title" class="font-semibold text-ink">Crear administrador de empresa</h2>
          <p class="text-sm text-ink-muted">Creá la cuenta inicial y asignale automáticamente el acceso completo a su empresa.</p>
        </div>
      </div>

      <form
        v-if="data.assignableCompanies.length"
        method="post"
        :action="data.actions.createCompanyAdministrator"
        class="grid gap-4 p-5 sm:grid-cols-2 sm:p-6 lg:grid-cols-6"
      >
        <CsrfField :csrf="data.csrf" />
        <label class="block lg:col-span-3">
          <span class="mb-1.5 block text-sm font-medium text-ink">Empresa <span class="text-danger" aria-hidden="true">*</span></span>
          <select name="admin_empresa_id" required :value="data.oldInput.admin_empresa_id" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20">
            <option value="" disabled>Seleccionar empresa</option>
            <option v-for="company in data.assignableCompanies" :key="company.id" :value="company.id">{{ company.name }}</option>
          </select>
        </label>
        <label class="block lg:col-span-3">
          <span class="mb-1.5 block text-sm font-medium text-ink">Nombre completo <span class="text-danger" aria-hidden="true">*</span></span>
          <input name="admin_nombre" maxlength="255" required :value="data.oldInput.admin_nombre" autocomplete="name" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" />
        </label>
        <label class="block lg:col-span-3">
          <span class="mb-1.5 block text-sm font-medium text-ink">Email de acceso <span class="text-danger" aria-hidden="true">*</span></span>
          <input type="email" name="admin_email" maxlength="255" required :value="data.oldInput.admin_email" autocomplete="email" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" />
        </label>
        <label class="block lg:col-span-3">
          <span class="mb-1.5 block text-sm font-medium text-ink">Motivo <span class="text-danger" aria-hidden="true">*</span></span>
          <input name="admin_motivo" minlength="5" maxlength="255" required :value="data.oldInput.admin_motivo" placeholder="Ej.: alta inicial aprobada" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm placeholder:text-ink-subtle focus:border-primary focus:ring-2 focus:ring-primary/20" />
        </label>
        <label class="block lg:col-span-3">
          <span class="mb-1.5 block text-sm font-medium text-ink">Contraseña temporal <span class="text-danger" aria-hidden="true">*</span></span>
          <input type="password" name="admin_password" minlength="8" maxlength="255" required autocomplete="new-password" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" />
        </label>
        <label class="block lg:col-span-3">
          <span class="mb-1.5 block text-sm font-medium text-ink">Confirmar contraseña <span class="text-danger" aria-hidden="true">*</span></span>
          <input type="password" name="admin_password_confirmation" minlength="8" maxlength="255" required autocomplete="new-password" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" />
        </label>
        <div class="lg:col-span-6">
          <p class="mb-3 text-xs leading-5 text-ink-muted">La cuenta se crea activa con el rol Administrador y acceso automático a todas las sucursales actuales y futuras de la empresa.</p>
          <button type="submit" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-accent px-4 py-2.5 text-sm font-semibold text-accent-foreground shadow-sm transition-colors hover:bg-accent-hover active:bg-accent-active">
            <UserPlusIcon class="size-5" aria-hidden="true" />
            Crear administrador
          </button>
        </div>
      </form>

      <div v-else class="px-5 py-6 text-sm text-ink-muted sm:px-6">
        Primero creá y activá una empresa para poder registrar su administrador.
      </div>
    </section>

    <section v-if="activeSection === 'companies'" aria-labelledby="companies-title" class="mb-9">
      <div class="mb-4 flex items-center justify-between gap-3">
        <div>
          <h2 id="companies-title" class="text-lg font-bold text-ink">Empresas</h2>
          <p class="mt-1 text-sm text-ink-muted">Datos fiscales, contacto y destinatario de alertas.</p>
        </div>
        <span class="rounded-full bg-surface-muted px-3 py-1 text-sm font-semibold text-ink-muted">{{ data.metrics.companiesTotal }}</span>
      </div>

      <div v-if="data.companies.length" class="grid gap-4 xl:grid-cols-2">
        <article v-for="company in data.companies" :key="company.id" class="overflow-hidden rounded-xl border border-border bg-surface-raised shadow-card">
          <div class="flex items-start justify-between gap-4 border-b border-border-subtle px-5 py-4">
            <div class="flex min-w-0 items-center gap-3">              <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary-subtle text-primary">
                <BuildingOffice2Icon class="size-5" aria-hidden="true" />
              </span>
              <div class="min-w-0">
                <h3 class="truncate font-semibold text-ink">{{ company.displayName }}</h3>
                <p class="text-xs font-medium text-ink-subtle">Empresa #{{ company.id }}</p>
              </div>
            </div>
            <StatusBadge :active="company.active" active-label="Activa" inactive-label="Inactiva" />
          </div>

          <form v-if="data.permissions.companiesEdit" method="post" :action="company.actions.update" data-confirm data-confirm-title="¿Guardar los cambios de la empresa?" data-confirm-text="Los datos de la empresa se actualizarán en el sistema." data-confirm-button="Guardar empresa" class="grid gap-4 p-5 sm:grid-cols-2">
            <CsrfField :csrf="data.csrf" />
            <label class="block">
              <span class="mb-1.5 block text-sm font-medium text-ink">Razón social</span>
              <input name="razon_social" required maxlength="255" :value="company.razonSocial" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" />
            </label>
            <label class="block">
              <span class="mb-1.5 block text-sm font-medium text-ink">Nombre de fantasía</span>
              <input name="nombre_fantasia" maxlength="255" :value="company.nombreFantasia" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" />
            </label>
            <label class="block">
              <span class="mb-1.5 block text-sm font-medium text-ink">CUIT</span>
              <input name="cuit" maxlength="20" :value="company.cuit" inputmode="numeric" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" />
            </label>
            <label class="block">
              <span class="mb-1.5 block text-sm font-medium text-ink">Email general</span>
              <input type="email" name="email" maxlength="255" :value="company.email" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" />
            </label>
            <label class="block sm:col-span-2">
              <span class="mb-1.5 block text-sm font-medium text-ink">Correo para notificaciones de mantenimiento</span>
              <input type="email" name="email_notificaciones" maxlength="255" :value="company.notificationEmail" placeholder="Usará el email general si queda vacío" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm placeholder:text-ink-subtle focus:border-primary focus:ring-2 focus:ring-primary/20" />
              <span class="mt-1.5 block text-xs leading-5 text-ink-muted">Destino de preventivos próximos/vencidos, OT y demás alertas operativas de esta empresa.</span>
            </label>
            <label class="block">
              <span class="mb-1.5 block text-sm font-medium text-ink">Teléfono</span>
              <input name="telefono" maxlength="50" :value="company.telefono" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" />
            </label>
            <label class="block">
              <span class="mb-1.5 block text-sm font-medium text-ink">Estado</span>
              <select name="estado" :value="company.active ? '1' : '0'" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20">
                <option value="1">Activa</option>
                <option value="0">Inactiva</option>
              </select>
            </label>
            <label class="block">
              <span class="mb-1.5 block text-sm font-medium text-ink">Instance ID WhatsApp</span>
              <input name="whatsapp_instance_id" maxlength="100" :value="company.whatsappInstanceId" placeholder="Usará la instancia global" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" />
            </label>
            <label class="block">
              <span class="mb-1.5 block text-sm font-medium text-ink">Idioma de avisos y carga pública</span>
              <select name="idioma_notificaciones" :value="company.notificationLocale || 'ES'" required class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20">
                <option value="ES">Español</option>
                <option value="PT">Português</option>
              </select>
              <span class="mt-1.5 block text-xs leading-5 text-ink-muted">Se usa en el recordatorio semanal y en el formulario público abierto desde el link.</span>
            </label>
            <label class="flex items-start gap-3 rounded-lg border border-border bg-surface-subtle p-3">
              <input type="hidden" name="notificaciones_whatsapp_habilitadas" value="0" />
              <input type="checkbox" name="notificaciones_whatsapp_habilitadas" value="1" :checked="company.whatsappEnabled" class="mt-0.5 size-4 rounded border-border-strong text-primary focus:ring-primary" />
              <span><span class="block text-sm font-medium text-ink">WhatsApp habilitado</span><span class="mt-1 block text-xs leading-5 text-ink-muted">Permite avisos al chofer de esta empresa.</span></span>
            </label>
            <label class="flex items-start gap-3 rounded-lg border border-border bg-surface-subtle p-3 sm:col-span-2">
              <input type="hidden" name="notificaciones_email_habilitadas" value="0" />
              <input type="checkbox" name="notificaciones_email_habilitadas" value="1" :checked="company.notificationEmailEnabled" class="mt-0.5 size-4 rounded border-border-strong text-primary focus:ring-primary" />
              <span><span class="block text-sm font-medium text-ink">Enviar notificaciones de mantenimiento por email</span><span class="mt-1 block text-xs leading-5 text-ink-muted">Al desactivarlo se conserva el correo configurado, pero no se generan entregas empresariales.</span></span>
            </label>
            <div class="rounded-lg border border-border bg-surface-subtle p-4 sm:col-span-2">
              <div class="mb-3">
                <p class="text-sm font-semibold text-ink">Informes para dueño / gerencia</p>
                <p class="mt-1 text-xs leading-5 text-ink-muted">Se envían separados de las alertas operativas. Podés indicar varios correos separados por coma.</p>
                <p class="mt-2 text-xs leading-5 text-ink-muted">La hora no crea un cron por empresa: el programador global revisa qué informes están vencidos en cada ejecución. En producción puede ejecutarse, por ejemplo, cada 30 minutos.</p>
              </div>
              <label class="block">
                <span class="mb-1.5 block text-sm font-medium text-ink">Destinatarios de informes</span>
                <input name="emails_informes" maxlength="1000" :value="company.managementReportEmails" placeholder="gerencia@empresa.com, dueño@empresa.com" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" />
                <span class="mt-1 block text-xs text-ink-muted">Si queda vacío se usa el correo de notificaciones y luego el email general.</span>
              </label>
              <div class="mt-4 grid gap-3 sm:grid-cols-2">
                <div class="rounded-lg border border-border bg-white p-3">
                  <label class="flex items-start gap-3">
                    <input type="hidden" name="informe_diario_habilitado" value="0" />
                    <input type="checkbox" name="informe_diario_habilitado" value="1" :checked="company.dailyReportEnabled" class="mt-0.5 size-4 rounded border-border-strong text-primary focus:ring-primary" />
                    <span class="text-sm font-medium text-ink">Resumen diario</span>
                  </label>
                  <label class="mt-3 block">
                    <span class="mb-1 block text-xs font-medium text-ink-muted">Hora</span>
                    <input type="time" name="informe_diario_hora" :value="company.dailyReportTime || '07:00'" class="min-h-10 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink" />
                  </label>
                </div>
                <div class="rounded-lg border border-border bg-white p-3">
                  <label class="flex items-start gap-3">
                    <input type="hidden" name="informe_semanal_habilitado" value="0" />
                    <input type="checkbox" name="informe_semanal_habilitado" value="1" :checked="company.weeklyReportEnabled" class="mt-0.5 size-4 rounded border-border-strong text-primary focus:ring-primary" />
                    <span class="text-sm font-medium text-ink">Resumen semanal</span>
                  </label>
                  <div class="mt-3 grid grid-cols-2 gap-2">
                    <label class="block">
                      <span class="mb-1 block text-xs font-medium text-ink-muted">Día</span>
                      <select name="informe_semanal_dia" :value="company.weeklyReportDay || 1" class="min-h-10 w-full rounded-lg border border-border-strong bg-white px-2 py-2 text-sm text-ink">
                        <option :value="1">Lunes</option><option :value="2">Martes</option><option :value="3">Miércoles</option><option :value="4">Jueves</option><option :value="5">Viernes</option><option :value="6">Sábado</option><option :value="7">Domingo</option>
                      </select>
                    </label>
                    <label class="block">
                      <span class="mb-1 block text-xs font-medium text-ink-muted">Hora</span>
                      <input type="time" name="informe_semanal_hora" :value="company.weeklyReportTime || '07:00'" class="min-h-10 w-full rounded-lg border border-border-strong bg-white px-2 py-2 text-sm text-ink" />
                    </label>
                  </div>
                </div>
              </div>
            </div>
            <div class="flex flex-wrap gap-2 sm:col-span-2">
              <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-lg border border-primary px-4 py-2.5 text-sm font-semibold text-primary transition-colors hover:bg-primary-subtle">Guardar empresa</button>
              <button
                type="submit"
                :formaction="`${company.actions.update}/notificaciones/prueba`"
                formnovalidate
                data-confirm
                data-confirm-title="¿Enviar correo de prueba?"
                data-confirm-text="Se enviará usando el destinatario actualmente guardado para esta empresa."
                data-confirm-button="Enviar prueba"
                :disabled="!company.active || !company.notificationEmailEnabled || (!company.notificationEmail && !company.email)"
                class="inline-flex min-h-11 items-center justify-center rounded-lg border border-accent-active px-4 py-2.5 text-sm font-semibold text-accent-active transition-colors hover:bg-accent-subtle disabled:cursor-not-allowed disabled:opacity-50"
              >
                Enviar correo de prueba
              </button>
              <button
                type="submit"
                :formaction="company.actions.testManagementReport"
                name="tipo_informe"
                value="DAILY"
                formnovalidate
                data-confirm
                data-confirm-title="¿Enviar informe diario de prueba?"
                data-confirm-text="Usará los datos actuales de la empresa y los destinatarios guardados."
                data-confirm-button="Enviar informe"
                :disabled="!company.active"
                class="inline-flex min-h-11 items-center justify-center rounded-lg border border-primary px-4 py-2.5 text-sm font-semibold text-primary transition-colors hover:bg-primary-subtle disabled:cursor-not-allowed disabled:opacity-50"
              >
                Probar informe diario
              </button>
              <button
                type="submit"
                :formaction="company.actions.testManagementReport"
                name="tipo_informe"
                value="WEEKLY"
                formnovalidate
                data-confirm
                data-confirm-title="¿Enviar informe semanal de prueba?"
                data-confirm-text="Usará los datos actuales de la empresa y los destinatarios guardados."
                data-confirm-button="Enviar informe"
                :disabled="!company.active"
                class="inline-flex min-h-11 items-center justify-center rounded-lg border border-border-strong px-4 py-2.5 text-sm font-semibold text-ink transition-colors hover:bg-surface-muted disabled:cursor-not-allowed disabled:opacity-50"
              >
                Probar informe semanal
              </button>
            </div>
            <p class="sm:col-span-2 text-xs leading-5 text-ink-muted">La prueba usa la configuración guardada. Si cambiaste el correo, guardá primero la empresa y después enviá la prueba.</p>
          </form>

          <dl v-else class="grid gap-4 p-5 text-sm sm:grid-cols-2">
            <div><dt class="text-ink-subtle">Razón social</dt><dd class="mt-1 font-medium text-ink">{{ company.razonSocial }}</dd></div>
            <div><dt class="text-ink-subtle">CUIT</dt><dd class="mt-1 font-medium text-ink">{{ company.cuit || '—' }}</dd></div>
            <div><dt class="text-ink-subtle">Email general</dt><dd class="mt-1 break-all font-medium text-ink">{{ company.email || '—' }}</dd></div>
            <div><dt class="text-ink-subtle">Teléfono</dt><dd class="mt-1 font-medium text-ink">{{ company.telefono || '—' }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-ink-subtle">Notificaciones de mantenimiento</dt><dd class="mt-1 break-all font-medium text-ink">{{ company.notificationEmail || company.email || 'Sin destinatario' }}</dd><dd class="mt-1 text-xs text-ink-muted">{{ company.notificationEmail ? 'Correo específico' : (company.email ? 'Fallback al email general' : 'No se enviarán emails') }} · {{ company.notificationEmailEnabled ? 'Habilitadas' : 'Deshabilitadas' }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-ink-subtle">Informes gerenciales</dt><dd class="mt-1 break-all font-medium text-ink">{{ company.managementReportEmails || company.notificationEmail || company.email || 'Sin destinatario' }}</dd><dd class="mt-1 text-xs text-ink-muted">Diario: {{ company.dailyReportEnabled ? company.dailyReportTime : 'deshabilitado' }} · Semanal: {{ company.weeklyReportEnabled ? 'día ' + company.weeklyReportDay + ' · ' + company.weeklyReportTime : 'deshabilitado' }}</dd></div>
          </dl>
        </article>
      </div>

      <div v-else class="rounded-xl border border-dashed border-border-strong bg-surface-raised px-6 py-10 text-center">
        <BuildingOffice2Icon class="mx-auto size-10 text-ink-subtle" aria-hidden="true" />
        <p class="mt-3 font-semibold text-ink">Todavía no hay empresas</p>
        <p class="mt-1 text-sm text-ink-muted">Creá la primera organización para comenzar a asignar usuarios.</p>
      </div>

      <PaginationBar :pagination="data.companiesPagination" />
    </section>

    <section v-if="activeSection === 'users'" aria-labelledby="global-users-title">
      <div class="mb-4 flex items-center justify-between gap-3">
        <div>
          <h2 id="global-users-title" class="text-lg font-bold text-ink">Usuarios del sistema</h2>
          <p class="mt-1 text-sm text-ink-muted">Empresa y roles efectivos de cada cuenta.</p>
        </div>
        <span class="rounded-full bg-surface-muted px-3 py-1 text-sm font-semibold text-ink-muted">{{ data.metrics.usersTotal }}</span>
      </div>

      <div class="space-y-4">
        <article v-for="user in data.users" :key="user.id" class="overflow-hidden rounded-xl border border-border bg-surface-raised shadow-card">
          <div class="flex flex-col gap-3 border-b border-border-subtle px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex min-w-0 items-center gap-3">
              <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-surface-muted text-ink-muted">
                <UserGroupIcon class="size-5" aria-hidden="true" />
              </span>
              <div class="min-w-0">
                <h3 class="truncate font-semibold text-ink">{{ user.name }}</h3>
                <p class="truncate text-sm text-ink-muted">{{ user.email }}</p>
              </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
              <span v-if="user.isSuperAdmin" class="inline-flex rounded-full bg-danger-subtle px-2.5 py-1 text-xs font-semibold text-danger-strong">Superadministrador</span>
              <span v-else class="inline-flex rounded-full bg-primary-subtle px-2.5 py-1 text-xs font-semibold text-primary">{{ user.companyName || 'Sin empresa' }}</span>
              <StatusBadge :active="user.active" />
            </div>
          </div>

          <div v-if="user.isSuperAdmin" class="flex items-start gap-3 p-5 text-sm text-ink-muted">
            <ShieldCheckIcon class="size-5 shrink-0 text-danger" aria-hidden="true" />
            Esta cuenta tiene capacidad global y no utiliza roles empresariales.
          </div>

          <div v-else class="grid gap-6 p-5 lg:grid-cols-2 lg:p-6">
            <form v-if="data.permissions.assignCompanies" method="post" :action="user.actions.assignCompany" data-confirm data-confirm-title="¿Asignar la empresa al usuario?" data-confirm-text="Se actualizará el acceso del usuario con trazabilidad." data-confirm-button="Asignar" class="rounded-xl border border-border-subtle bg-surface-subtle p-4">
              <CsrfField :csrf="data.csrf" />
              <div class="mb-4 flex items-center gap-2">
                <BuildingOffice2Icon class="size-5 text-accent-active" aria-hidden="true" />
                <h4 class="font-semibold text-ink">Empresa asignada</h4>
              </div>
              <label class="block">
                <span class="mb-1.5 block text-sm font-medium text-ink">Empresa</span>
                <select name="empresa_id" required :value="user.companyId" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20">
                  <option v-for="company in data.assignableCompanies" :key="company.id" :value="company.id">{{ company.name }}</option>
                </select>
              </label>
              <label class="mt-3 block">
                <span class="mb-1.5 block text-sm font-medium text-ink">Motivo del cambio</span>
                <input name="motivo" minlength="5" maxlength="255" required placeholder="Ej.: reasignación organizativa" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm placeholder:text-ink-subtle focus:border-primary focus:ring-2 focus:ring-primary/20" />
              </label>
              <p class="mt-2 text-xs leading-5 text-warning-strong">Un traslado retira los roles y sucursales anteriores.</p>
              <button type="submit" class="mt-4 inline-flex min-h-11 items-center justify-center rounded-lg border border-accent-active px-4 py-2.5 text-sm font-semibold text-accent-active transition-colors hover:bg-accent-subtle">Asignar empresa</button>
            </form>

            <form v-if="data.permissions.assignRoles" method="post" :action="user.actions.assignRoles" data-confirm data-confirm-title="¿Asignar los roles al usuario?" data-confirm-text="Se actualizarán los permisos del usuario con trazabilidad." data-confirm-button="Asignar" class="rounded-xl border border-border-subtle bg-surface-subtle p-4">
              <CsrfField :csrf="data.csrf" />
              <fieldset>
                <legend class="mb-3 flex items-center gap-2 font-semibold text-ink">
                  <IdentificationIcon class="size-5 text-primary" aria-hidden="true" />
                  Roles empresariales
                </legend>
                <div class="grid gap-2 sm:grid-cols-2">
                  <label v-for="role in data.roles" :key="role.id" class="flex min-h-11 cursor-pointer items-center gap-3 rounded-lg border border-border bg-white px-3 py-2 text-sm text-ink transition-colors hover:border-primary/50">
                    <input type="checkbox" name="roles[]" :value="role.id" :checked="isRoleAssigned(user, role.id)" class="size-4 rounded border-border-strong text-primary focus:ring-primary" />
                    <span>{{ role.name }}</span>
                  </label>
                </div>
              </fieldset>
              <label class="mt-3 block">
                <span class="mb-1.5 block text-sm font-medium text-ink">Motivo de la asignación</span>
                <input name="motivo" minlength="5" maxlength="255" required placeholder="Ej.: responsabilidades aprobadas" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm placeholder:text-ink-subtle focus:border-primary focus:ring-2 focus:ring-primary/20" />
              </label>
              <button type="submit" class="mt-4 inline-flex min-h-11 items-center justify-center rounded-lg border border-primary px-4 py-2.5 text-sm font-semibold text-primary transition-colors hover:bg-primary-subtle">Guardar roles</button>
            </form>

            <div v-if="!data.permissions.assignCompanies && !data.permissions.assignRoles" class="lg:col-span-2">
              <p class="text-sm font-medium text-ink-muted">No tenés permisos para modificar este acceso.</p>
              <div class="mt-3 flex flex-wrap gap-2">
                <span v-for="role in user.roles" :key="role.id" class="rounded-full bg-primary-subtle px-2.5 py-1 text-xs font-semibold text-primary">{{ role.name }}</span>
                <span v-if="!user.roles.length" class="text-sm text-ink-subtle">Sin roles asignados</span>
              </div>
            </div>
          </div>
        </article>

        <div v-if="!data.users.length" class="rounded-xl border border-dashed border-border-strong bg-surface-raised px-6 py-10 text-center">
          <UserGroupIcon class="mx-auto size-10 text-ink-subtle" aria-hidden="true" />
          <p class="mt-3 font-semibold text-ink">No hay usuarios para mostrar</p>
        </div>
      </div>

      <PaginationBar :pagination="data.usersPagination" />    </section>
  </div>
</template>