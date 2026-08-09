<script setup>
const props = defineProps({
  status: {
    type: String,
    required: true,
  },
  label: {
    type: String,
    required: true,
  },
})

const styles = {
  ok: 'bg-success-subtle text-success-strong ring-success/20',
  due: 'bg-warning-subtle text-warning-foreground ring-warning/25',
  overdue: 'bg-danger-subtle text-danger-strong ring-danger/20',
  scheduled: 'bg-info-subtle text-info-strong ring-info/20',
  inactive: 'bg-surface-muted text-maintenance-inactive ring-border',
}
</script>

<template>
  <span
    class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset"
    :class="styles[props.status] ?? styles.inactive"
  >
    <span
      class="size-1.5 rounded-full"
      :class="{
        'bg-maintenance-ok': props.status === 'ok',
        'bg-maintenance-due': props.status === 'due',
        'bg-maintenance-overdue': props.status === 'overdue',
        'bg-maintenance-scheduled': props.status === 'scheduled',
        'bg-maintenance-inactive': !['ok', 'due', 'overdue', 'scheduled'].includes(props.status),
      }"
      aria-hidden="true"
    ></span>
    {{ label }}
  </span>
</template>
