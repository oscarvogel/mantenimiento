<script setup>
import { ArrowRightIcon } from '@heroicons/vue/20/solid'
import { ClockIcon, ExclamationTriangleIcon, TruckIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  label: {
    type: String,
    required: true,
  },
  value: {
    type: Number,
    required: true,
  },
  tone: {
    type: String,
    default: 'primary',
    validator: (value) => ['primary', 'due', 'overdue'].includes(value),
  },
  href: {
    type: String,
    required: true,
  },
  linkLabel: {
    type: String,
    required: true,
  },
})

const toneStyles = {
  primary: {
    icon: 'bg-primary-subtle text-primary',
    value: 'text-ink',
    accent: 'bg-primary',
  },
  due: {
    icon: 'bg-warning-subtle text-warning-strong',
    value: 'text-warning-strong',
    accent: 'bg-maintenance-due',
  },
  overdue: {
    icon: 'bg-danger-subtle text-danger',
    value: 'text-danger-strong',
    accent: 'bg-maintenance-overdue',
  },
}

const icons = {
  primary: TruckIcon,
  due: ClockIcon,
  overdue: ExclamationTriangleIcon,
}
</script>

<template>
  <article :class="['relative overflow-hidden rounded-xl border border-border bg-surface-raised p-5 sm:p-6', href !== '#' && 'ui-card-interactive']">
    <div class="flex items-start justify-between gap-4">
      <div>
        <p class="text-sm font-medium text-ink-muted">{{ label }}</p>
        <p class="mt-2 text-3xl font-bold tracking-tight" :class="toneStyles[props.tone].value">{{ value }}</p>
      </div>
      <span class="flex size-11 items-center justify-center rounded-lg" :class="toneStyles[props.tone].icon">
        <component :is="icons[props.tone]" class="size-6" aria-hidden="true" />
      </span>
    </div>
    <a v-if="href !== '#'" :href="href" class="mt-5 inline-flex items-center gap-1 text-sm font-semibold text-primary hover:text-primary-hover">
      {{ linkLabel }}
      <ArrowRightIcon class="size-4" aria-hidden="true" />
    </a>
    <span v-else class="mt-5 inline-flex text-sm font-medium text-ink-subtle">{{ linkLabel }}</span>
  </article>
</template>
