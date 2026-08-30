<script setup>
import { ArrowDownTrayIcon, DocumentIcon, PhotoIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import { secondaryButton } from '../helpers.js'

defineProps({
  order: { type: Object, required: true },
})

const emit = defineEmits(['close'])
</script>

<template>
  <Teleport to="body">
    <div
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/55 p-3 backdrop-blur-[1px] sm:p-5"
      data-testid="work-order-evidence-modal"
      @click.self="emit('close')"
      @keydown.esc="emit('close')"
    >
      <section
        class="flex max-h-[calc(100vh-1.5rem)] w-full max-w-4xl flex-col overflow-hidden rounded-2xl border border-border bg-surface-raised shadow-2xl sm:max-h-[calc(100vh-2.5rem)]"
        role="dialog"
        aria-modal="true"
        aria-labelledby="work-order-evidence-title"
      >
        <header class="flex items-start justify-between gap-4 border-b border-border-subtle px-5 py-4 sm:px-6">
          <div>
            <p class="text-xs font-bold uppercase tracking-[0.14em] text-primary">Evidencia de orden de trabajo</p>
            <h2 id="work-order-evidence-title" class="mt-1 text-xl font-bold text-ink">{{ order.number }}</h2>
            <p class="mt-1 text-sm text-ink-muted">{{ order.evidenceCount }} archivo{{ order.evidenceCount === 1 ? '' : 's' }} asociado{{ order.evidenceCount === 1 ? '' : 's' }} a esta OT.</p>
          </div>
          <button type="button" :class="secondaryButton" aria-label="Cerrar evidencias" @click="emit('close')">
            <XMarkIcon class="size-5" aria-hidden="true" />
          </button>
        </header>

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
                    <p class="mt-1 text-xs text-ink-muted">{{ evidence.createdAt || 'Sin fecha' }} · {{ evidence.sizeKb }} KB</p>
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
      </section>
    </div>
  </Teleport>
</template>
