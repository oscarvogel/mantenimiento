<script setup>
defineProps({
  csrf: {
    type: Object,
    required: true,
  },
})
</script>

<template>
  <input type="hidden" :name="csrf.name" :value="csrf.hash" />
</template>
