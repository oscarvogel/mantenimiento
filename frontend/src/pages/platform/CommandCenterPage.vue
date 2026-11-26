<script setup>
import * as uiLabels from '../../ui/labels.js'
import { formatNumber } from '../../ui/format.js'
import { moduleIconFor } from '../../ui/moduleIcons.js'
import { secondaryButton } from '../../ui/buttons.js'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import {
  ArrowPathRoundedSquareIcon,
  ArrowRightIcon,
  CheckCircleIcon,
  ClipboardDocumentCheckIcon,
  ExclamationTriangleIcon,
  ShieldCheckIcon,
} from '@heroicons/vue/24/outline'

const props = defineProps({
  data: {
    type: Object,
    required: true,
  },
})

const pageRoot = ref(null)
let cardMotionPreference

const list = (value) => Array.isArray(value) ? value : []
// Available modules first: the portal starts with what the person can open today.
const modules = computed(() => {
  const all = list(props.data.modules)
  return [...all.filter((module) => module.state === 'available'), ...all.filter((module) => module.state !== 'available')]
})
const indicators = computed(() => list(props.data.indicators))
const attention = computed(() => list(props.data.attention))
const shortcuts = computed(() => list(props.data.shortcuts))
const isManagement = computed(() => props.data.profile === 'management')
const availableCount = computed(() => modules.value.filter((module) => module.state === 'available').length)
const firstName = computed(() => {
  const name = typeof props.data.userName === 'string' ? props.data.userName.trim() : ''
  return name.split(/\s+/)[0] || 'Usuario'
})
const weekday = new Intl.DateTimeFormat('es-AR', { weekday: 'long', day: 'numeric', month: 'long' }).format(new Date())
const today = weekday.charAt(0).toUpperCase() + weekday.slice(1)
const subtitle = computed(() => {
  if (isManagement.value) return 'Esto es lo que pasa hoy en la flota.'
  if (shortcuts.value.length) return 'Tu trabajo del día, a un toque.'
  return 'Elegí el módulo con el que vas a trabajar.'
})

const indicatorTones = {
  danger: 'text-danger-strong',
  warning: 'text-warning-strong',
  success: 'text-success-strong',
  neutral: 'text-ink',
}
const indicatorValue = (indicator) => indicator.value === null || indicator.value === undefined
  ? null
  : `${formatNumber(indicator.value)}${indicator.unit ? ` ${indicator.unit}` : ''}`

const warningAttentionTone = 'bg-warning-subtle text-warning-foreground'
const readingAttention = { tone: warningAttentionTone, action: 'Ver ficha del equipo' }
const attentionTypes = {
  VENCIDO: { tone: 'bg-danger-subtle text-danger-strong', action: 'Ver planes vencidos' },
  PROXIMO: { tone: warningAttentionTone, action: 'Ver planes próximos' },
  DESACTUALIZADA: readingAttention,
  SIN_LECTURA: readingAttention,
}

const shortcutIcons = {
  'my-work': ClipboardDocumentCheckIcon,
  readings: ArrowPathRoundedSquareIcon,
  'report-failure': ExclamationTriangleIcon,
}

onMounted(async () => {
  const root = pageRoot.value
  const cards = [...(root?.querySelectorAll('[data-module-card]') ?? [])]
  if (cards.length === 0) return

  const { gsap } = await import('gsap')
  if (pageRoot.value !== root) return

  cardMotionPreference = gsap.matchMedia()
  cardMotionPreference.add('(prefers-reduced-motion: no-preference)', () => {
    gsap.from(cards, {
      y: 22,
      autoAlpha: 0,
      duration: 0.55,
      stagger: 0.075,
      ease: 'power2.out',
      clearProps: 'transform,opacity,visibility',
    })
  })
})

onBeforeUnmount(() => cardMotionPreference?.revert())
</script>

