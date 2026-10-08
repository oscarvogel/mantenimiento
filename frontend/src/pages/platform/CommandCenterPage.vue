<script setup>
import { computed } from 'vue'
import {
  ArrowRightIcon,
  BeakerIcon,
  ChartBarSquareIcon,
  CircleStackIcon,
  Cog6ToothIcon,
  DocumentTextIcon,
  SparklesIcon,
  TruckIcon,
  WrenchScrewdriverIcon,
} from '@heroicons/vue/24/outline'
import heroTruck from '../../assets/platform/portal-hero.jpg'
import maintenanceWorkshop from '../../assets/platform/maintenance-card.jpg'
import tripsBackground from '../../assets/platform/trips-card.jpg'
import fuelBackground from '../../assets/platform/fuel-card.jpg'
import tiresBackground from '../../assets/platform/tires-card.jpg'
import billingBackground from '../../assets/platform/billing-card.jpg'
import managementBackground from '../../assets/platform/management-card.jpg'
import reportsBackground from '../../assets/platform/reports-card.jpg'
import automationsBackground from '../../assets/platform/automations-card.jpg'
import aiBackground from '../../assets/platform/ai-card.jpg'

const props = defineProps({
  data: {
    type: Object,
    required: true,
  },
})

const modules = computed(() => Array.isArray(props.data.modules) ? props.data.modules : [])
const availableCount = computed(() => modules.value.filter((module) => module.state === 'available').length)
const pendingCount = computed(() => modules.value.filter((module) => module.state === 'not_implemented').length)
const restrictedCount = computed(() => modules.value.filter((module) => module.state === 'restricted').length)
const isSuperAdmin = computed(() => Boolean(props.data.globalAdminUrl))
const firstName = computed(() => {
  const name = typeof props.data.userName === 'string' ? props.data.userName.trim() : ''
  return name.split(/\s+/)[0] || 'Usuario'
})

const moduleIcons = {
  wrench: WrenchScrewdriverIcon,
  truck: TruckIcon,
  fuel: BeakerIcon,
  tires: CircleStackIcon,
  billing: DocumentTextIcon,
  management: ChartBarSquareIcon,
  reports: ChartBarSquareIcon,
  automations: Cog6ToothIcon,
  ai: SparklesIcon,
}

const moduleBackgrounds = {
  maintenance: maintenanceWorkshop,
  trips: tripsBackground,
  fuel: fuelBackground,
  tires: tiresBackground,
  billing: billingBackground,
  management: managementBackground,
  reports: reportsBackground,
  automations: automationsBackground,
  ai: aiBackground,
}

const iconFor = (name) => moduleIcons[name] ?? WrenchScrewdriverIcon
const cardStyle = (module) => {
  const background = moduleBackgrounds[module.key]
  return background
    ? { backgroundImage: `linear-gradient(90deg, rgb(3 18 42 / .96) 0%, rgb(3 18 42 / .82) 55%, rgb(3 18 42 / .34) 100%), url("${background}")` }
    : undefined
}

const heroStyle = {
  backgroundImage: `linear-gradient(90deg, rgb(2 18 44 / .97) 0%, rgb(2 18 44 / .86) 43%, rgb(2 18 44 / .12) 100%), url("${heroTruck}")`,
}
</script>

