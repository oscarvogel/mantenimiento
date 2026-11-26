<script setup>
import * as uiLabels from '../../ui/labels.js'
import { formatNumber } from '../../ui/format.js'
import { moduleIconFor } from '../../ui/moduleIcons.js'
import { secondaryButton } from '../../ui/buttons.js'
import FleetAvailabilityPanel from './components/FleetAvailabilityPanel.vue'
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

// Vencidos y próximos son las dos tarjetas grandes; el resto va en la fila compacta.
const heroKeys = ['overdue', 'due-soon']
const heroIndicators = computed(() => heroKeys.map((key) => indicators.value.find((item) => item.key === key)).filter(Boolean))
const miniIndicators = computed(() => indicators.value.filter((item) => !heroKeys.includes(item.key)))
const plansUrl = computed(() => indicators.value.find((item) => item.key === 'compliance')?.href ?? null)

const indicatorTones = {
  danger: 'text-danger-strong',
  warning: 'text-warning-strong',
  success: 'text-success-strong',
  neutral: 'text-ink',
}
const heroDots = { danger: 'bg-accent-400', warning: 'bg-warning', success: 'bg-success', neutral: 'bg-ink-subtle' }
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
  <div ref="pageRoot" class="mx-auto max-w-[84rem] space-y-8 pb-8">
    <!-- El saludo está en el encabezado del shell; acá queda el título accesible de la página. -->
    <h1 id="command-center-heading" class="sr-only">Inicio</h1>

    <div v-if="data.globalAdminUrl" class="flex justify-end">
      <a :href="data.globalAdminUrl" :class="secondaryButton">
        <ShieldCheckIcon class="size-5" aria-hidden="true" />
        Administración global
      </a>
    </div>

    <div
      data-test="portal-grid"
      class="grid items-start gap-7"
      :class="{ 'xl:grid-cols-[minmax(0,1fr)_360px]': data.fleet }"
    >
    <div class="min-w-0 space-y-8">
    <section v-if="indicators.length" aria-labelledby="indicators-heading">
      <div class="mb-3.5 flex items-baseline justify-between gap-3">
        <h2 id="indicators-heading" class="text-lg font-semibold text-ink">Mantenimiento preventivo</h2>
        <a v-if="plansUrl" :href="plansUrl" class="text-sm text-ink-muted hover:text-ink">Ver planes</a>
      </div>

      <div v-if="heroIndicators.length" class="grid gap-4 sm:grid-cols-2">
        <a
          v-for="(indicator, index) in heroIndicators"
          :key="indicator.key"
          :href="indicator.href"
          data-test="hero-indicator"
          class="ui-interactive group relative flex min-h-[172px] flex-col overflow-hidden rounded-card p-6 shadow-card focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-border-focus focus-visible:ring-offset-2"
          :class="index === 0 ? 'bg-surface-inverse text-white' : 'bg-surface-raised text-ink'"
        >
          <!-- Trama de puntos decorativa. -->
          <span class="hero-dots pointer-events-none absolute -right-8 -top-2 h-56 w-64 opacity-[0.12]" aria-hidden="true"></span>
          <span class="relative text-sm font-medium" :class="index === 0 ? 'text-white/80' : 'text-ink-muted'">{{ indicator.label }}</span>
          <span
            v-if="indicatorValue(indicator) !== null"
            class="relative mt-2 text-5xl font-semibold tabular-nums tracking-tight"
            :class="index === 0 ? 'text-white' : (indicatorTones[indicator.tone] ?? 'text-ink')"
          >{{ indicatorValue(indicator) }}</span>
          <span v-else class="relative mt-2 text-base font-semibold" :class="index === 0 ? 'text-white/80' : 'text-ink-muted'">Sin datos</span>
          <span class="relative mt-auto flex items-center justify-end gap-1.5 pt-4 text-sm font-semibold" :class="index === 0 ? 'text-white' : 'text-primary'">
            <span class="mr-auto size-2 rounded-full" :class="heroDots[indicator.tone] ?? 'bg-ink-subtle'" aria-hidden="true"></span>
            Ver detalle
            <ArrowRightIcon class="size-4 transition-transform group-hover:translate-x-0.5" aria-hidden="true" />
          </span>
        </a>
      </div>

      <div v-if="miniIndicators.length" class="mt-3.5 grid gap-4 sm:grid-cols-2">
        <a
          v-for="indicator in miniIndicators"
          :key="indicator.key"
          :href="indicator.href"
          data-test="mini-indicator"
          class="ui-interactive flex items-center justify-between gap-3 rounded-[18px] bg-surface-raised px-5 py-3.5 text-sm text-ink-muted shadow-card hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-border-focus"
        >
          <span>{{ indicator.key === 'compliance' ? 'Planes preventivos al día' : indicator.label }}</span>
          <b v-if="indicatorValue(indicator) !== null" class="text-xl font-semibold tabular-nums text-ink">{{ indicatorValue(indicator) }}</b>
          <span v-else class="font-semibold">Sin datos</span>
        </a>
      </div>
    </section>

    <section v-if="shortcuts.length" aria-labelledby="shortcuts-heading">
      <h2 id="shortcuts-heading" class="sr-only">Tu trabajo de hoy</h2>
      <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <li v-for="(shortcut, index) in shortcuts" :key="shortcut.key">
          <a
            :href="shortcut.href"
            :title="shortcut.description"
            data-test="shortcut-pill"
            class="ui-interactive flex h-[58px] items-center gap-3 rounded-[18px] px-2.5 font-semibold shadow-card focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-border-focus"
            :class="index === 0 ? 'bg-primary-300 text-secondary-900' : 'bg-surface-raised text-ink hover:bg-surface-muted'"
          >
            <span
              class="flex size-[38px] shrink-0 items-center justify-center rounded-full"
              :class="index === 0 ? 'bg-white text-secondary-900' : 'bg-secondary-900 text-white dark:bg-neutral-100 dark:text-secondary-900'"
              aria-hidden="true"
            >
              <component :is="shortcutIcons[shortcut.key] ?? ClipboardDocumentCheckIcon" class="size-[18px]" />
            </span>
            {{ shortcut.label }}
          </a>
        </li>
      </ul>
    </section>

    <section v-if="isManagement" aria-labelledby="attention-heading">
      <h2 id="attention-heading" class="mb-3.5 text-lg font-semibold text-ink">Requiere atención hoy</h2>
      <template v-if="attention.length">
        <div class="mb-2 hidden grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)_minmax(0,0.9fr)_10rem] gap-3 px-5 text-xs text-ink-subtle sm:grid" aria-hidden="true">
          <span>Equipo</span><span>Motivo</span><span>Estado</span><span></span>
        </div>
        <ul class="space-y-2">
          <li v-for="(item, index) in attention" :key="`${item.title}-${index}`">
            <component
              :is="item.href ? 'a' : 'div'"
              :href="item.href ?? undefined"
              data-test="attention-row"
              class="grid min-h-[62px] grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 rounded-[18px] bg-surface-raised px-4 py-3 shadow-card sm:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)_minmax(0,0.9fr)_10rem] sm:px-5"
              :class="item.href ? 'ui-interactive group hover:bg-surface-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-border-focus' : ''"
            >
              <span class="min-w-0 font-semibold text-ink [overflow-wrap:anywhere]">{{ item.title }}</span>
              <span class="order-last col-span-2 min-w-0 text-sm text-ink-muted sm:order-none sm:col-span-1">{{ item.detail }}</span>
              <span
                data-test="status-pill"
                class="w-max rounded-pill px-3 py-1 text-xs font-semibold"
                :class="attentionTypes[item.status]?.tone ?? 'bg-surface-muted text-ink-muted'"
              >{{ uiLabels.humanizeCode(item.statusLabel) }}</span>
              <span v-if="item.href" class="hidden items-center justify-end gap-1.5 text-sm font-semibold text-ink group-hover:text-primary sm:inline-flex">
                {{ attentionTypes[item.status]?.action ?? 'Ver detalle' }}
                <ArrowRightIcon class="size-4 shrink-0" aria-hidden="true" />
              </span>
            </component>
          </li>
        </ul>
      </template>
      <p v-else class="flex items-center gap-2 rounded-[18px] bg-surface-raised px-5 py-4 text-sm text-ink-muted shadow-card">
        <CheckCircleIcon class="size-5 text-success-strong" aria-hidden="true" />
        No hay mantenimientos vencidos ni equipos sin lecturas al día.
      </p>
    </section>

    <section aria-labelledby="available-modules-heading">
      <div class="mb-3.5 flex flex-wrap items-baseline justify-between gap-3">
        <h2 id="available-modules-heading" class="text-lg font-semibold text-ink">Módulos</h2>
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
            class="ui-interactive group flex items-center gap-3 rounded-[18px] bg-surface-raised px-4 py-3.5 shadow-card hover:bg-surface-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-border-focus"
          >
            <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary-subtle text-primary" aria-hidden="true">
              <component :is="moduleIconFor(module.icon)" class="size-5" />
            </span>
            <span class="min-w-0 flex-1">
              <span class="block font-semibold text-ink">{{ module.label }}</span>
              <span class="block truncate text-xs text-ink-subtle">{{ module.description }}</span>
            </span>
            <ArrowRightIcon class="size-4 shrink-0 text-ink-subtle transition-transform group-hover:translate-x-0.5" aria-hidden="true" />
          </a>

          <div
            v-else
            role="group"
            aria-disabled="true"
            :aria-label="`${module.label}: ${uiLabels.humanizeCode(module.status)}`"
            data-module-card
            class="module-card--unavailable flex items-center gap-3 rounded-[18px] border border-dashed border-border px-4 py-3.5"
          >
            <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-surface-muted text-ink-muted" aria-hidden="true">
              <component :is="moduleIconFor(module.icon)" class="size-5" />
            </span>
            <span class="min-w-0 flex-1">
              <span class="block font-semibold text-ink">{{ module.label }}</span>
              <span
                class="module-status--pending mt-1 inline-block rounded-pill px-2 py-0.5 text-xs font-semibold"
                :class="module.state === 'restricted' ? 'bg-warning-subtle text-warning-foreground' : 'bg-surface-muted text-ink-muted'"
              >{{ uiLabels.humanizeCode(module.status) }}</span>
              <span v-if="module.state === 'restricted'" class="mt-1 block text-xs text-ink-muted">Consultá a tu administrador</span>
            </span>
          </div>
        </template>
      </div>

      <div v-else class="rounded-card bg-surface-raised p-6 text-ink-muted shadow-card">
        <h3 class="font-semibold text-ink">Sin módulos habilitados para tu cuenta</h3>
        <p class="mt-1 text-sm">Consultá al administrador de tu empresa para conocer tus accesos.</p>
      </div>
    </section>
    </div>

    <FleetAvailabilityPanel v-if="data.fleet" :fleet="data.fleet" />
    </div>
  </div>
</template>

<style scoped>
.hero-dots {
  background-image: radial-gradient(currentColor 2px, transparent 2.2px);
  background-size: 11px 11px;
  mask-image: radial-gradient(closest-side, #000 55%, transparent);
}
</style>
