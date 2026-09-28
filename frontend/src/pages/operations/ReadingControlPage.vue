<script setup>
import { computed, ref } from 'vue'
import {
  ArrowPathIcon,
  ArrowTopRightOnSquareIcon,
  ChatBubbleLeftEllipsisIcon,
  ClockIcon,
  ExclamationTriangleIcon,
  MagnifyingGlassIcon,
} from '@heroicons/vue/24/outline'
import EmptyState from './components/EmptyState.vue'
import FormField from './components/FormField.vue'
import PageHeading from './components/PageHeading.vue'
import PaginationBar from './components/PaginationBar.vue'
import PanelCard from './components/PanelCard.vue'
import { fieldClass, formatNumberEs, primaryButton, secondaryButton } from './helpers.js'

const props = defineProps({ data: { type: Object, required: true } })

const data = computed(() => ({
  ...props.data,
  results: props.data.results ?? [],
  catalogs: props.data.catalogs ?? {},
  routes: props.data.routes ?? {},
  summary: props.data.summary ?? { total: 0, sinLectura: 0, antiguos: 0, alDia: 0 },
  pagination: props.data.pagination ?? { page: 1, perPage: 25, total: 0, totalPages: 1 },
  filters: props.data.filters ?? { q: '', branchId: '', typeId: '', filter: 'all', sort: 'age_asc' },
  claim: props.data.claim ?? { enabled: false, reason: null },
}))

// --- Reclamo por WhatsApp -------------------------------------------------
// El envío es SIEMPRE una acción explícita del operador: abrir la pantalla
// nunca envía nada. Solo se manda `equipmentId`; el destinatario real lo
// resuelve el servidor, así que desde el navegador no se puede redirigir el
// mensaje a otro número.
const claimTarget = ref(null)
const claimPending = ref(false)
const claimBusy = ref(false)
const claimFeedback = ref(null)

const claimAvailable = computed(() => Boolean(data.value.claim?.enabled))
const claimReason = computed(() => data.value.claim?.reason ?? null)

const csrf = computed(() => props.data.csrf ?? null)

const canClaimRow = (item) => claimAvailable.value && Boolean(item.canClaim)

const openClaim = (item) => {
  if (claimBusy.value) return
  claimFeedback.value = null
  claimTarget.value = item
}

const closeClaim = () => {
  if (claimBusy.value) return
  claimTarget.value = null
}

const confirmLabel = (item) => {
  const name = item.driverName && item.driverName !== '(sin chofer)' ? item.driverName : 'el chofer'
  const label = item.equipmentCode || item.equipmentPlate || 'el equipo'
  return `Enviar recordatorio a ${name} por el equipo ${label}?`
}

const claimDetails = (item) => {
  const rows = []
  rows.push({ label: 'Equipo', value: item.equipmentCode + (item.equipmentPlate ? ' · ' + item.equipmentPlate : '') })
  rows.push({ label: 'Chofer', value: item.driverName })
  if (item.driverPhone) rows.push({ label: 'Teléfono', value: item.driverPhone })
  if (item.hasReading) {
    rows.push({ label: 'Último KM', value: kmLabel(item) })
    rows.push({ label: 'Última lectura', value: readingLabel(item) })
    rows.push({
      label: 'Días sin cargar',
      value: Number(item.daysSinceLastReading ?? 0) === 0
        ? 'Hoy'
        : `${item.daysSinceLastReading} días`,
    })
  } else {
    rows.push({ label: 'Última lectura', value: 'Nunca registrado' })
  }
  return rows
}

