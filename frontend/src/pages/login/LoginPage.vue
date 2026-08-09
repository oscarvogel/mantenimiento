<script setup>
import { ref } from 'vue'
import {
  ChartBarSquareIcon,
  ClipboardDocumentCheckIcon,
  EyeIcon,
  EyeSlashIcon,
  ShieldCheckIcon,
  TruckIcon,
  WrenchScrewdriverIcon,
} from '@heroicons/vue/24/outline'

defineProps({ data: { type: Object, required: true } })

const passwordVisible = ref(false)
</script>

<template>
  <main
    class="relative min-h-dvh overflow-hidden bg-brand-950 bg-cover bg-center px-4 py-5 sm:px-6 sm:py-8 lg:grid lg:place-items-center lg:px-10"
    :style="{ backgroundImage: `url(${data.backgroundUrl})` }"
  >
    <div class="absolute inset-0 bg-brand-950/80 backdrop-blur-[2px]" aria-hidden="true"></div>

    <section
      aria-labelledby="login-title"
      class="relative mx-auto grid w-full max-w-[75rem] overflow-hidden rounded-[1.75rem] border border-white/20 bg-surface-raised shadow-2xl lg:min-h-[44rem] lg:grid-cols-[0.92fr_1.08fr]"
    >
      <div class="flex flex-col justify-center px-6 py-8 sm:px-10 sm:py-10 lg:px-14 lg:py-12">
        <div class="mb-8 flex items-center gap-3">
          <span class="flex size-11 items-center justify-center rounded-xl bg-accent text-accent-foreground shadow-sm">
            <WrenchScrewdriverIcon class="size-6" aria-hidden="true" />
          </span>
          <div>
            <p class="text-base font-bold leading-tight text-ink">Mantenimiento</p>
            <p class="mt-0.5 text-xs font-medium text-ink-muted">Gestión de flota</p>
          </div>
        </div>

        <div>
          <p class="text-sm font-semibold text-primary">Acceso al sistema</p>
          <h1 id="login-title" class="mt-1 text-4xl font-bold tracking-tight text-ink sm:text-5xl">Ingreso</h1>
          <p class="mt-3 text-sm leading-6 text-ink-muted sm:text-base">
            Ingresá tus credenciales para acceder a tu espacio de trabajo.
          </p>
        </div>

        <div
          v-if="data.alert?.message"
          :role="data.alert.type === 'success' ? 'status' : 'alert'"
          class="mt-6 rounded-xl border px-4 py-3 text-sm font-medium"
          :class="data.alert.type === 'success'
            ? 'border-success/25 bg-success-subtle text-success-strong'
            : data.alert.type === 'warning'
              ? 'border-warning/30 bg-warning-subtle text-warning-foreground'
              : 'border-danger/25 bg-danger-subtle text-danger-strong'"
        >
          {{ data.alert.message }}
        </div>

        <form method="post" :action="data.action" autocomplete="on" class="mt-7 space-y-5">
          <input type="hidden" :name="data.csrf.name" :value="data.csrf.hash" />

          <div>
            <label for="login-email" class="text-sm font-semibold text-ink">Email</label>
            <input
              id="login-email"
              name="email"
              type="email"
              autocomplete="username"
              required
              autofocus
              :value="data.email"
              :aria-invalid="Boolean(data.errors?.email)"
              :aria-describedby="data.errors?.email ? 'login-email-error' : undefined"
              class="mt-2 block min-h-12 w-full rounded-xl border border-border bg-brand-50 px-4 py-3 text-sm text-ink shadow-sm outline-none transition placeholder:text-ink-subtle focus:border-border-focus focus:bg-white focus:ring-4 focus:ring-primary/10"
              placeholder="nombre@empresa.com"
            />
            <p v-if="data.errors?.email" id="login-email-error" class="mt-2 text-sm font-medium text-danger-strong">
              {{ data.errors.email }}
            </p>
          </div>

          <div>
            <label for="login-password" class="text-sm font-semibold text-ink">Contraseña</label>
            <div class="relative mt-2">
              <input
                id="login-password"
                name="password"
                :type="passwordVisible ? 'text' : 'password'"
                autocomplete="current-password"
                minlength="4"
                required
                :aria-invalid="Boolean(data.errors?.password)"
                :aria-describedby="data.errors?.password ? 'login-password-error' : undefined"
                class="block min-h-12 w-full rounded-xl border border-border bg-brand-50 py-3 pl-4 pr-12 text-sm text-ink shadow-sm outline-none transition focus:border-border-focus focus:bg-white focus:ring-4 focus:ring-primary/10"
                placeholder="Ingresá tu contraseña"
              />
              <button
                type="button"
                class="absolute inset-y-0 right-1 flex min-h-11 min-w-11 items-center justify-center rounded-lg text-ink-muted transition hover:bg-brand-100 hover:text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                :aria-label="passwordVisible ? 'Ocultar contraseña' : 'Mostrar contraseña'"
                :aria-pressed="passwordVisible"
                @click="passwordVisible = !passwordVisible"
              >
                <EyeSlashIcon v-if="passwordVisible" class="size-5" aria-hidden="true" />
                <EyeIcon v-else class="size-5" aria-hidden="true" />
              </button>
            </div>
            <p v-if="data.errors?.password" id="login-password-error" class="mt-2 text-sm font-medium text-danger-strong">
              {{ data.errors.password }}
            </p>
          </div>

          <button
            type="submit"
            class="flex min-h-12 w-full items-center justify-center rounded-xl bg-primary px-5 py-3 text-sm font-bold text-primary-foreground shadow-lg shadow-primary/20 transition hover:bg-primary-hover focus:outline-none focus-visible:ring-4 focus-visible:ring-primary/25 active:bg-primary-active"
          >
            Iniciar sesión
          </button>
        </form>

        <div class="mt-8 border-t border-border-subtle pt-6">
          <div class="grid grid-cols-3 gap-2 sm:gap-3">
            <div class="rounded-xl bg-surface-muted p-3">
              <TruckIcon class="size-5 text-primary" aria-hidden="true" />
              <p class="mt-2 text-sm font-bold text-ink">Equipos</p>
              <p class="mt-1 hidden text-[0.68rem] font-semibold uppercase tracking-wide text-ink-muted sm:block">Estado y uso</p>
            </div>
            <div class="rounded-xl bg-surface-muted p-3">
              <ClipboardDocumentCheckIcon class="size-5 text-primary" aria-hidden="true" />
              <p class="mt-2 text-sm font-bold text-ink">Servicios</p>
              <p class="mt-1 hidden text-[0.68rem] font-semibold uppercase tracking-wide text-ink-muted sm:block">Planes y OT</p>
            </div>
            <div class="rounded-xl bg-surface-muted p-3">
              <ChartBarSquareIcon class="size-5 text-primary" aria-hidden="true" />
              <p class="mt-2 text-sm font-bold text-ink">Reportes</p>
              <p class="mt-1 hidden text-[0.68rem] font-semibold uppercase tracking-wide text-ink-muted sm:block">Decisiones claras</p>
            </div>
          </div>
        </div>
      </div>

      <div
        class="relative m-2 hidden min-h-[42.5rem] overflow-hidden rounded-[1.35rem] bg-brand-950 bg-cover bg-[68%_center] lg:flex lg:flex-col lg:justify-end"
        :style="{ backgroundImage: `url(${data.backgroundUrl})` }"
      >
        <div class="absolute inset-0 bg-gradient-to-t from-brand-950 via-brand-950/55 to-brand-950/10" aria-hidden="true"></div>
        <div class="relative p-10 xl:p-12">
          <span class="inline-flex rounded-full border border-white/25 bg-brand-950/60 px-3 py-1 text-[0.7rem] font-bold uppercase tracking-[0.18em] text-brand-100 backdrop-blur">
            Sistema de gestión
          </span>
          <h2 class="mt-5 max-w-[10ch] text-5xl font-bold leading-[0.98] tracking-tight text-white xl:text-6xl">
            Mantené tu flota en movimiento.
          </h2>
          <div class="mt-7 rounded-xl border border-white/25 bg-brand-950/75 p-5 text-base font-semibold leading-7 text-white backdrop-blur-sm">
            Equipos, lecturas, servicios y órdenes en una sola herramienta operativa para toda la empresa.
          </div>
          <div class="mt-5 flex flex-wrap gap-2">
            <span class="rounded-full border border-white/30 bg-brand-950/55 px-3 py-2 text-xs font-semibold text-white">Control preventivo</span>
            <span class="rounded-full border border-white/30 bg-brand-950/55 px-3 py-2 text-xs font-semibold text-white">Multiempresa</span>
            <span class="rounded-full border border-white/30 bg-brand-950/55 px-3 py-2 text-xs font-semibold text-white">Historial trazable</span>
          </div>
        </div>
      </div>
    </section>

    <p class="relative mx-auto mt-4 flex w-full max-w-[75rem] items-center justify-center gap-2 text-center text-xs font-medium text-brand-100 lg:absolute lg:bottom-4">
      <ShieldCheckIcon class="size-4" aria-hidden="true" />
      Acceso protegido y actividad trazable
    </p>
  </main>
</template>
