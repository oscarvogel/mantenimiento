<script setup>
import { computed } from 'vue'
import { ArrowRightIcon } from '@heroicons/vue/20/solid'
import {
  ClipboardDocumentListIcon,
  ClockIcon,
  ExclamationTriangleIcon,
  TruckIcon,
} from '@heroicons/vue/24/outline'
import CountUp from './CountUp.vue'

const props = defineProps({
  label: {
    type: String,
    required: true,
  },
  value: {
    type: [Number, String],
    required: true,
  },
  suffix: {
    type: String,
    default: '',
  },
  tone: {
    type: String,
    default: 'primary',
    validator: (value) => ['primary', 'due', 'overdue', 'orders'].includes(value),
  },
  href: {
    type: String,
    required: true,
  },
  linkLabel: {
    type: String,
    required: true,
  },
  // La cifra más urgente del resumen va en la tarjeta oscura, como en el inicio.
  emphasis: {
    type: Boolean,
    default: false,
  },
})

const toneStyles = {
  primary: {
    icon: 'bg-primary-subtle text-primary',
    value: 'text-ink',
  },
  due: {
    icon: 'bg-warning-subtle text-warning-strong',
    value: 'text-warning-strong',
  },
  overdue: {
    icon: 'bg-danger-subtle text-danger',
    value: 'text-danger-strong',
  },
  orders: {
    icon: 'bg-info-subtle text-info-strong',
    value: 'text-ink',
  },
}

const icons = {
  primary: TruckIcon,
  due: ClockIcon,
  overdue: ExclamationTriangleIcon,
  orders: ClipboardDocumentListIcon,
}

const isNumericValue = computed(() => {
  if (props.value === null || props.value === undefined || props.value === '') return false
  return Number.isFinite(Number(props.value))
})
</script>

<template>
  <article :class="['relative flex flex-col overflow-hidden rounded-card p-5 sm:p-6', emphasis ? 'bg-surface-inverse text-ink-inverse' : 'bg-surface-raised shadow-card', href !== '#' && 'ui-card-interactive']">
    <span v-if="emphasis" class="hero-dots pointer-events-none absolute -right-8 -top-2 h-56 w-64 opacity-[0.12]" aria-hidden="true"></span>
    <div class="flex items-start justify-between gap-4">
      <div>
        <p class="text-sm font-medium" :class="emphasis ? 'text-ink-inverse/80' : 'text-ink-muted'">{{ label }}</p>
        <p class="mt-2 text-4xl font-bold tracking-tight" :class="emphasis ? 'text-ink-inverse' : toneStyles[props.tone].value">
          <CountUp v-if="isNumericValue" :value="Number(value)" :suffix="suffix" />
          <template v-else>{{ value }}</template>
        </p>
      </div>
      <span v-if="!emphasis" class="flex size-11 items-center justify-center rounded-2xl" :class="toneStyles[props.tone].icon">
        <component :is="icons[props.tone]" class="size-6" aria-hidden="true" />
      </span>
    </div>
    <a v-if="href !== '#'" :href="href" class="relative mt-auto inline-flex items-center gap-1 self-end pt-5 text-sm font-semibold" :class="emphasis ? 'text-ink-inverse hover:underline' : 'text-primary hover:text-primary-hover'">
      {{ linkLabel }}
      <ArrowRightIcon class="size-4" aria-hidden="true" />
    </a>
    <span v-else class="mt-auto inline-flex self-end pt-5 text-sm font-medium" :class="emphasis ? 'text-ink-inverse/70' : 'text-ink-subtle'">{{ linkLabel }}</span>
  </article>
</template>