const sendClaim = async () => {
  const item = claimTarget.value
  if (!item || claimBusy.value) return

  claimBusy.value = true
  claimPending.value = true
  claimFeedback.value = null

  try {
    const body = new FormData()
    body.append('equipmentId', String(item.equipmentId))
    if (csrf.value?.name && csrf.value?.hash) {
      body.append(csrf.value.name, csrf.value.hash)
    }

    const response = await fetch(data.value.routes.claim, {
      method: 'POST',
      body,
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })

    let payload = null
    try {
      payload = await response.json()
    } catch {
      payload = null
    }

    if (!response.ok || !payload?.ok) {
      claimFeedback.value = {
        type: 'error',
        message: payload?.error ?? 'No se pudo enviar el reclamo por WhatsApp.',
      }
      return
    }

    claimPending.value = false
    claimFeedback.value = {
      type: 'success',
      message: payload.message ?? 'Reclamo enviado por WhatsApp.',
      detail: payload.pilotMode === 'piloto'
        ? 'Se envió solo al teléfono piloto configurado, no al chofer real.'
        : null,
    }
    claimTarget.value = null
  } catch (error) {
    claimFeedback.value = {
      type: 'error',
      message: 'No se pudo completar la solicitud. Revisá la conexión e intentá de nuevo.',
    }
  } finally {
    claimBusy.value = false
  }
}

const statusOptions = computed(() => data.value.catalogs.statusOptions ?? [])
const sortOptions = computed(() => data.value.catalogs.sortOptions ?? [])
const branches = computed(() => data.value.catalogs.branches ?? [])
const types = computed(() => data.value.catalogs.types ?? [])

// Antigüedad: el peor caso (nunca leído) se distingue de "hace muchos días"
// porque para el operador son dos problemas distintos.
const statusFor = (item) => {
  if (!item.hasReading) return 'SIN_LECTURA'
  const days = Number(item.daysSinceLastReading ?? 0)
  if (days === 0) return 'HOY'
  if (days > 7) return 'ANTIGUO'
  if (days > 3) return 'REVISAR'
  return 'AL_DIA'
}

const antiquityClass = (item) => {
  const status = statusFor(item)
  if (status === 'SIN_LECTURA') return 'bg-danger-subtle text-danger-strong'
  if (status === 'ANTIGUO') return 'bg-danger-subtle text-danger-strong'
  if (status === 'REVISAR') return 'bg-warning-subtle text-warning-foreground'
  return 'bg-success-subtle text-success-strong'
}

const antiquityLabel = (item) => {
  if (!item.hasReading) return 'Sin lectura'
  const days = Number(item.daysSinceLastReading ?? 0)
  if (days === 0) return 'Hoy'
  return `hace ${days} ${days === 1 ? 'día' : 'días'}`
}

const kmLabel = (item) => (item.lastKm === null || item.lastKm === undefined ? '—' : formatNumberEs(item.lastKm, 0))

const readingLabel = (item) => {
  if (!item.lastReadingAt) return 'Nunca registrado'
  return String(item.lastReadingAt).replace('T', ' ').slice(0, 16)
}
</script>