<template>
  <div ref="pageRoot" class="mx-auto max-w-[84rem] space-y-7 pb-8 sm:space-y-9">
    <header class="flex flex-wrap items-end justify-between gap-x-8 gap-y-3 border-b border-border-subtle pb-5 sm:pb-6">
      <div>
        <h1 id="command-center-heading" class="text-3xl font-bold tracking-tight text-ink sm:text-4xl">Hola, {{ firstName }}</h1>
        <p class="mt-1.5 text-sm text-ink-muted sm:text-base">{{ subtitle }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
        <p class="text-xs font-medium text-ink-muted sm:text-sm">{{ today }}</p>
        <a v-if="data.globalAdminUrl" :href="data.globalAdminUrl" :class="secondaryButton">
          <ShieldCheckIcon class="size-5" aria-hidden="true" />
          Administración global
        </a>
      </div>
    </header>

    <div
      v-if="isManagement || indicators.length"
      class="grid items-start gap-6 lg:gap-7"
      :class="{ 'xl:grid-cols-[minmax(0,1.18fr)_minmax(0,1fr)]': isManagement && indicators.length }"
    >
      <section v-if="isManagement" aria-labelledby="attention-heading" class="min-w-0 rounded-2xl border border-border-subtle bg-surface-raised p-4 sm:p-6">
        <h2 id="attention-heading" class="text-lg font-bold text-ink">Requiere atención hoy</h2>
        <ul v-if="attention.length" class="mt-3 divide-y divide-border-subtle">
          <li v-for="(item, index) in attention" :key="`${item.title}-${index}`">
            <component
              :is="item.href ? 'a' : 'div'"
              :href="item.href ?? undefined"
              class="flex flex-col items-start gap-2.5 py-3 sm:flex-row sm:flex-wrap sm:items-center sm:gap-x-4"
              :class="item.href ? 'ui-interactive group -mx-2 rounded-lg px-2 hover:bg-surface-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-border-focus' : ''"
            >
              <span class="flex min-w-0 flex-1 flex-col items-start gap-2 sm:basis-60">
                <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold" :class="attentionTypes[item.status]?.tone ?? 'bg-surface-muted text-ink-muted'">
                  {{ uiLabels.humanizeCode(item.statusLabel) }}
                </span>
                <span class="min-w-0 [overflow-wrap:anywhere]">
                  <span class="block font-semibold text-ink">{{ item.title }}</span>
                  <span class="block text-sm text-ink-muted">{{ item.detail }}</span>
                </span>
              </span>
              <span v-if="item.href" class="inline-flex shrink-0 items-center gap-2 text-sm font-semibold text-primary group-hover:text-primary-hover">
                <span class="underline underline-offset-4">{{ attentionTypes[item.status]?.action ?? 'Ver detalle' }}</span>
                <ArrowRightIcon class="size-4 shrink-0" aria-hidden="true" />
              </span>
            </component>
          </li>
        </ul>
        <p v-else class="mt-3 flex items-center gap-2 text-sm text-ink-muted">
          <CheckCircleIcon class="size-5 text-success-strong" aria-hidden="true" />
          No hay mantenimientos vencidos ni equipos sin lecturas al día.
        </p>
      </section>

      <section v-if="indicators.length" aria-labelledby="indicators-heading" class="min-w-0">
        <h2 id="indicators-heading" class="mb-3 text-lg font-bold text-ink">Indicadores de hoy</h2>
        <ul class="grid grid-cols-2 gap-2.5 sm:gap-3" :class="{ 'md:grid-cols-4 xl:grid-cols-2': isManagement }">
          <li v-for="indicator in indicators" :key="indicator.key">
            <a
              :href="indicator.href"
              data-indicator
              class="ui-interactive group flex h-full flex-col rounded-xl border border-border-subtle bg-surface-raised p-3 hover:border-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-border-focus sm:p-4"
            >
              <span class="min-h-9 text-xs font-medium text-ink-muted sm:min-h-0 sm:text-sm">{{ indicator.key === 'compliance' ? 'Planes preventivos al día' : indicator.label }}</span>
              <span v-if="indicatorValue(indicator) !== null" class="mt-1 text-2xl font-bold tabular-nums sm:mt-2 sm:text-3xl" :class="indicatorTones[indicator.tone] ?? 'text-ink'">
                {{ indicatorValue(indicator) }}
              </span>
              <span v-else class="mt-1 text-sm font-semibold text-ink-muted sm:mt-2 sm:text-base">Sin datos suficientes</span>
              <span class="mt-auto inline-flex items-center gap-1 pt-2 text-xs font-semibold text-primary group-hover:text-primary-hover sm:pt-3 sm:text-sm">
                Ver detalle
                <ArrowRightIcon class="size-4 shrink-0 transition-transform group-hover:translate-x-0.5" aria-hidden="true" />
              </span>
            </a>
          </li>
        </ul>
      </section>
    </div>

    <section v-if="shortcuts.length" aria-labelledby="shortcuts-heading">
      <h2 id="shortcuts-heading" class="mb-3 text-lg font-bold text-ink">Tu trabajo de hoy</h2>
      <ul class="grid gap-3 sm:grid-cols-3">
        <li v-for="shortcut in shortcuts" :key="shortcut.key">
          <a
            :href="shortcut.href"
            class="ui-interactive group flex h-full min-h-24 items-start gap-4 rounded-xl border border-border-subtle bg-surface-raised p-4 hover:border-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-border-focus"
          >
            <span class="flex size-11 shrink-0 items-center justify-center rounded-lg bg-primary-subtle text-primary" aria-hidden="true">
              <component :is="shortcutIcons[shortcut.key] ?? ClipboardDocumentCheckIcon" class="size-6" />
            </span>
            <span>
              <span class="block font-bold text-ink group-hover:text-primary">{{ shortcut.label }}</span>
              <span class="mt-1 block text-sm text-ink-muted">{{ shortcut.description }}</span>
            </span>
          </a>
        </li>
      </ul>
    </section>

    <section aria-labelledby="available-modules-heading">
      <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
        <h2 id="available-modules-heading" class="text-lg font-bold text-ink">Módulos</h2>
        <p v-if="modules.length" class="text-sm text-ink-muted">
          {{ availableCount }} {{ availableCount === 1 ? 'disponible' : 'disponibles' }} de {{ modules.length }}
        </p>
      </div>

      <div v-if="modules.length" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        <template v-for="module in modules" :key="module.key">
          <a
            v-if="module.state === 'available' && module.href"
            :href="module.href"
            data-module-card
            class="ui-interactive group flex h-full items-start gap-3.5 rounded-xl border border-border-subtle bg-surface-raised p-4 hover:border-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-border-focus sm:p-5"
          >
            <span class="flex size-11 shrink-0 items-center justify-center rounded-lg bg-primary-subtle text-primary" aria-hidden="true">
              <component :is="moduleIconFor(module.icon)" class="size-6" />
            </span>
            <div class="flex min-w-0 flex-1 flex-col self-stretch">
              <h3 class="font-bold text-ink">{{ module.label }}</h3>
              <p class="mb-2 mt-1 text-sm text-ink-muted">{{ module.description }}</p>
              <span class="mt-auto inline-flex items-center gap-1.5 text-sm font-semibold text-primary group-hover:text-primary-hover">
                Ingresar
                <ArrowRightIcon class="size-4 transition-transform group-hover:translate-x-1" aria-hidden="true" />
              </span>
            </div>
          </a>

          <div
            v-else
            role="group"
            aria-disabled="true"
            :aria-label="`${module.label}: ${uiLabels.humanizeCode(module.status)}`"
            data-module-card
            class="module-card--unavailable flex h-full items-start gap-3.5 rounded-xl border border-dashed border-border bg-surface-subtle p-4 sm:p-5"
          >
            <span class="flex size-11 shrink-0 items-center justify-center rounded-lg bg-surface-muted text-ink-muted" aria-hidden="true">
              <component :is="moduleIconFor(module.icon)" class="size-6" />
            </span>
            <div class="min-w-0">
              <h3 class="font-bold text-ink">{{ module.label }}</h3>
              <p class="mt-1 text-sm text-ink-muted">{{ module.description }}</p>
              <span
                class="module-status--pending mt-2 inline-block rounded-md px-2 py-0.5 text-xs font-semibold"
                :class="module.state === 'restricted' ? 'bg-warning-subtle text-warning-foreground' : 'bg-surface-muted text-ink-muted'"
              >
                {{ uiLabels.humanizeCode(module.status) }}
              </span>
              <p v-if="module.state === 'restricted'" class="mt-2 text-sm text-ink-muted">Consultá a tu administrador</p>
            </div>
          </div>
        </template>
      </div>

      <div v-else class="rounded-2xl border border-border bg-surface-raised p-6 text-ink-muted">
        <h3 class="font-semibold text-ink">Sin módulos habilitados para tu cuenta</h3>
        <p class="mt-1 text-sm">Consultá al administrador de tu empresa para conocer tus accesos.</p>
      </div>
    </section>
  </div>
</template>
