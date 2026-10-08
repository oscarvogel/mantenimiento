<script setup>
import { computed } from 'vue'
import { ArrowRightIcon, Squares2X2Icon, WrenchScrewdriverIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  data: {
    type: Object,
    required: true,
  },
})

const modules = computed(() => Array.isArray(props.data.modules) ? props.data.modules : [])
const isSuperAdmin = computed(() => Boolean(props.data.globalAdminUrl))
</script>

<template>
  <div class="mx-auto max-w-6xl space-y-8 pb-10">
    <section class="relative isolate overflow-hidden rounded-3xl bg-gradient-to-br from-brand-950 via-brand-900 to-brand-800 px-6 py-8 text-white shadow-card sm:px-9 sm:py-10 lg:px-12 lg:py-12">
      <div class="pointer-events-none absolute -right-10 -top-20 -z-10 size-72 rounded-full border border-white/10 sm:size-96" aria-hidden="true"></div>
      <div class="pointer-events-none absolute -bottom-36 right-28 -z-10 size-72 rounded-full bg-primary/20 blur-3xl" aria-hidden="true"></div>

      <p class="text-xs font-bold uppercase tracking-[0.2em] text-blue-200 sm:text-sm">Vogel Consultoría</p>
      <h1 class="mt-3 text-3xl font-bold tracking-tight sm:text-4xl lg:text-5xl">Centro de mandos</h1>
      <p class="mt-3 max-w-2xl text-base leading-7 text-blue-100 sm:text-lg">
        Un acceso claro a los módulos habilitados para tu trabajo.
      </p>
    </section>

    <section aria-labelledby="available-modules-heading" class="space-y-4">
      <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
          <p class="text-sm font-semibold text-primary">Plataforma de gestión operativa</p>
          <h2 id="available-modules-heading" class="mt-1 text-xl font-bold text-ink sm:text-2xl">Módulos disponibles</h2>
        </div>
        <p v-if="modules.length" class="text-sm text-ink-muted">
          {{ modules.length }} {{ modules.length === 1 ? 'módulo habilitado' : 'módulos habilitados' }} para tu cuenta
        </p>
      </div>

      <div v-if="modules.length" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <a
          v-for="module in modules"
          :key="module.key"
          :href="module.href"
          class="group rounded-2xl border border-border bg-surface-raised p-5 shadow-card transition duration-200 hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-border-focus focus-visible:ring-offset-2 focus-visible:ring-offset-surface-subtle sm:p-6"
        >
          <div class="flex items-start justify-between gap-4">
            <span class="flex size-12 items-center justify-center rounded-xl bg-primary-subtle text-primary" aria-hidden="true">
              <WrenchScrewdriverIcon class="size-6" />
            </span>
            <span class="inline-flex items-center gap-2 rounded-full bg-success-subtle px-3 py-1 text-xs font-semibold text-success-strong">
              <span class="size-1.5 rounded-full bg-success" aria-hidden="true"></span>
              {{ module.status }}
            </span>
          </div>

          <h3 class="mt-5 text-lg font-bold text-ink">{{ module.label }}</h3>
          <p class="mt-2 min-h-12 text-sm leading-6 text-ink-muted">{{ module.description }}</p>

          <span class="mt-5 inline-flex items-center gap-2 text-sm font-semibold text-primary group-hover:text-primary-hover">
            Abrir módulo
            <ArrowRightIcon class="size-4 transition-transform group-hover:translate-x-1" aria-hidden="true" />
          </span>
        </a>
      </div>

      <div v-else class="rounded-2xl border border-border bg-surface-raised px-5 py-7 shadow-card sm:px-7">
        <div class="flex max-w-2xl items-start gap-4">
          <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-surface-muted text-ink-muted" aria-hidden="true">
            <Squares2X2Icon class="size-5" />
          </span>
          <div>
            <h3 class="font-semibold text-ink">No hay módulos operativos disponibles</h3>
            <p class="mt-1 text-sm leading-6 text-ink-muted">
              {{ isSuperAdmin
                ? 'La administración global está disponible en su espacio correspondiente.'
                : 'Si necesitás acceso a Mantenimiento, consultá al administrador de tu empresa.' }}
            </p>
            <a
              v-if="isSuperAdmin"
              :href="data.globalAdminUrl"
              class="mt-4 inline-flex items-center gap-2 text-sm font-semibold text-primary hover:text-primary-hover"
            >
              Ir a Administración global
              <ArrowRightIcon class="size-4" aria-hidden="true" />
            </a>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>