<template>
  <div>
    <PageHeading
      eyebrow="Lecturas"
      title="Control de lecturas de kilometraje"
      description="Consultá la última lectura registrada de cada equipo y detectá cuáles tienen los registros más antiguos. Podés enviarle un recordatorio por WhatsApp al chofer cuando corresponda."
    >
      <template #actions>
        <a v-if="data.routes.quickReadings" :href="data.routes.quickReadings" :class="secondaryButton">
          Registrar lectura
        </a>
      </template>
    </PageHeading>

    <section class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Resumen de lecturas">
      <div class="rounded-xl border border-border bg-surface-raised p-5">
        <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">Equipos</p>
        <p class="mt-2 text-3xl font-bold text-ink">{{ data.summary.total }}</p>
      </div>
      <div class="rounded-xl border border-danger/20 bg-danger-subtle p-5">
        <p class="text-xs font-semibold uppercase tracking-wide text-danger-strong">Sin lectura</p>
        <p class="mt-2 text-3xl font-bold text-danger-strong">{{ data.summary.sinLectura }}</p>
      </div>
      <div class="rounded-xl border border-warning/25 bg-warning-subtle p-5">
        <p class="text-xs font-semibold uppercase tracking-wide text-warning-foreground">Más de 7 días</p>
        <p class="mt-2 text-3xl font-bold text-warning-foreground">{{ data.summary.antiguos }}</p>
      </div>
      <div class="rounded-xl border border-border bg-surface-raised p-5">
        <p class="text-xs font-semibold uppercase tracking-wide text-ink-muted">En esta página</p>
        <p class="mt-2 text-3xl font-bold text-ink">{{ data.results.length }}</p>
      </div>
    </section>

    <PanelCard title="Buscar y filtrar" class="mb-6">
      <form method="get" :action="data.routes.index" class="grid gap-3 lg:grid-cols-[minmax(15rem,1fr)_11rem_11rem_13rem_13rem_auto] lg:items-end">
        <FormField label="Equipo, patente, chasis o chofer" for-id="reading-control-search">
          <div class="relative">
            <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-ink-muted" aria-hidden="true" />
            <input
              id="reading-control-search"
              name="q"
              :value="data.filters.q"
              placeholder="Ej.: BEN4G47 o_PEREZ"
              :class="`${fieldClass} pl-9`"
            />
          </div>
        </FormField>

        <FormField label="Sucursal" for-id="reading-control-branch">
          <select id="reading-control-branch" name="sucursal_id" :class="fieldClass">
            <option value="">Todas</option>
            <option
              v-for="branch in branches"
              :key="branch.id"
              :value="branch.id"
              :selected="String(data.filters.branchId ?? '') === String(branch.id)"
            >
              {{ branch.name }}
            </option>
          </select>
        </FormField>

        <FormField label="Tipo de equipo" for-id="reading-control-type">
          <select id="reading-control-type" name="tipo_id" :class="fieldClass">
            <option value="">Todos</option>
            <option
              v-for="type in types"
              :key="type.id"
              :value="type.id"
              :selected="String(data.filters.typeId ?? '') === String(type.id)"
            >
              {{ type.name }}
            </option>
          </select>
        </FormField>

        <FormField label="Antigüedad" for-id="reading-control-filter">
          <select id="reading-control-filter" name="filter" :class="fieldClass">
            <option
              v-for="option in statusOptions"
              :key="option.key"
              :value="option.key"
              :selected="data.filters.filter === option.key"
            >
              {{ option.label }}
            </option>
          </select>
        </FormField>

        <FormField label="Orden" for-id="reading-control-sort">
          <select id="reading-control-sort" name="sort" :class="fieldClass">
            <option
              v-for="option in sortOptions"
              :key="option.key"
              :value="option.key"
              :selected="(data.filters.sort ?? 'age_asc') === option.key"
            >
              {{ option.label }}
            </option>
          </select>
        </FormField>

        <button type="submit" :class="primaryButton">Aplicar</button>
      </form>
    </PanelCard>

    <PanelCard title="Lecturas por equipo" :count="data.results.length" flush>
      <EmptyState
        v-if="data.results.length === 0"
        title="No hay equipos para estos filtros"
        description="Probá cambiando la antigüedad, la sucursal, el tipo o la búsqueda."
      />

      <template v-else>
        <div class="hidden overflow-x-auto md:block">
          <table class="w-full min-w-[64rem] text-left text-sm">
            <thead class="bg-surface-subtle text-xs uppercase tracking-wide text-ink-muted">
              <tr>
                <th class="px-5 py-3">Equipo</th>
                <th class="px-5 py-3">Tipo</th>
                <th class="px-5 py-3">Sucursal</th>
                <th class="px-5 py-3">Chofer</th>
                <th class="px-5 py-3 text-right">Último KM</th>
                <th class="px-5 py-3">Última lectura</th>
                <th class="px-5 py-3">Antigüedad</th>
                <th class="px-5 py-3 text-right">Acción</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-border-subtle">
              <tr v-for="item in data.results" :key="item.equipmentId" class="hover:bg-brand-50/50">
                <td class="px-5 py-4">
                  <div class="font-semibold text-ink">{{ item.equipmentCode }}</div>
                  <div class="mt-1 text-xs text-ink-muted">{{ item.equipmentPlate || 'Sin patente' }}</div>
                </td>
                <td class="px-5 py-4 text-ink-muted">{{ item.typeName }}</td>
                <td class="px-5 py-4 text-ink-muted">{{ item.branchName || '—' }}</td>
                <td class="px-5 py-4">
                  <div class="font-medium text-ink">{{ item.driverName }}</div>
                  <div class="mt-1 text-xs text-ink-muted">{{ item.driverPhone || 'Sin teléfono' }}</div>
                </td>
                <td class="px-5 py-4 text-right font-medium text-ink">{{ kmLabel(item) }}</td>
                <td class="px-5 py-4 text-ink-muted">{{ readingLabel(item) }}</td>
                <td class="px-5 py-4">
                  <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold" :class="antiquityClass(item)">
                    {{ antiquityLabel(item) }}
                  </span>
                </td>
                <td class="px-5 py-4 text-right">
                  <button
                    v-if="canClaimRow(item)"
                    type="button"
                    class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-primary px-3.5 py-2 text-sm font-semibold text-primary hover:bg-brand-50 disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="claimBusy"
                    :aria-label="`Reclamar por WhatsApp al chofer de ${item.equipmentCode}`"
                    @click="openClaim(item)"
                  >
                    <ChatBubbleLeftEllipsisIcon class="size-4" aria-hidden="true" />
                    Reclamar por WhatsApp
                  </button>
                  <span v-else-if="claimAvailable" class="text-xs text-ink-muted">
                    {{ item.hasDriver ? (item.hasValidPhone ? '—' : 'Sin teléfono') : 'Sin chofer' }}
                  </span>
                  <span v-else class="text-xs text-ink-muted">No disponible</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="divide-y divide-border-subtle md:hidden">
          <article v-for="item in data.results" :key="item.equipmentId" class="space-y-3 p-4">
            <div class="flex items-start justify-between gap-3">
              <div>
                <p class="font-semibold text-ink">{{ item.equipmentCode }}</p>
                <p class="mt-1 text-sm text-ink-muted">{{ item.equipmentPlate || 'Sin patente' }} · {{ item.typeName }}</p>
              </div>
              <span class="inline-flex shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold" :class="antiquityClass(item)">
                {{ antiquityLabel(item) }}
              </span>
            </div>
            <p class="text-sm text-ink-muted">Sucursal: {{ item.branchName || '—' }}</p>
            <p class="text-sm text-ink-muted">
              Chofer: {{ item.driverName }}<template v-if="item.driverPhone"> · {{ item.driverPhone }}</template>
            </p>
            <div class="flex flex-wrap items-center gap-2 text-sm">
              <ExclamationTriangleIcon v-if="!item.hasReading" class="size-4 text-danger" aria-hidden="true" />
              <ClockIcon v-else class="size-4 text-ink-muted" aria-hidden="true" />
              <span class="font-medium text-ink">{{ kmLabel(item) }} km</span>
              <span class="text-ink-muted">· {{ readingLabel(item) }}</span>
            </div>
            <a v-if="item.equipmentUrl" :href="item.equipmentUrl" :class="secondaryButton" class="w-full justify-center">
              Ver equipo
              <ArrowTopRightOnSquareIcon class="ml-2 size-4" aria-hidden="true" />
            </a>
            <button
              v-if="canClaimRow(item)"
              type="button"
              class="inline-flex min-h-10 w-full items-center justify-center gap-2 rounded-lg border border-primary px-3.5 py-2 text-sm font-semibold text-primary hover:bg-brand-50 disabled:cursor-not-allowed disabled:opacity-60"
              :disabled="claimBusy"
              @click="openClaim(item)"
            >
              <ChatBubbleLeftEllipsisIcon class="size-4" aria-hidden="true" />
              Reclamar por WhatsApp
            </button>
            <p v-else-if="claimAvailable" class="text-center text-xs text-ink-muted">
              {{ item.hasDriver ? (item.hasValidPhone ? 'Acción no disponible' : 'Sin teléfono cargable') : 'Sin chofer asignado' }}
            </p>
          </article>
        </div>
      </template>

      <template v-if="data.pagination.totalPages > 1 || data.pagination.perPage" #footer>
        <PaginationBar :pagination="data.pagination" />
      </template>
    </PanelCard>

    <p class="mt-4 text-xs text-ink-subtle">
      <template v-if="claimAvailable">
        Consultar lecturas no modifica datos. El reclamo por WhatsApp solo se envía cuando confirmás cada equipo.
      </template>
      <template v-else>
        Pantalla de consulta: no envía mensajes ni modifica datos. El reclamo por WhatsApp no está disponible en este momento.
      </template>
    </p>

    <p
      v-if="claimReason && !claimAvailable"
      class="mt-2 text-xs text-ink-muted"
    >
      {{ claimReason }}
    </p>

    <div
      v-if="claimFeedback"
      class="mt-4 flex items-start gap-3 rounded-lg border p-4 text-sm"
      :class="claimFeedback.type === 'success'
        ? 'border-success/30 bg-success-subtle text-success-strong'
        : 'border-danger/30 bg-danger-subtle text-danger-strong'"
      role="status"
      aria-live="polite"
    >
      <div class="flex-1">
        <p class="font-semibold">{{ claimFeedback.message }}</p>
        <p v-if="claimFeedback.detail" class="mt-1 text-xs">{{ claimFeedback.detail }}</p>
      </div>
      <button
        type="button"
        class="text-xs font-semibold underline"
        aria-label="Cerrar aviso"
        @click="claimFeedback = null"
      >
        Cerrar
      </button>
    </div>

    <div
      v-if="claimTarget"
      class="fixed inset-0 z-50 flex items-end justify-center bg-ink/40 p-4 sm:items-center"
      role="dialog"
      aria-modal="true"
      aria-labelledby="reading-control-claim-title"
      @click.self="closeClaim"
    >
      <div class="w-full max-w-lg rounded-xl border border-border bg-surface-raised p-5 shadow-xl">
        <h2 id="reading-control-claim-title" class="text-lg font-semibold text-ink">
          {{ confirmLabel(claimTarget) }}
        </h2>
        <p class="mt-1 text-sm text-ink-muted">
          Se va a enviar un recordatorio por WhatsApp al chofer asignado con un enlace para cargar la lectura.
        </p>

        <dl class="mt-4 space-y-2 rounded-lg bg-surface-subtle p-4 text-sm">
          <div
            v-for="detail in claimDetails(claimTarget)"
            :key="detail.label"
            class="flex items-baseline justify-between gap-4"
          >
            <dt class="text-ink-muted">{{ detail.label }}</dt>
            <dd class="text-right font-medium text-ink">{{ detail.value }}</dd>
          </div>
        </dl>

        <p
          v-if="claimFeedback && claimFeedback.type === 'error'"
          class="mt-3 rounded-lg border border-danger/30 bg-danger-subtle p-3 text-sm text-danger-strong"
          role="alert"
        >
          {{ claimFeedback.message }}
        </p>

        <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <button type="button" :class="secondaryButton" :disabled="claimBusy" @click="closeClaim">
            Cancelar
          </button>
          <button
            type="button"
            :class="primaryButton"
            :disabled="claimBusy"
            @click="sendClaim"
          >
            <span v-if="claimPending" class="inline-flex items-center gap-2">
              <span class="size-4 animate-spin rounded-full border-2 border-current border-t-transparent" aria-hidden="true" />
              Enviando…
            </span>
            <span v-else>Enviar WhatsApp</span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
