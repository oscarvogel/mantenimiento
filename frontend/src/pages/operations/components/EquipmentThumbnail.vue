<script setup>
import { computed } from 'vue'
import { CameraIcon } from '@heroicons/vue/24/outline'

const props = defineProps({
  url: { type: String, default: null }, code: { type: String, default: 'EQ' },
  alt: { type: String, default: '' }, size: { type: String, default: 'md' },
})
const sizeClass = computed(() => ({ sm: 'size-10', md: 'size-12', lg: 'size-14', hero: 'aspect-[4/3] w-full' }[props.size] ?? 'size-12'))
</script>

<template>
  <img v-if="url" :src="url" :alt="alt || `Foto de ${code}`" :class="[sizeClass, 'shrink-0 rounded-lg border border-border bg-surface-muted object-cover']" loading="lazy" />
  <div v-else :class="[sizeClass, 'grid shrink-0 place-items-center rounded-lg border border-border bg-surface-muted text-ink-subtle']" role="img" :aria-label="`Sin foto para ${code}`"><CameraIcon class="size-1/2" aria-hidden="true" /></div>
</template>
