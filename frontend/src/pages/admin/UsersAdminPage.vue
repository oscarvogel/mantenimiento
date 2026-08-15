<script setup>
import { ChevronDownIcon, ClockIcon, IdentificationIcon, KeyIcon, PlusIcon, ShieldCheckIcon, UserIcon, UserPlusIcon, UsersIcon } from '@heroicons/vue/24/outline'
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

const includesId = (ids, id) => ids.includes(Number(id))
</script>

<template>
  <div class="admin-users">
    <AdminPageHeading
      :eyebrow="data.company.name"
      title="Usuarios y acceso"
      description="Gestioná cuentas, roles y sucursales sin salir del alcance de tu empresa."
    >
      <template #aside>
        <div class="inline-flex items-center gap-2 self-start rounded-lg border border-primary/20 bg-primary-subtle px-3 py-2 text-sm font-semibold text-primary">
          <ShieldCheckIcon class="size-5" aria-hidden="true" />
          Acceso por empresa
        </div>
      </template>
    </AdminPageHeading>

    <section aria-label="Resumen de usuarios" class="mb-6 grid gap-3 sm:grid-cols-3">
      <AdminMetric label="Usuarios" :value="data.metrics.total" />
      <AdminMetric label="Activos" :value="data.metrics.active" tone="success" />
      <AdminMetric label="Inactivos" :value="data.metrics.inactive" tone="muted" />
    </section>

    <section v-if="data.permissions.create" class="mb-8 overflow-hidden rounded-xl border border-border bg-surface-raised shadow-card" aria-labelledby="new-user-title">
      <div class="flex items-center gap-3 border-b border-border-subtle bg-surface-subtle px-5 py-4 sm:px-6">
        <span class="flex size-10 items-center justify-center rounded-lg bg-primary-subtle text-primary">
          <UserPlusIcon class="size-5" aria-hidden="true" />
        </span>
        <div>
          <h2 id="new-user-title" class="font-semibold text-ink">Nuevo usuario</h2>
          <p class="text-sm text-ink-muted">Creá la cuenta y definí su acceso inicial.</p>
        </div>
      </div>

      <form method="post" :action="data.actions.create" class="grid gap-5 p-5 sm:grid-cols-2 sm:p-6">
        <CsrfField :csrf="data.csrf" />
        <label class="block">
          <span class="mb-1.5 block text-sm font-medium text-ink">Nombre <span class="text-danger" aria-hidden="true">*</span></span>
          <input name="nombre" maxlength="255" required :value="data.oldInput.nombre" autocomplete="name" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" />
        </label>
        <label class="block">
          <span class="mb-1.5 block text-sm font-medium text-ink">Email <span class="text-danger" aria-hidden="true">*</span></span>
          <input type="email" name="email" maxlength="255" required :value="data.oldInput.email" autocomplete="email" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" />
        </label>
        <label class="block">
          <span class="mb-1.5 block text-sm font-medium text-ink">Contraseña inicial <span class="text-danger" aria-hidden="true">*</span></span>
          <input type="password" name="password" minlength="8" maxlength="255" required autocomplete="new-password" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" />
          <span class="mt-1 block text-xs text-ink-subtle">Mínimo 8 caracteres.</span>
        </label>
        <label class="block">
          <span class="mb-1.5 block text-sm font-medium text-ink">Repetir contraseña <span class="text-danger" aria-hidden="true">*</span></span>
          <input type="password" name="password_confirmation" minlength="8" maxlength="255" required autocomplete="new-password" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" />
        </label>

        <fieldset class="rounded-xl border border-border-subtle bg-surface-subtle p-4">
          <legend class="px-1 text-sm font-semibold text-ink">Roles</legend>
          <div class="mt-2 grid gap-2 sm:grid-cols-2">
            <label v-for="role in data.roles" :key="role.id" class="flex min-h-11 cursor-pointer items-center gap-3 rounded-lg border border-border bg-white px-3 py-2 text-sm text-ink hover:border-primary/50">
              <input type="checkbox" name="roles[]" :value="role.id" :checked="includesId(data.oldInput.roleIds, role.id)" class="size-4 rounded border-border-strong text-primary focus:ring-primary" />
              <span>{{ role.name }}</span>
            </label>
          </div>
        </fieldset>

        <fieldset class="rounded-xl border border-border-subtle bg-surface-subtle p-4">
          <legend class="px-1 text-sm font-semibold text-ink">Sucursales</legend>
          <div class="mt-2 grid gap-2 sm:grid-cols-2">
            <label v-for="branch in data.assignableBranches" :key="branch.id" class="flex min-h-11 cursor-pointer items-center gap-3 rounded-lg border border-border bg-white px-3 py-2 text-sm text-ink hover:border-primary/50">
              <input type="checkbox" name="sucursales[]" :value="branch.id" :checked="includesId(data.oldInput.branchIds, branch.id)" class="size-4 rounded border-border-strong text-primary focus:ring-primary" />
              <span>{{ branch.name }}</span>
            </label>
          </div>
          <p class="mt-2 text-xs leading-5 text-ink-muted">El servidor asigna todas las sucursales cuando el rol es Administrador.</p>
        </fieldset>

        <label class="block sm:col-span-2">
          <span class="mb-1.5 block text-sm font-medium text-ink">Motivo del alta <span class="text-danger" aria-hidden="true">*</span></span>
          <input name="motivo" minlength="5" maxlength="255" required :value="data.oldInput.motivo" placeholder="Ej.: incorporación aprobada" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm placeholder:text-ink-subtle focus:border-primary focus:ring-2 focus:ring-primary/20" />
        </label>
        <div class="sm:col-span-2">
          <button type="submit" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground shadow-sm transition-colors hover:bg-primary-hover active:bg-primary-active">
            <PlusIcon class="size-5" aria-hidden="true" />
            Crear usuario
          </button>
        </div>
      </form>
    </section>

    <section aria-labelledby="users-list-title">
      <div class="mb-4 flex items-center justify-between gap-3">
        <div>
          <h2 id="users-list-title" class="text-lg font-bold text-ink">Usuarios de la empresa</h2>
          <p class="mt-1 text-sm text-ink-muted">Los cambios sensibles requieren un motivo y quedan auditados.</p>
        </div>
        <span class="rounded-full bg-surface-muted px-3 py-1 text-sm font-semibold text-ink-muted">{{ data.metrics.total }}</span>
      </div>

      <div class="space-y-4">
        <article v-for="user in data.users" :key="user.id" class="overflow-hidden rounded-xl border border-border bg-surface-raised shadow-card">
          <div class="flex flex-col gap-3 border-b border-border-subtle px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex min-w-0 items-center gap-3">
              <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-primary-subtle text-primary">
                <UserIcon class="size-5" aria-hidden="true" />
              </span>
              <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                  <h3 class="truncate font-semibold text-ink">{{ user.name }}</h3>
                  <span v-if="user.isSelf" class="rounded-full bg-info-subtle px-2 py-0.5 text-xs font-semibold text-info-strong">Tu cuenta</span>
                </div>
                <p class="truncate text-sm text-ink-muted">{{ user.email }}</p>
              </div>
            </div>
            <div class="flex flex-wrap items-center gap-2 sm:justify-end">
              <span v-for="role in user.roles" :key="role.id" class="rounded-full bg-primary-subtle px-2.5 py-1 text-xs font-semibold text-primary">{{ role.name }}</span>
              <StatusBadge :active="user.active" />
            </div>
          </div>

          <div class="grid gap-6 p-5 xl:grid-cols-2 xl:p-6">
            <section aria-label="Datos de la cuenta">
              <div class="mb-4 flex items-center gap-2">
                <IdentificationIcon class="size-5 text-primary" aria-hidden="true" />
                <h4 class="font-semibold text-ink">Datos de la cuenta</h4>
              </div>

              <form v-if="data.permissions.editAccounts" method="post" :action="user.actions.update" class="grid gap-4 sm:grid-cols-2">
                <CsrfField :csrf="data.csrf" />
                <label class="block">
                  <span class="mb-1.5 block text-sm font-medium text-ink">Nombre</span>
                  <input name="nombre" maxlength="255" required :value="user.name" autocomplete="name" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" />
                </label>
                <label class="block">
                  <span class="mb-1.5 block text-sm font-medium text-ink">Email</span>
                  <input type="email" name="email" maxlength="255" required :value="user.email" autocomplete="email" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" />
                </label>
                <label class="block sm:col-span-1">
                  <span class="mb-1.5 block text-sm font-medium text-ink">Estado</span>
                  <select name="activo" :value="user.active ? '1' : '0'" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20">
                    <option value="1">Activo</option>
                    <option v-if="user.canDeactivate" value="0">Inactivo</option>
                  </select>
                </label>
                <label class="block sm:col-span-1">
                  <span class="mb-1.5 block text-sm font-medium text-ink">Motivo</span>
                  <input name="motivo" minlength="5" maxlength="255" required class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" />
                </label>
                <div class="sm:col-span-2">
                  <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-lg border border-primary px-4 py-2.5 text-sm font-semibold text-primary transition-colors hover:bg-primary-subtle">Guardar cuenta</button>
                </div>
              </form>

              <dl v-else class="grid gap-4 rounded-xl bg-surface-subtle p-4 text-sm sm:grid-cols-2">
                <div><dt class="text-ink-subtle">Nombre</dt><dd class="mt-1 font-medium text-ink">{{ user.name }}</dd></div>
                <div><dt class="text-ink-subtle">Email</dt><dd class="mt-1 break-all font-medium text-ink">{{ user.email }}</dd></div>
              </dl>

              <div v-if="user.lastAccess" class="mt-4 flex items-center gap-2 text-xs text-ink-subtle">
                <ClockIcon class="size-4" aria-hidden="true" />
                Último acceso: {{ user.lastAccess }}
              </div>
            </section>

            <section aria-label="Acceso efectivo">
              <div class="mb-4 flex items-center gap-2">
                <ShieldCheckIcon class="size-5 text-primary" aria-hidden="true" />
                <h4 class="font-semibold text-ink">Acceso efectivo</h4>
              </div>

              <div v-if="user.isSelf || !data.permissions.editAccess" class="rounded-xl border border-info/20 bg-info-subtle p-4 text-sm text-info-strong">
                <p class="font-semibold">{{ user.isSelf ? 'Tu propio acceso está protegido' : 'Acceso de solo lectura' }}</p>
                <p class="mt-1 leading-5">{{ user.isSelf ? 'Tus roles y sucursales no se modifican desde esta pantalla.' : 'No tenés permiso para cambiar roles o sucursales.' }}</p>
                <div class="mt-3 flex flex-wrap gap-2">
                  <span v-for="role in user.roles" :key="`read-role-${role.id}`" class="rounded-full bg-white/70 px-2.5 py-1 text-xs font-semibold">{{ role.name }}</span>
                  <span v-for="branch in user.branches" :key="`read-branch-${branch.id}`" class="rounded-full bg-white/70 px-2.5 py-1 text-xs font-semibold">{{ branch.name }}</span>
                </div>
              </div>

              <form v-else method="post" :action="user.actions.assignAccess" data-confirm data-confirm-title="¿Guardar el acceso del usuario?" data-confirm-text="Se asignarán roles y sucursales auditando el motivo del cambio." data-confirm-button="Guardar acceso" class="grid gap-4 sm:grid-cols-2">
                <CsrfField :csrf="data.csrf" />
                <fieldset class="rounded-xl border border-border-subtle bg-surface-subtle p-4">
                  <legend class="px-1 text-sm font-semibold text-ink">Roles</legend>
                  <div class="mt-2 space-y-2">
                    <label v-for="role in data.roles" :key="role.id" class="flex min-h-10 cursor-pointer items-center gap-3 rounded-lg px-2 py-1 text-sm text-ink hover:bg-white">
                      <input type="checkbox" name="roles[]" :value="role.id" :checked="includesId(user.assignedRoleIds, role.id)" class="size-4 rounded border-border-strong text-primary focus:ring-primary" />
                      <span>{{ role.name }}</span>
                    </label>
                  </div>
                </fieldset>
                <fieldset class="rounded-xl border border-border-subtle bg-surface-subtle p-4">
                  <legend class="px-1 text-sm font-semibold text-ink">Sucursales</legend>
                  <div v-if="user.allCompanyBranches" class="mb-2 rounded-lg bg-info-subtle px-3 py-2 text-xs font-medium text-info-strong">Acceso automático a todas las sucursales activas.</div>
                  <div class="space-y-2">
                    <label v-for="branch in data.assignableBranches" :key="branch.id" class="flex min-h-10 cursor-pointer items-center gap-3 rounded-lg px-2 py-1 text-sm text-ink hover:bg-white">
                      <input type="checkbox" name="sucursales[]" :value="branch.id" :checked="includesId(user.assignedBranchIds, branch.id)" class="size-4 rounded border-border-strong text-primary focus:ring-primary" />
                      <span>{{ branch.name }}</span>
                    </label>
                  </div>
                </fieldset>
                <label class="block sm:col-span-2">
                  <span class="mb-1.5 block text-sm font-medium text-ink">Motivo de la asignación</span>
                  <input name="motivo" minlength="5" maxlength="255" required class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" />
                </label>
                <div class="rounded-xl border border-primary/20 bg-primary-subtle p-4 text-sm text-ink sm:col-span-2">
                  <p class="font-semibold">Resumen de acceso</p>
                  <p class="mt-1 text-ink-muted">Marcá los roles y sucursales de arriba. El servidor volverá a validar el alcance antes de guardar.</p>
                  <p class="mt-2 text-xs font-semibold text-primary">Actualmente: {{ user.roles.map((role) => role.name).join(', ') || 'sin roles' }} · {{ user.allCompanyBranches ? 'todas las sucursales activas' : `${user.branches.length} sucursal(es)` }}</p>
                </div>
                <div class="sm:col-span-2">
                  <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-lg border border-primary px-4 py-2.5 text-sm font-semibold text-primary transition-colors hover:bg-primary-subtle">Guardar acceso</button>
                </div>
              </form>
            </section>
          </div>

          <details v-if="data.permissions.resetPasswords" class="group border-t border-border-subtle bg-surface-subtle">
            <summary class="flex min-h-12 cursor-pointer list-none items-center gap-3 px-5 py-3 text-sm font-semibold text-ink hover:bg-surface-muted xl:px-6">
              <KeyIcon class="size-5 text-warning-strong" aria-hidden="true" />
              Restablecer contraseña
              <ChevronDownIcon class="ml-auto size-5 text-ink-subtle transition-transform group-open:rotate-180" aria-hidden="true" />
            </summary>
            <form method="post" :action="user.actions.resetPassword" data-confirm data-confirm-title="¿Restablecer la contraseña?" data-confirm-text="El usuario deberá usar la nueva contraseña en su próximo ingreso." data-confirm-button="Restablecer" data-confirm-danger="true" class="grid gap-4 border-t border-border-subtle bg-white px-5 py-5 sm:grid-cols-3 xl:px-6">
              <CsrfField :csrf="data.csrf" />
              <label class="block">
                <span class="mb-1.5 block text-sm font-medium text-ink">Nueva contraseña</span>
                <input type="password" name="password" minlength="8" maxlength="255" required autocomplete="new-password" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" />
              </label>
              <label class="block">
                <span class="mb-1.5 block text-sm font-medium text-ink">Repetir contraseña</span>
                <input type="password" name="password_confirmation" minlength="8" maxlength="255" required autocomplete="new-password" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" />
              </label>
              <label class="block">
                <span class="mb-1.5 block text-sm font-medium text-ink">Motivo</span>
                <input name="motivo" minlength="5" maxlength="255" required class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" />
              </label>
              <div class="sm:col-span-3">
                <button type="submit" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-warning-strong px-4 py-2.5 text-sm font-semibold text-warning-strong transition-colors hover:bg-warning-subtle">
                  <KeyIcon class="size-5" aria-hidden="true" />
                  Restablecer contraseña
                </button>
              </div>
            </form>
          </details>
        </article>

        <div v-if="!data.users.length" class="rounded-xl border border-dashed border-border-strong bg-surface-raised px-6 py-10 text-center">
          <UsersIcon class="mx-auto size-10 text-ink-subtle" aria-hidden="true" />
          <p class="mt-3 font-semibold text-ink">No hay usuarios para mostrar</p>
          <p class="mt-1 text-sm text-ink-muted">Creá la primera cuenta de esta empresa.</p>
        </div>
      </div>

      <PaginationBar :pagination="data.pagination" />
    </section>
  </div>
</template>
