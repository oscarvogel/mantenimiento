<script setup>
import * as format from '../../../ui/format.js'
import BaseDialog from '../../../ui/BaseDialog.vue'
import { ArrowDownTrayIcon, DocumentIcon, PhotoIcon } from '@heroicons/vue/24/outline'
import { secondaryButton } from '../helpers.js'

defineProps({
  order: { type: Object, required: true },
})

const emit = defineEmits(['close'])
</script>

<template>
  <BaseDialog eyebrow="Evidencia de orden de trabajo" :title="order.number" :description="`${order.evidenceCount} archivo${order.evidenceCount === 1 ? '' : 's'} asociado${order.evidenceCount === 1 ? '' : 's'} a esta OT.`" title-id="work-order-evidence-title" close-label="Cerrar evidencias" size="xl" data-testid="work-order-evidence-modal" @close="emit('close')">

    <div class="overflow-y-auto p-5 sm:p-6">
      <div class="grid gap-4 sm:grid-cols-2">
        <article v-for="evidence in order.evidence" :key="`${evidence.source || 'equipment_attachment'}-${evidence.id}`" class="overflow-hidden rounded-xl border border-border bg-white">
          <a
            v-if="evidence.isImage"
            :href="evidence.previewUrl"
            target="_blank"
            rel="noopener"
            class="block bg-surface-subtle"
            :aria-label="`Ver ${evidence.originalName}`"
          >
            <img :src="evidence.previewUrl" :alt="evidence.originalName" class="h-52 w-full object-contain" loading="lazy" />
          </a>
          <div v-else class="flex h-40 items-center justify-center bg-surface-subtle text-ink-muted">
            <DocumentIcon class="size-14" aria-hidden="true" />
          </div>

          <div class="p-4">
            <div class="flex items-start gap-3">
              <PhotoIcon v-if="evidence.isImage" class="mt-0.5 size-5 shrink-0 text-primary" aria-hidden="true" />
              <DocumentIcon v-else class="mt-0.5 size-5 shrink-0 text-primary" aria-hidden="true" />
              <div class="min-w-0 flex-1">
                <p class="break-words font-semibold text-ink">{{ evidence.originalName }}</p>
                <p class="mt-1 text-xs text-ink-muted">{{ format.formatDateTime(evidence.createdAt, 'Sin fecha') }} · {{ evidence.sizeKb }} KB</p>
                <p v-if="evidence.description" class="mt-2 text-sm text-ink-muted">{{ evidence.description }}</p>
              </div>
            </div>
            <div class="mt-4 flex flex-wrap gap-2">
              <a :href="evidence.previewUrl" target="_blank" rel="noopener" :class="secondaryButton">Ver</a>
              <a :href="evidence.downloadUrl" :class="secondaryButton"><ArrowDownTrayIcon class="mr-1.5 size-4" aria-hidden="true" />Descargar</a>
            </div>
          </div>
        </article>
      </div>
    </div>
  </BaseDialog>
</template>