<template>
  <div class="mx-auto max-w-[86rem] space-y-7 pb-10 sm:space-y-9">
    <section
      class="relative isolate flex min-h-[19rem] items-center overflow-hidden rounded-3xl bg-brand-950 bg-cover bg-center px-6 py-9 text-white shadow-card sm:min-h-[21rem] sm:px-10 lg:px-12"
      :style="heroStyle"
      aria-labelledby="command-center-heading"
    >
      <div class="max-w-3xl">
        <p class="text-xs font-bold uppercase tracking-[0.19em] text-blue-200 sm:text-sm">Plataforma de Gestión Operativa</p>
        <h1 id="command-center-heading" class="mt-3 text-3xl font-bold tracking-tight sm:text-4xl lg:text-5xl">
          Bienvenido, {{ firstName }}
        </h1>
        <p class="mt-2 text-xl font-medium text-blue-100 sm:text-2xl lg:text-3xl">Vogel Consultoría</p>
        <p class="mt-3 max-w-2xl text-sm leading-6 text-blue-100 sm:text-base sm:leading-7">
          Información, control y decisiones para una operación más eficiente.
        </p>
      </div>
    </section>

    <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_19rem]">
      <section aria-labelledby="available-modules-heading" class="min-w-0">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
          <div>
            <p class="text-sm font-semibold text-primary">Gestioná tu operación desde un solo lugar</p>
            <h2 id="available-modules-heading" class="mt-1 text-2xl font-bold text-ink sm:text-3xl">Módulos de la plataforma</h2>
          </div>
          <p class="text-sm text-ink-muted">
            {{ modules.length }} módulos<span v-if="availableCount"> · {{ availableCount }} operativo</span>
          </p>
        </div>

        <div v-if="modules.length" class="grid gap-3 sm:grid-cols-2 2xl:grid-cols-3">
          <template v-for="module in modules" :key="module.key">
            <a
              v-if="module.state === 'available' && module.href"
              :href="module.href"
              :style="cardStyle(module)"
              data-module-card
              class="group relative isolate flex min-h-[13.5rem] flex-col overflow-hidden rounded-2xl border border-brand-700 bg-brand-950 bg-cover bg-center p-4 text-white shadow-card transition duration-200 hover:-translate-y-0.5 hover:border-primary hover:shadow-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-border-focus focus-visible:ring-offset-2 focus-visible:ring-offset-surface-subtle sm:p-5"
            >
              <div class="flex items-start justify-between gap-3">
                <span class="flex size-12 items-center justify-center rounded-xl bg-primary text-white shadow-lg" aria-hidden="true">
                  <component :is="iconFor(module.icon)" class="size-6" />
                </span>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/20 px-2.5 py-1 text-xs font-semibold text-emerald-200">
                  <span class="size-1.5 rounded-full bg-emerald-400" aria-hidden="true"></span>
                  {{ module.status }}
                </span>
              </div>
              <div class="mt-auto pt-6">
                <h3 class="text-lg font-bold">{{ module.label }}</h3>
                <p class="mt-1.5 min-h-10 max-w-[24rem] text-sm leading-5 text-blue-100">{{ module.description }}</p>
                <span class="mt-3 inline-flex items-center gap-2 text-sm font-semibold text-sky-300 group-hover:text-white">
                  Ingresar al módulo
                  <ArrowRightIcon class="size-4 transition-transform group-hover:translate-x-1" aria-hidden="true" />
                </span>
              </div>
            </a>

            <button
              v-else
              type="button"
              disabled
              aria-disabled="true"
              :aria-label="`${module.label}: ${module.status}`"
              :style="cardStyle(module)"
              data-module-card
              class="relative isolate flex min-h-[13.5rem] cursor-not-allowed flex-col overflow-hidden rounded-2xl border border-brand-700 bg-brand-950 bg-cover bg-center p-4 text-left text-white shadow-card opacity-85 sm:p-5"
              :class="module.key === 'maintenance' ? 'opacity-85' : ''"
            >
              <div class="flex items-start justify-between gap-3">
                <span class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-primary-subtle text-primary" :class="module.state === 'not_implemented' ? 'bg-primary/20 text-sky-300' : ''" aria-hidden="true">
                  <component :is="iconFor(module.icon)" class="size-6" />
                </span>
                <span
                  class="module-status--pending max-w-[9.5rem] rounded-full bg-surface-muted px-2.5 py-1 text-center text-[0.65rem] font-semibold leading-4 text-ink-muted"
                  :class="module.state === 'not_implemented' ? 'bg-white/10 text-blue-100' : (module.state === 'restricted' ? 'bg-warning-subtle text-warning-strong' : '')"
                >
                  {{ module.status }}
                </span>
              </div>
              <div class="mt-auto pt-6">
                <h3 class="text-lg font-bold">{{ module.label }}</h3>
                <p class="mt-1.5 min-h-10 text-sm leading-5 text-blue-100">{{ module.description }}</p>
                <span class="mt-3 inline-flex items-center gap-2 text-sm font-semibold text-ink-muted">
                  {{ module.state === 'restricted' ? 'Consultá a tu administrador' : 'Próximamente' }}
                </span>
              </div>
            </button>
          </template>
        </div>

        <div v-else class="rounded-2xl border border-border bg-surface-raised p-6 text-ink-muted">
          <h3 class="font-semibold text-ink">Sin módulos habilitados para tu cuenta</h3>
          <p class="mt-1 text-sm">Consultá al administrador de tu empresa para conocer tus accesos.</p>
        </div>
      </section>

      <aside class="rounded-2xl border border-border bg-surface-raised p-5 shadow-card sm:p-6" aria-labelledby="platform-status-heading">
        <div class="flex items-center gap-3">
          <span class="flex size-10 items-center justify-center rounded-xl bg-primary-subtle text-primary" aria-hidden="true">
            <ChartBarSquareIcon class="size-5" />
          </span>
          <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-primary">Vogel Consultoría</p>
            <h2 id="platform-status-heading" class="font-bold text-ink">Estado de la plataforma</h2>
          </div>
        </div>

        <p class="mt-4 text-sm leading-6 text-ink-muted">
          Los módulos están visibles para que conozcas la propuesta. Cada acceso se habilita cuando su implementación esté disponible para tu cuenta.
        </p>

        <dl class="mt-5 space-y-3 border-t border-border-subtle pt-4 text-sm">
          <div class="flex items-center justify-between gap-3">
            <dt class="text-ink-muted">Operativos</dt>
            <dd class="font-bold text-success-strong">{{ availableCount }}</dd>
          </div>
          <div class="flex items-center justify-between gap-3">
            <dt class="text-ink-muted">Sin implementación aún</dt>
            <dd class="font-bold text-ink">{{ pendingCount }}</dd>
          </div>
          <div v-if="restrictedCount" class="flex items-center justify-between gap-3">
            <dt class="text-ink-muted">No habilitados para tu cuenta</dt>
            <dd class="font-bold text-warning-strong">{{ restrictedCount }}</dd>
          </div>
        </dl>

        <a
          v-if="isSuperAdmin"
          :href="data.globalAdminUrl"
          class="mt-5 inline-flex items-center gap-2 text-sm font-semibold text-primary hover:text-primary-hover"
        >
          Administración global
          <ArrowRightIcon class="size-4" aria-hidden="true" />
        </a>
      </aside>
    </div>
  </div>
</template>
