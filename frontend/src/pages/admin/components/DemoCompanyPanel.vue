<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { XMarkIcon } from '@heroicons/vue/24/outline'
import CsrfField from './CsrfField.vue'

const props = defineProps({
  data: {
    type: Object,
    required: true,
  },
})

const open = ref(false)
const demoAction = computed(() => {
  const path = window.location.pathname.replace(/\/superadmin\/?$/, '')
  return `${path}/superadmin/demo`
})

const openFromSidebar = () => {
  open.value = true
}

onMounted(() => window.addEventListener('maintenance:open-demo-company', openFromSidebar))
onBeforeUnmount(() => window.removeEventListener('maintenance:open-demo-company', openFromSidebar))

const confirmRegenerate = (event) => {
  if (!window.confirm('Esto eliminará los datos actuales de la empresa demo y volverá a generarlos. ¿Continuar?')) {
    event.preventDefault()
  }
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/55 p-4" @click.self="open = false">
      <section class="w-full max-w-2xl overflow-hidden rounded-2xl border border-border bg-surface-raised shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="demo-company-title">
        <header class="flex items-start justify-between gap-4 border-b border-border-subtle bg-surface-subtle px-5 py-4 sm:px-6">
          <div>
            <p class="text-xs font-bold uppercase tracking-[0.14em] text-accent-active">Solo superadmin</p>
            <h2 id="demo-company-title" class="mt-1 text-xl font-bold text-ink">Empresa demo</h2>
            <p class="mt-1 text-sm text-ink-muted">Creá o restablecé una empresa poblada para presentar y probar el sistema.</p>
          </div>
          <button type="button" class="rounded-lg border border-border px-3 py-2 text-ink-muted hover:bg-surface-muted" aria-label="Cerrar" @click="open = false">
            <XMarkIcon class="size-5" aria-hidden="true" />
          </button>
        </header>

        <form method="post" :action="demoAction" class="grid gap-4 p-5 sm:grid-cols-2 sm:p-6">
          <CsrfField :csrf="data.csrf" />

          <label class="block sm:col-span-2">
            <span class="mb-1.5 block text-sm font-medium text-ink">Email de acceso demo</span>
            <input type="email" name="demo_email" maxlength="255" required value="demo@mantenimiento.local" autocomplete="email" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" />
          </label>

          <label class="block">
            <span class="mb-1.5 block text-sm font-medium text-ink">Contraseña</span>
            <input type="password" name="demo_password" minlength="8" maxlength="255" required autocomplete="new-password" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" />
          </label>

          <label class="block">
            <span class="mb-1.5 block text-sm font-medium text-ink">Repetir contraseña</span>
            <input type="password" name="demo_password_confirmation" minlength="8" maxlength="255" required autocomplete="new-password" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" />
          </label>

          <label class="block sm:col-span-2 sm:max-w-40">
            <span class="mb-1.5 block text-sm font-medium text-ink">Vigencia (días)</span>
            <input type="number" name="demo_dias" min="1" max="90" required value="15" class="min-h-11 w-full rounded-lg border border-border-strong bg-white px-3 py-2 text-sm text-ink shadow-sm focus:border-primary focus:ring-2 focus:ring-primary/20" />
          </label>

          <div class="sm:col-span-2 rounded-xl border border-info/20 bg-info-subtle p-4 text-sm text-info-strong">
            Se generan equipos, lecturas históricas, servicios, planes en distintos estados y órdenes de trabajo con fechas relativas al día de creación.
          </div>

          <div class="sm:col-span-2 flex flex-wrap justify-end gap-2 border-t border-border-subtle pt-4">
            <button type="button" class="inline-flex min-h-11 items-center rounded-lg border border-border px-4 py-2.5 text-sm font-semibold text-ink hover:bg-surface-muted" @click="open = false">Cancelar</button>
            <button type="submit" name="demo_accion" value="regenerar" class="inline-flex min-h-11 items-center rounded-lg border border-danger px-4 py-2.5 text-sm font-semibold text-danger-strong hover:bg-danger-subtle" @click="confirmRegenerate">Regenerar demo</button>
            <button type="submit" name="demo_accion" value="crear" class="inline-flex min-h-11 items-center rounded-lg bg-accent px-4 py-2.5 text-sm font-semibold text-accent-foreground hover:bg-accent-hover">Crear empresa demo</button>
          </div>
        </form>
      </section>
    </div>
  </Teleport>
</template>
